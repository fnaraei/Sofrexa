<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Db, Flash, HttpError, I18n, Money, Request, Response, ValidationError, View};
use Sofrexa\Modules\Customers\Customers;

/** The till: C1/C8 open bills, C2/C3 payment, C6/C9 shift close, C7 rates, C10/C11 cash moves. */
final class CashierController
{
    // ------------------------------------------------------------ C1 / C8
    public function index(Request $req): void
    {
        $cards = array_map([Till::class, 'card'], Orders::open());
        $counts = ['all' => count($cards)];
        foreach ($cards as $c) {
            $counts[$c['channel']] = ($counts[$c['channel']] ?? 0) + 1;
        }
        $today = Till::today();
        View::page('cashier/index', [
            'title' => I18n::t('cash.title'),
            'nav' => 'cashier',
            'tab' => 'cashier',
            'cards' => $cards,
            'counts' => $counts,
            'openTotal' => array_sum(array_column($cards, 'amount')),
            'today' => $today,
            'shift' => Shifts::current(),
            'rates' => Rates::latest(),
            'scripts' => ['js/cashier.js'],
        ]);
    }

    // ------------------------------------------------------------ C2 / C3
    public function pay(Request $req): void
    {
        $o = Orders::get($req->param('id'));
        if (!in_array($o['status'], Orders::OPEN, true)) {
            Response::redirect('/cashier');
        }
        $persons = max(0, min(20, $req->int('persons')));
        $done = max(0, min($persons, $req->int('k')));
        $due = max(0, (int) $o['total'] - (int) $o['paid']);
        $share = $persons > 1 ? (int) ceil($due / max(1, $persons - $done)) : $due;
        View::page('cashier/pay', [
            'title' => Board::title($o),
            'nav' => 'cashier',
            'o' => $o,
            'due' => $due,
            'share' => min($share, $due),
            'persons' => $persons,
            'done' => $done,
            'rates' => Rates::detail(),
            'currencies' => Till::currencies(),
            'shift' => Shifts::current(),
            'discountText' => self::discountText($o),
            'vat' => Orders::vat($o['id']),
            'customer' => $o['customer_id'] ? Customers::get($o['customer_id']) : null,
            'scripts' => ['js/pay.js'],
        ]);
    }

    public function payPost(Request $req): void
    {
        $o = Orders::editable($req->param('id'));
        if (!Shifts::currentId()) {
            throw new \InvalidArgumentException(I18n::t('order.err_no_shift'));
        }
        $due = max(0, (int) $o['total'] - (int) $o['paid']);
        $share = $req->input('share') !== null ? min($due, max(0, $req->int('share'))) : $due;
        $method = in_array($req->str('method'), ['cash', 'card', 'account', 'mixed'], true) ? $req->str('method') : 'cash';
        $cur = strtoupper($req->str('currency', 'TRY'));
        $cashPart = static function () use ($req, $cur): array {
            return $cur === 'TRY'
                ? ['method' => 'cash', 'currency' => 'TRY', 'amount' => Money::parse($req->str('received'))]
                : ['method' => 'cash', 'currency' => $cur, 'amount_fx' => (float) str_replace(',', '.', str_replace('.', '', $req->str('received')))];
        };
        $customer = $req->str('customer_id') ?: null;
        $parts = match ($method) {
            'card' => [['method' => 'card', 'amount' => $req->str('card') !== '' ? Money::parse($req->str('card')) : $share]],
            'account' => [['method' => 'account', 'amount' => $share]],
            'mixed' => (static function () use ($cashPart, $share, $cur): array {
                $cash = $cashPart();
                $cashTry = $cur === 'TRY' ? (int) $cash['amount'] : Money::toTry((float) $cash['amount_fx'], (float) (Rates::latest()[$cur] ?? 0));
                return array_values(array_filter([$cash, $share - $cashTry > 0 ? ['method' => 'card', 'amount' => $share - $cashTry] : null]));
            })(),
            default => [$cashPart()],
        };
        if ($method === 'account' && !$customer) {
            throw new ValidationError(['customer' => I18n::t('pay.pick_customer')]);
        }
        $r = Orders::pay($o['id'], $parts, $customer, $req->bool('receipt'), $share);
        Notify::closeFor($o['id'], 'bill');
        if ($r['paid']) {
            Notify::closeFor($o['id']);
            Flash::set('success', $r['change'] > 0 ? I18n::t('pay.done', ['change' => money($r['change'])]) : I18n::t('pay.done_plain'));
            Response::json(['ok' => true, 'redirect' => '/cashier', 'change' => $r['change']]);
        }
        $persons = $req->int('persons');
        $msg = $r['change'] > 0 ? I18n::t('pay.done', ['change' => money($r['change'])]) : I18n::t('pay.partial', ['due' => money($r['due'])]);
        Flash::set('success', $msg);
        Response::json(['ok' => true, 'change' => $r['change'], 'redirect' => '/cashier/pay/' . $o['id'] . ($persons > 1 ? '?persons=' . $persons . '&k=' . ($req->int('k') + 1) : '')]);
    }

