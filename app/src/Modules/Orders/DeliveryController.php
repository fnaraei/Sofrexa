<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Db, Flash, HttpError, I18n, Request, Response, Settings, View};
use Sofrexa\Modules\Customers\Customers;
use Sofrexa\Modules\Menu\Menu;

/** C4 phone order (delivery or pickup) and C5 delivery board with couriers. */
final class DeliveryController
{
    // ------------------------------------------------------------ C5
    public function index(Request $req): void
    {
        View::page('delivery/index', [
            'title' => I18n::t('deliv.title'),
            'nav' => 'delivery',
            'tab' => 'delivery',
            'cols' => Delivery::board(),
            'couriers' => Delivery::couriers(),
            'stats' => Delivery::todayStats(),
            'scripts' => ['js/delivery.js'],
        ]);
    }

    /** Actions for one order on the board (approve, ready, courier, out, delivered, pay, slip, cancel). */
    public function sheet(Request $req): void
    {
        $o = Orders::editable($req->param('id'));
        $o['stage'] = Delivery::stage($o + ['line_count' => count(array_filter($o['lines'], static fn(array $l): bool => $l['status'] !== 'void')),
            'ready' => count(array_filter($o['lines'], static fn(array $l): bool => $l['status'] === 'ready'))]);
        Response::json(['ok' => true, 'html' => View::partial('delivery/_sheet_order', ['o' => $o, 'couriers' => Delivery::couriers()])]);
    }

    public function move(Request $req): void
    {
        $stage = $req->param('stage');
        if ($stage === 'reject') {
            Orders::void($req->param('id'), $req->str('reason') ?: 'reddedildi');
        } else {
            Delivery::move($req->param('id'), $stage);
        }
        Response::json(['ok' => true, 'message' => I18n::t($stage === 'done' ? 'deliv.delivered' : 'deliv.moved'), 'reload' => true]);
    }

    public function courier(Request $req): void
    {
        Delivery::assign($req->param('id'), $req->str('courier_id') ?: null);
        Response::json(['ok' => true, 'message' => I18n::t('deliv.moved'), 'reload' => true]);
    }

    public function slip(Request $req): void
    {
        Orders::editable($req->param('id'));
        Tickets::courier($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('order.prebill_done')]);
    }

    public function settleSheet(Request $req): void
    {
        $c = null;
        foreach (Delivery::couriers() as $x) {
            if ($x['id'] === $req->param('id')) {
                $c = $x;
            }
        }
        if (!$c) {
            throw new HttpError(404);
        }
        Response::json(['ok' => true, 'html' => View::partial('delivery/_sheet_settle', ['c' => $c])]);
    }

    public function settle(Request $req): void
    {
        $name = (string) Db::value('SELECT name FROM users WHERE id = ?', [$req->param('id')]);
        $n = Delivery::settle($req->param('id'));
        Response::json(['ok' => true, 'message' => $n ? I18n::t('deliv.settled', ['name' => first_name($name)]) : I18n::t('deliv.nothing'), 'reload' => true]);
    }

    // ------------------------------------------------------------ C4
    public function create(Request $req): void
    {
        $items = array_values(array_filter(Menu::items(), static fn(array $i): bool => (bool) $i['active']));
        $cats = array_values(array_filter(Menu::categories(), static fn(array $c): bool => (bool) $c['active'] && (int) $c['item_count'] > 0));
        $groups = Db::pairs('SELECT g.item_id, MAX(m.min_sel) FROM item_modifier_groups g JOIN modifier_groups m ON m.id = g.group_id WHERE g.deleted = 0 AND m.deleted = 0 GROUP BY g.item_id');
        $day = Orders::businessDay();
        View::page('delivery/new', [
            'title' => I18n::t('deliv.new_delivery'),
            'nav' => 'delivery',
            'type' => $req->str('type') === 'pickup' ? 'pickup' : 'delivery',
            'items' => $items,
            'cats' => $cats,
            'popular' => OrderController::popular($items),
            'groups' => $groups,
            'couriers' => Delivery::couriers(),
            'nextNo' => (int) Db::value('SELECT COALESCE(MAX(no), 0) + 1 FROM orders WHERE day = ? AND no < 5000', [$day]),
            'fee' => (int) Settings::get('online.delivery_fee', 0),
            'eta' => (string) Settings::get('online.eta_minutes', '35–45'),
            'scripts' => ['js/delivery.js'],
        ]);
    }

    /** Phone lookup while typing (C4 match card). */
    public function customer(Request $req): void
    {
        $c = Customers::byPhone($req->str('phone'));
        if (!$c) {
            Response::json(['ok' => true, 'customer' => null]);
        }
        $st = Customers::stats($c['id']);
        $days = $st['last'] ? (int) floor((time() - intdiv($st['last'], 1000)) / 86400) : null;
        Response::json(['ok' => true, 'customer' => [
            'id' => $c['id'], 'name' => $c['name'], 'initials' => initials($c['name']),
            'line' => I18n::t('deliv.match', ['n' => digits($st['orders']), 'when' => $days === null ? '—' : ($days === 0 ? mb_strtolower(I18n::t('ui.today'), 'UTF-8') : I18n::t('deliv.days_ago', ['n' => digits($days)])), 'bal' => money($st['balance'])]),
            'addresses' => array_map(static fn(array $a): array => ['id' => $a['id'], 'label' => $a['label'] ?: I18n::t('deliv.addr_home'), 'address' => $a['address']], Customers::addresses($c['id'])),
        ]]);
    }

    public function store(Request $req): void
    {
        $id = Delivery::create($req->all());
        $o = Orders::get($id);
        Flash::set('success', I18n::t('deliv.created', ['no' => digits(sprintf('%04d', (int) $o['no']))]));
        Response::json(['ok' => true, 'redirect' => '/delivery']);
    }
}
