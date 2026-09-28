<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Db, HttpError, I18n, Request, Response, Router, View};

/** W6 notifications: food ready, QR order to approve, bill requested, waiter called. */
final class NotifyController
{
    public function index(Request $req): void
    {
        View::page('orders/notifications', [
            'title' => I18n::t('notif.title'),
            'nav' => 'tables',
            'tab' => 'notifications',
            'rows' => Notify::forMe(),
            'unread' => Notify::unread(),
        ]);
    }

    public function readAll(Request $req): void
    {
        Notify::markAllRead();
        Response::json(['ok' => true, 'reload' => true]);
    }

    /**
     * $act: done ("Aldım", "Gidiyorum"), later ("Sonra"), prebill ("Ön hesap yazdır"), retry (a stuck printer),
     * returned / waste (a cancelled dish: "Hazırlanmadı · stoka dön" / "Hazırlandı · zayi").
     * This one route reaches till work, so every action that is till work asks for its permission and for the till
     * (Router::tillOnly) here, exactly as its own route would.
     */
    public function act(Request $req): void
    {
        $n = Db::row('SELECT * FROM notifications WHERE id = ? AND deleted = 0', [$req->param('id')]);
        if (!$n || !Notify::isMine($n)) {
            throw new HttpError(404);
        }
        $act = $req->param('act');
        switch ($act) {
            case 'later':
                Notify::later($n['id']);
                break;
            case 'prebill':
                self::till('bill.print');
                if ($n['ref_type'] === 'order' && $n['ref_id']) {
                    Orders::preBill($n['ref_id']);
                }
                Notify::done($n['id']);
                break;
            case 'retry':
                if ($n['kind'] !== 'printer') {
                    throw new HttpError(404);
                }
                self::till('bill.print');
                \Sofrexa\Print\Spooler::retryNow((string) $n['ref_id']);
                Notify::later($n['id']);
                Response::json(['ok' => true, 'message' => I18n::t('notif.printer_retry')]);
            case 'returned':
            case 'waste':
                if ($n['kind'] !== 'void' || $n['ref_type'] !== 'order_item') {
                    throw new HttpError(404);
                }
                self::till('cash.pay');
                Orders::settleVoid((string) $n['ref_id'], $act);
                Notify::done($n['id']);
                Response::json(['ok' => true, 'reload' => true, 'message' => I18n::t($act === 'returned' ? 'void.returned' : 'void.wasted')]);
            default:
                if ($n['kind'] === 'ready' && $n['ref_type'] === 'order' && $n['ref_id']) {
                    // "Aldım": the plates on the screen left the kitchen — only those; one that arrived since keeps ringing
                    self::till('orders.take');
                    $seen = $req->input('lines');
                    $took = Notify::collect($n, is_array($seen) ? $seen : null);
                    if ($took) {
                        \Sofrexa\Modules\Kitchen\Kitchen::served($n['ref_id'], $took);
                    }
                    break;
                }
                Notify::done($n['id']);
        }
        Response::json(['ok' => true, 'reload' => true]);
    }

    /** The permission an action's own route asks for, and the till rule of that route. */
    private static function till(string $perm): void
    {
        if (!Auth::can($perm) && !(($perm === 'cash.pay') && Auth::can('orders.void'))) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        Router::tillOnly();
    }
}