    /** Sheets of the payment screen: discount, receipt note, the bill (phone), more actions (phone). */
    public function paySheet(Request $req): void
    {
        $o = Orders::editable($req->param('id'));
        $kind = $req->param('kind');
        if (!in_array($kind, ['discount', 'note', 'bill', 'more'], true)) {
            throw new HttpError(404);
        }
        Response::json(['ok' => true, 'html' => View::partial('cashier/_sheet_' . $kind, [
            'o' => $o, 'discountText' => self::discountText($o), 'vat' => Orders::vat($o['id']),
        ])]);
    }

    public function discount(Request $req): void
    {
        $o = Orders::editable($req->param('id'));
        if ($req->bool('clear')) {
            Orders::clearDiscount($o['id']);
        } else {
            $kind = $req->str('kind') === 'amount' ? 'amount' : 'pct';
            $value = $kind === 'amount' ? (float) Money::parse($req->str('value')) : (float) str_replace(',', '.', $req->str('value'));
            if ((int) $o['discount'] > 0) {
                Orders::clearDiscount($o['id']);
            }
            Orders::discount($o['id'], $kind, $value, $req->str('reason'));
        }
        Response::json(['ok' => true, 'message' => I18n::t('disc.done'), 'reload' => true]);
    }

    public function note(Request $req): void
    {
        $o = Orders::editable($req->param('id'));
        Db::save('orders', ['id' => $o['id'], 'receipt_note' => mb_substr($req->str('note'), 0, 200) ?: null]);
        Response::json(['ok' => true, 'message' => I18n::t('ui.saved'), 'reload' => true]);
    }

    /** Customers with an account, for "Cari". */
    public function customers(Request $req): void
    {
        $rows = array_map(static fn(array $c): array => [
            'id' => $c['id'], 'name' => $c['name'], 'phone' => (string) $c['phone'],
            'balance' => I18n::t('pay.balance', ['amount' => money((int) $c['balance'])]),
        ], Customers::search($req->str('q'), 8, true));
        Response::json(['ok' => true, 'rows' => $rows]);
    }

    // ------------------------------------------------------------ C7 rates
    public function rates(Request $req): void
    {
        View::page('cashier/rates', [
            'title' => I18n::t('rates.title'),
            'nav' => 'cashier',
            'rates' => Rates::detail(),
            'backTo' => str_starts_with($req->str('back'), '/cashier') ? $req->str('back') : '/cashier',
        ]);
    }

    public function saveRates(Request $req): void
    {
        $n = 0;
        foreach (Rates::CURRENCIES as $c) {
            $v = str_replace(',', '.', $req->str('rate_' . $c));
            if ($v === '' || !is_numeric($v)) {
                continue;
            }
            $old = Rates::detail()[$c];
            // an unchanged rate is confirmed again (it counts as today's)
            if ($old['rate'] === null || abs((float) $v - $old['rate']) > 0.00001 || date('Y-m-d', intdiv((int) $old['at'], 1000)) !== date('Y-m-d')) {
                Rates::set($c, (float) $v);
                $n++;
            }
        }
        $back = str_starts_with($req->str('back'), '/cashier') ? $req->str('back') : '/cashier';
        Flash::set('success', I18n::t('rates.saved'));
        Response::json(['ok' => true, 'redirect' => $back]);
    }

    // ------------------------------------------------------------ shift: open, X, close (C6 / C9)
    public function openSheet(Request $req): void
    {
        Response::json(['ok' => true, 'html' => View::partial('cashier/_sheet_open', ['currencies' => Till::currencies()])]);
    }

    public function open(Request $req): void
    {
        $opening = [];
        foreach (Till::currencies() as $c) {
            $v = $req->str('open_' . $c);
            if ($v !== '') {
                $opening[$c] = $c === 'TRY' ? Money::parse($v) : (float) str_replace(',', '.', $v);
            }
        }
        Shifts::open($opening + ['TRY' => 0]);
        Response::json(['ok' => true, 'message' => I18n::t('shift.opened'), 'reload' => true]);
    }

    public function shift(Request $req): void
    {
        $s = Shifts::current();
        if (!$s) {
            Flash::set('error', I18n::t('shift.err_none'));
            Response::redirect('/cashier');
        }
        View::page('cashier/shift', [
            'title' => I18n::t('shift.close_title'),
            'nav' => 'cashier',
            's' => $s,
            'd' => Till::shiftClose($s),
            'currencies' => Till::currencies(),
            'scripts' => ['js/cashier.js'],
        ]);
    }

    public function xReport(Request $req): void
    {
        $id = Shifts::currentId();
        if (!$id) {
            throw new \InvalidArgumentException(I18n::t('shift.err_none'));
        }
        Tickets::xReport($id);
        Response::json(['ok' => true, 'message' => I18n::t('shift.x_done')]);
    }

