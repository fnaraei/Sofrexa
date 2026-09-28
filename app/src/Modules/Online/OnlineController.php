<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Online;

use Sofrexa\Core\{Db, HttpError, I18n, Request, Response, Turnstile, View};
use Sofrexa\Modules\Orders\Orders;

/**
 * Public online ordering pages (no staff sign-in): menu and cart (O5, mobile like Q1), sign-in and sign-up (O1, O7, O9),
 * e-mail code (O2), new password (O8), checkout (O3, O6) and tracking (O4).
 * Session keys: online (signed-in account), online_pending (account waiting for its code), online_cart, online_reset.
 */
final class OnlineController
{
    private const LAYOUT = 'layouts/guest';

    public function menu(Request $req): void
    {
        View::page('online/menu', [
            'menu' => OnlineOrders::menu(),
            'acc' => Accounts::current(),
            'avail' => OnlineOrders::availability(),
        ], self::LAYOUT);
    }

    /** "Devam et": the cart from the phone is kept on the server; checkout next, or sign-in first. */
    public function cart(Request $req): void
    {
        $lines = array_values(array_filter($req->arr('lines'), 'is_array'));
        $p = OnlineOrders::priced($lines);
        if (!$p['lines']) {
            throw new \InvalidArgumentException(I18n::t('on.err_empty'));
        }
        $_SESSION['online_cart'] = ['lines' => array_map(static fn(array $l): array => ['item' => $l['item'], 'qty' => $l['qty'], 'mods' => $l['mods'], 'note' => $l['note']], $p['lines']),
            'type' => $req->str('type') === 'pickup' ? 'pickup' : 'delivery'];
        $acc = Accounts::current();
        Response::json(['ok' => true, 'redirect' => $acc && $acc['verified_at'] ? '/online/odeme' : '/online/giris?next=odeme']);
    }

    // ------------------------------------------------------------ accounts

    public function login(Request $req): void
    {
        if (Accounts::current()) {
            Response::redirect($this->next($req));
        }
        View::page('online/login', ['next' => $req->str('next'), 'turnstile' => Turnstile::enabled() ? Turnstile::siteKey() : ''], self::LAYOUT);
    }

    public function doLogin(Request $req): void
    {
        $this->human($req);
        $a = Accounts::login($req->str('email'), (string) $req->input('password', ''), $req->bool('remember'));
        if (!$a['verified_at']) {
            $_SESSION['online_pending'] = $a['id'];
            try {
                Accounts::sendCode($a, 'verify');
            } catch (\InvalidArgumentException) {
                // a code went out less than a minute ago: the customer uses that one
            }
            Response::json(['ok' => true, 'redirect' => '/online/dogrula']);
        }
        Response::json(['ok' => true, 'redirect' => $this->next($req)]);
    }

    public function register(Request $req): void
    {
        View::page('online/register', ['next' => $req->str('next'), 'turnstile' => Turnstile::enabled() ? Turnstile::siteKey() : '',
            'old' => $_SESSION['online_form'] ?? []], self::LAYOUT);
    }

    public function doRegister(Request $req): void
    {
        $this->human($req);
        $in = ['name' => $req->str('name'), 'email' => $req->str('email'), 'phone' => $req->str('phone'), 'password' => (string) $req->input('password', ''),
            'terms' => $req->bool('terms'), 'marketing' => $req->bool('marketing')];
        $_SESSION['online_form'] = array_diff_key($in, ['password' => 1]);
        $a = Accounts::register($in);
        $_SESSION['online_pending'] = $a['id'];
        Response::json(['ok' => true, 'redirect' => '/online/dogrula' . ($req->str('next') !== '' ? '?next=' . rawurlencode($req->str('next')) : '')]);
    }

    public function verify(Request $req): void
    {
        $a = $this->pending();
        View::page('online/verify', ['a' => $a, 'wait' => Accounts::resendIn($a), 'next' => $req->str('next')], self::LAYOUT);
    }

    public function doVerify(Request $req): void
    {
        $a = $this->pending();
        Accounts::verify($a['id'], $req->str('code'));
        unset($_SESSION['online_pending'], $_SESSION['online_form']);
        Response::json(['ok' => true, 'redirect' => $this->next($req)]);
    }

    /** "Tekrar gönder" on O2 and O8. */
    public function resend(Request $req): void
    {
        if ($req->str('purpose') === 'reset') {
            $email = (string) ($_SESSION['online_reset'] ?? '');
            $a = $email !== '' ? Accounts::byEmail($email) : null;
            if ($a) {
                Accounts::sendCode($a, 'reset');
            }
        } else {
            Accounts::sendCode($this->pending(), 'verify');
        }
        Response::json(['ok' => true, 'message' => I18n::t('on.code_again'), 'wait' => 60]);
    }

    public function forgot(Request $req): void
    {
        $email = (string) ($_SESSION['online_reset'] ?? '');
        $a = $email !== '' ? Accounts::byEmail($email) : null;
        View::page('online/forgot', ['email' => $email !== '' ? $email : $req->str('email'), 'sent' => $email !== '' && $req->str('step') !== 'email',
            'wait' => $a ? Accounts::resendIn($a) : 0], self::LAYOUT);
    }

