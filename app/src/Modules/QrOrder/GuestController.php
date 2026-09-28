<?php
declare(strict_types=1);

namespace Sofrexa\Modules\QrOrder;

use Sofrexa\Core\{Db, I18n, Request, Response, Settings, View};

/**
 * Guest pages of the QR table card (no sign-in): Q1 menu and Q2 cart (one page), Q3 order status, Q4 ordering paused.
 * The phone remembers its own orders in the PHP session, per table, until the table's session closes.
 */
final class GuestController
{
    private const LAYOUT = 'layouts/guest';

    /** Q1 (with Q2 inside), or Q4 when the web copy has lost the till. ?menu=1 opens the menu read-only from Q4. */
    public function menu(Request $req): void
    {
        $t = QrOrders::table($req->param('code'));
        $avail = QrOrders::availability();
        if ($t && $avail === 'offline' && !$req->str('menu')) {
            View::page('qr/blocked', ['table' => $t, 'code' => $req->param('code')], self::LAYOUT);
        }
        $mine = $t ? $this->mine($t) : [];
        View::page('qr/menu', [
            'table' => $t,
            'code' => $req->param('code'),
            'menu' => QrOrders::menu(),
            'readonly' => !$t || $avail !== 'open',
            'avail' => $avail,
            'approval' => $t ? QrOrders::needsApproval(QrOrders::session($t['id'])) : false,
            'mine' => $mine,
        ], self::LAYOUT);
    }

    /** Places the order of Q2. Body: {lines: [{item, qty, mods, note}], note}. */
    public function order(Request $req): void
    {
        $t = $this->tableOrFail($req);
        try {
            [$orderId, $sessionId] = QrOrders::submit($t, $req->arr('lines'), $req->str('note'));
        } catch (\InvalidArgumentException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage(), 'closed' => QrOrders::availability() !== 'open'], 422);
        }
        $dev = $_SESSION['qr'][$t['id']] ?? null;
        if (!$dev || $dev['s'] !== $sessionId) {
            $dev = ['s' => $sessionId, 'o' => []];
        }
        $dev['o'][] = $orderId;
        $_SESSION['qr'][$t['id']] = $dev;
        Response::json(['ok' => true, 'redirect' => '/q/' . rawurlencode($req->param('code')) . '/status']);
    }

    /** Q3. ?partial=1: only the live part, for the page's polling. */
    public function status(Request $req): void
    {
        $t = $this->tableOrFail($req);
        $st = QrOrders::status($t, $this->mine($t));
        $code = $req->param('code');
        if (!$st) {
            if ($req->str('partial')) {
                Response::json(['ok' => true, 'redirect' => '/q/' . rawurlencode($code)]);
            }
            Response::redirect('/q/' . rawurlencode($code));
        }
        $data = ['table' => $t, 'code' => $code, 'st' => $st, 'avail' => QrOrders::availability()];
        if ($req->str('partial')) {
            Response::json(['ok' => true, 'html' => View::partial('qr/_status', $data)]);
        }
        View::page('qr/status', $data, self::LAYOUT);
    }

    public function call(Request $req): void
    {
        $t = $this->tableOrFail($req);
        if (!Settings::get('qr.call_waiter', true)) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $ok = QrOrders::callWaiter($t);
        $msg = QrOrders::availability() === 'offline' ? 'qr.called_offline' : ($ok ? 'qr.called' : 'qr.called_again');
        Response::json(['ok' => true, 'message' => I18n::t($msg)]);
    }

    public function bill(Request $req): void
    {
        $t = $this->tableOrFail($req);
        if (!Settings::get('qr.request_bill', true)) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        if (!$this->mine($t)) {
            Response::json(['ok' => false, 'error' => I18n::t('qr.err_no_order')], 422);
        }
        $ok = QrOrders::requestBill($t);
        Response::json(['ok' => true, 'message' => I18n::t($ok ? 'qr.bill_asked' : 'qr.called_again')]);
    }

    /** This phone's orders at the table, while the table's session is still the one they were placed in. */
    private function mine(array $t): array
    {
        $dev = $_SESSION['qr'][$t['id']] ?? null;
        if (!$dev) {
            return [];
        }
        $open = (bool) Db::value('SELECT 1 FROM qr_sessions WHERE id = ? AND closed_at IS NULL AND deleted = 0', [$dev['s']]);
        if (!$open) {
            unset($_SESSION['qr'][$t['id']]);
            return [];
        }
        return (array) $dev['o'];
    }

    private function tableOrFail(Request $req): array
    {
        $t = QrOrders::table($req->param('code'));
        if (!$t) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        return $t;
    }

    /** Languages offered on the guest pages, as [code => native name]. */
    public static function languages(): array
    {
        $names = ['tr' => 'Türkçe', 'en' => 'English', 'fa' => 'فارسی', 'ru' => 'Русский'];
        $out = [];
        foreach ((array) Settings::get('lang.customer', array_keys($names)) as $l) {
            if (isset($names[$l])) {
                $out[$l] = $names[$l];
            }
        }
        return $out ?: $names;
    }
}
