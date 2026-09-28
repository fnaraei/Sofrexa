<?php
declare(strict_types=1);

namespace Sofrexa\Modules\QrOrder;

use Sofrexa\Core\{Auth, Db, I18n, Request, Response, View};
use Sofrexa\Modules\Menu\Floor;

/** W5: a waiter approves or rejects a table's first QR order. */
final class QrController
{
    /** The approval sheet: every guest order of the table's session that waits, as one list. */
    public function sheet(Request $req): void
    {
        $o = Db::row('SELECT * FROM orders WHERE id = ? AND deleted = 0', [$req->param('id')]);
        if (!$o || $o['channel'] !== 'qr' || $o['status'] !== 'pending') {
            Response::json(['ok' => true, 'redirect' => $o && $o['table_id'] ? '/tables?t=' . $o['table_id'] : '/tables']);
        }
        $orders = $this->group($o);
        $ids = array_column($orders, 'id');
        $lines = Db::rows("SELECT * FROM order_items WHERE order_id IN (" . Db::in($ids) . ") AND deleted = 0 AND status <> 'void' ORDER BY created_at, rowid", $ids);
        $session = $o['qr_session_id'] ? Db::row('SELECT * FROM qr_sessions WHERE id = ?', [$o['qr_session_id']]) : null;
        $main = QrOrders::mainOrder((string) $o['table_id']);
        Response::json(['ok' => true, 'html' => View::partial('qr/_sheet_approve', [
            'o' => $o,
            'orders' => $orders,
            'lines' => $lines,
            'table' => Floor::table((string) $o['table_id']),
            'first' => !($session && $session['approved_at']),
            'guests' => (int) ($main['guests'] ?? 0),
            'total' => array_sum(array_column($orders, 'total')),
        ])]);
    }

    public function approve(Request $req): void
    {
        $o = $this->pendingOrFail($req->param('id'));
        foreach ($this->group($o) as $x) {
            QrOrders::accept($x['id'], Auth::user()['id']);
        }
        Response::json(['ok' => true, 'message' => I18n::t('qr.approved'), 'reload' => true]);
    }

    public function reject(Request $req): void
    {
        $o = $this->pendingOrFail($req->param('id'));
        foreach ($this->group($o) as $x) {
            QrOrders::reject($x['id']);
        }
        Response::json(['ok' => true, 'message' => I18n::t('qr.rejected'), 'reload' => true]);
    }

    /** The waiting guest orders of the same table session, oldest first. */
    private function group(array $o): array
    {
        return array_values(array_filter(QrOrders::pending((string) $o['table_id']), static fn(array $x): bool => $x['qr_session_id'] === $o['qr_session_id']));
    }

    private function pendingOrFail(string $id): array
    {
        $o = Db::row('SELECT * FROM orders WHERE id = ? AND deleted = 0', [$id]);
        if (!$o || $o['channel'] !== 'qr') {
            throw new \Sofrexa\Core\HttpError(404);
        }
        if ($o['status'] !== 'pending') {
            throw new \InvalidArgumentException(I18n::t('qr.err_handled'));
        }
        return $o;
    }
}