    public function doForgot(Request $req): void
    {
        $this->human($req);
        Accounts::startReset($req->str('email'));
        $_SESSION['online_reset'] = Accounts::email($req->str('email'));
        Response::json(['ok' => true, 'redirect' => '/online/sifre']);
    }

    public function doReset(Request $req): void
    {
        $email = (string) ($_SESSION['online_reset'] ?? '');
        if ($email === '') {
            Response::json(['ok' => true, 'redirect' => '/online/sifre?step=email']);
        }
        Accounts::reset($email, $req->str('code'), (string) $req->input('password', ''), (string) $req->input('password2', ''));
        unset($_SESSION['online_reset']);
        Response::json(['ok' => true, 'redirect' => $this->next($req)]);
    }

    public function logout(Request $req): void
    {
        Accounts::logout();
        Response::json(['ok' => true, 'redirect' => '/online']);
    }

    /** The account menu (not designed): the customer's recent orders and "Çıkış yap". */
    public function account(Request $req): void
    {
        $a = Accounts::current() ?? throw new HttpError(404);
        Response::json(['ok' => true, 'html' => View::partial('online/_sheet_account', ['a' => $a, 'orders' => OnlineOrders::recent($a['customer_id'])])]);
    }

    // ------------------------------------------------------------ checkout and tracking

    public function checkout(Request $req): void
    {
        $a = $this->customer('/online/odeme');
        $cart = $_SESSION['online_cart'] ?? null;
        $p = OnlineOrders::priced((array) ($cart['lines'] ?? []));
        if (!$p['lines']) {
            Response::redirect('/online');
        }
        View::page('online/checkout', [
            'a' => $a,
            'p' => $p,
            'type' => in_array($cart['type'] ?? '', OnlineOrders::types(), true) ? $cart['type'] : (OnlineOrders::types()[0] ?? 'delivery'),
            'addresses' => Accounts::addresses($a['customer_id']),
            'slots' => OnlineOrders::slots(),
            'avail' => OnlineOrders::availability(),
        ], self::LAYOUT);
    }

    public function address(Request $req): void
    {
        $a = $this->customer('/online/odeme');
        $id = Accounts::addAddress($a['customer_id'], $req->str('label'), $req->str('street'), $req->str('district'), $req->str('note'));
        Response::json(['ok' => true, 'id' => $id, 'reload' => true]);
    }

    public function place(Request $req): void
    {
        $a = $this->customer('/online/odeme');
        $cart = $_SESSION['online_cart'] ?? ['lines' => []];
        $when = $req->str('when') === 'slot' ? $req->str('slot') : ($req->str('when') ?: 'asap');
        $id = OnlineOrders::place($a, (array) $cart['lines'], [
            'type' => $req->str('type'), 'address_id' => $req->str('address_id'), 'when' => $when, 'phone' => $req->str('phone'),
            'pay' => $req->str('pay'), 'cash_given' => $req->str('cash_given'), 'note' => $req->str('note'),
        ]);
        unset($_SESSION['online_cart']);
        Response::json(['ok' => true, 'message' => I18n::t('on.placed'), 'redirect' => '/online/siparis/' . $id, 'clear_cart' => true]);
    }

    /** O4, and ?partial=1 for the page's own refresh. */
    public function track(Request $req): void
    {
        $a = $this->customer('/online/siparis/' . $req->param('id'));
        $o = Db::row("SELECT id FROM orders WHERE id = ? AND customer_id = ? AND channel = 'online' AND deleted = 0", [$req->param('id'), $a['customer_id']]);
        if (!$o) {
            throw new HttpError(404);
        }
        $o = Orders::get($o['id']);
        $data = ['o' => $o, 't' => OnlineOrders::track($o), 'a' => $a];
        if ($req->str('partial')) {
            Response::json(['ok' => true, 'html' => View::partial('online/_track', $data)]);
        }
        View::page('online/track', $data, self::LAYOUT);
    }

    // ------------------------------------------------------------ helpers

    /** A signed-in, verified customer, or the sign-in page (coming back here afterwards). */
    private function customer(string $back): array
    {
        $a = Accounts::current();
        if ($a && $a['verified_at']) {
            return $a;
        }
        $_SESSION['online_back'] = $back;
        if ((new Request())->wantsJson()) {
            Response::json(['ok' => true, 'redirect' => '/online/giris']);
        }
        Response::redirect('/online/giris');
    }

    private function pending(): array
    {
        $id = $_SESSION['online_pending'] ?? null;
        $a = is_string($id) ? Accounts::get($id) : null;
        if (!$a) {
            Response::redirect('/online/giris');
        }
        return $a;
    }

    /** Where to go after signing in: checkout when a cart is waiting, the page that asked, or the menu. */
    private function next(Request $req): string
    {
        $back = (string) ($_SESSION['online_back'] ?? '');
        unset($_SESSION['online_back']);
        if ($back !== '' && str_starts_with($back, '/online')) {
            return $back;
        }
        if ($req->str('next') === 'odeme' || !empty($_SESSION['online_cart'])) {
            return '/online/odeme';
        }
        return '/online';
    }

    private function human(Request $req): void
    {
        if (!Turnstile::verify($req->str('cf-turnstile-response'), $req->ip())) {
            throw new \InvalidArgumentException(I18n::t('login.captcha'));
        }
    }
}
