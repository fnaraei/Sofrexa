<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Db, HttpError, I18n, Request, Response, View};

/** Cancelled dishes of the kitchen — IP1/IP2 list, IP3 "Masaya ver" and IP4 "Personele yaz" sheets, and the till's answers. */
final class VoidsController
{
    public function index(Request $req): void
    {
        $filter = in_array($req->str('f'), Voids::FILTERS, true) ? $req->str('f') : 'today';
        View::page('cashier/voids', [
            'title' => I18n::t('voids.title'),
            'nav' => 'cashier',
            'back' => '/cashier',
            'filter' => $filter,
            'rows' => Voids::list($filter),
            'stats' => Voids::stats(),
            'scripts' => ['js/voids.js'],
        ]);
    }

    /** IP3 / IP4. */
    public function sheet(Request $req): void
    {
        $v = $this->voided($req->param('id'));
        $kind = $req->param('kind');
        $data = ['v' => $v, 'amount' => (int) round((float) $v['qty'] * ((int) $v['unit_price'] + (int) $v['mods_price']))];
        $view = match ($kind) {
            'table' => 'cashier/_sheet_void_table',
            'staff' => 'cashier/_sheet_void_staff',
            default => throw new HttpError(404),
        };
        $data += $kind === 'table' ? ['bills' => Voids::openBills($v['order_id'])] : ['staff' => Voids::staff()];
        Response::json(['ok' => true, 'html' => View::partial($view, $data)]);
    }

    /** "Hazırlanmadı · stoka dön" / "Hazırlandı · zayi". */
    public function settle(Request $req): void
    {
        $how = $req->str('how') === 'returned' ? 'returned' : 'waste';
        Orders::settleVoid($req->param('id'), $how);
        Response::json(['ok' => true, 'reload' => true, 'message' => I18n::t($how === 'returned' ? 'void.returned' : 'void.wasted')]);
    }

    public function reuse(Request $req): void
    {
        $o = Orders::get($req->str('order_id'));
        Orders::reuseVoided($req->param('id'), $o['id']);
        Response::json(['ok' => true, 'reload' => true, 'message' => I18n::t('voids.given', ['where' => Orders::where($o)])]);
    }

    public function staff(Request $req): void
    {
        $name = (string) Db::value('SELECT name FROM users WHERE id = ? AND deleted = 0', [$req->str('user_id')]);
        Orders::chargeVoidedToStaff($req->param('id'), $req->str('user_id'));
        Response::json(['ok' => true, 'reload' => true, 'message' => I18n::t('voids.charged', ['name' => first_name($name)])]);
    }

    private function voided(string $id): array
    {
        $v = Db::row("SELECT * FROM order_items WHERE id = ? AND status = 'void' AND deleted = 0 AND void_stock IN ('pending', 'waste')", [$id]);
        if (!$v) {
            throw new HttpError(404);
        }
        return $v;
    }
}
