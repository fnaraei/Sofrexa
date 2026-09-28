<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Db, HttpError, I18n, Request, Response, View};

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

    /** $act: done ("Aldım", "Gidiyorum"), later ("Sonra"), prebill ("Ön hesap yazdır"). */
    public function act(Request $req): void
    {
        $n = Db::row('SELECT * FROM notifications WHERE id = ? AND deleted = 0', [$req->param('id')]);
        if (!$n) {
            throw new HttpError(404);
        }
        switch ($req->param('act')) {
            case 'later':
                Notify::later($n['id']);
                break;
            case 'prebill':
                if ($n['ref_type'] === 'order' && $n['ref_id']) {
                    Orders::preBill($n['ref_id']);
                }
                Notify::done($n['id']);
                break;
            default:
                // "Aldım" on a ready alert: the plates left the kitchen
                if ($n['kind'] === 'ready' && $n['ref_type'] === 'order' && $n['ref_id']) {
                    \Sofrexa\Modules\Kitchen\Kitchen::served($n['ref_id'], (array) (json_arr($n['body'])['lines'] ?? []));
                }
                Notify::done($n['id']);
        }
        Response::json(['ok' => true, 'reload' => true]);
    }
}