    public function close(Request $req): void
    {
        $s = Shifts::current();
        if (!$s) {
            throw new \InvalidArgumentException(I18n::t('shift.err_none'));
        }
        $expected = Shifts::summary($s['id'])['cash'];
        $counted = [];
        $diff = false;
        foreach (Till::currencies() as $c) {
            $v = $req->str('count_' . $c);
            $counted[$c] = $c === 'TRY' ? Money::parse($v) : (float) str_replace(',', '.', $v === '' ? '0' : $v);
            $exp = $c === 'TRY' ? (int) ($expected['TRY'] ?? 0) : (float) ($expected[$c] ?? 0);
            if (abs($counted[$c] - $exp) > ($c === 'TRY' ? 0 : 0.001)) {
                $diff = true;
            }
        }
        if ($diff && $req->str('note') === '') {
            throw new ValidationError(['note' => I18n::t('shift.note_required')]);
        }
        $z = Shifts::close($counted, $req->str('note'));
        Flash::set('success', I18n::t('shift.closed', ['z' => str_pad((string) $z, 4, '0', STR_PAD_LEFT)]));
        Response::json(['ok' => true, 'redirect' => '/cashier']);
    }

    // ------------------------------------------------------------ C10 / C11 cash moves
    public function moves(Request $req): void
    {
        $s = Shifts::current();
        if (!$s) {
            Flash::set('error', I18n::t('shift.err_none'));
            Response::redirect('/cashier');
        }
        $sum = Shifts::summary($s['id']);
        $moves = Db::rows('SELECT m.*, u.name AS user_name FROM cash_moves m LEFT JOIN users u ON u.id = m.user_id WHERE m.shift_id = ? ORDER BY m.at DESC', [$s['id']]);
        $open = Db::row("SELECT m.at, u.name FROM cash_moves m LEFT JOIN users u ON u.id = m.user_id WHERE m.shift_id = ? AND m.kind = 'open' ORDER BY m.at LIMIT 1", [$s['id']]);
        $cashSales = Db::row("SELECT COALESCE(SUM(p.amount), 0) AS amount, COUNT(DISTINCT p.order_id) AS n FROM payments p WHERE p.shift_id = ? AND p.method = 'cash'", [$s['id']]);
        View::page('cashier/moves', [
            'title' => I18n::t('moves.title'),
            'nav' => 'cashier',
            's' => $s,
            'sum' => $sum,
            'moves' => $moves,
            'open' => $open,
            'cashSales' => $cashSales,
            'reversed' => array_flip(array_filter(array_column($moves, 'reverses'))),
            'rates' => Rates::detail(),
            'currencies' => Till::currencies(),
            'scripts' => ['js/cashier.js'],
        ]);
    }

    public function moveSheet(Request $req): void
    {
        Response::json(['ok' => true, 'html' => View::partial('cashier/_sheet_move', ['kind' => $req->str('kind') === 'in' ? 'in' : 'out', 'currencies' => Till::currencies()])]);
    }

    public function saveMove(Request $req): void
    {
        $kind = $req->str('kind') === 'in' ? 'in' : 'out';
        $reason = $req->str('reason');
        if ($reason === '') {
            throw new ValidationError(['reason' => I18n::t('moves.err_reason')]);
        }
        $cur = strtoupper($req->str('currency', 'TRY'));
        $amount = $cur === 'TRY' ? (float) Money::parse($req->str('amount')) : (float) str_replace(',', '.', $req->str('amount'));
        if ($amount <= 0) {
            throw new ValidationError(['amount' => I18n::t('order.err_amount')]);
        }
        $photo = Till::savePhoto($_FILES['photo'] ?? []);
        Shifts::move($kind, $cur, $amount, $reason, $req->str('note') ?: null, null, null, $photo);
        Tickets::drawer();
        Response::json(['ok' => true, 'message' => I18n::t('moves.saved'), 'reload' => true]);
    }

    public function noSale(Request $req): void
    {
        Shifts::noSale($req->str('reason'));
        Response::json(['ok' => true, 'message' => I18n::t('moves.nosale_done'), 'reload' => true]);
    }

    /** The receipt photo of a cash move (kept private, staff only). */
    public function photo(Request $req): void
    {
        $path = (string) Db::value('SELECT photo FROM cash_moves WHERE id = ?', [$req->param('id')]);
        $file = \Sofrexa\Core\App::storage('uploads/private') . '/' . $path;
        if ($path === '' || !is_file($file)) {
            throw new HttpError(404);
        }
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    public function reverse(Request $req): void
    {
        Shifts::reverse($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('moves.reversed'), 'reload' => true]);
    }

    // ------------------------------------------------------------ helpers

    /** "%10 · müdavim" from the discounts since the last reset. */
    public static function discountText(array $o): string
    {
        if ((int) $o['discount'] <= 0) {
            return '';
        }
        $active = [];
        foreach ($o['discounts'] as $d) {
            if ($d['kind'] === 'reverse') {
                $active = [];
                continue;
            }
            $active[] = $d;
        }
        return implode(' + ', array_map(static fn(array $d): string => ($d['kind'] === 'pct' ? '%' . digits(I18n::num((float) $d['value'])) : money((int) $d['amount']))
            . ($d['reason'] ? ' · ' . mb_strtolower((string) $d['reason'], 'UTF-8') : ''), $active));
    }
}
