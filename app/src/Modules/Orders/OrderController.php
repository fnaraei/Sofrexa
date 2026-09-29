<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Clock, Db, I18n, Request, Response, View};
use Sofrexa\Modules\Menu\Menu;

/** Order taking: W2 (phone menu), W10 (desktop menu + order panel), W3 (phone order summary), W4 (options sheet). */
final class OrderController
{
    public function show(Request $req): void
    {
        $o = Orders::get($req->param('id'));
        if (!in_array($o['status'], Orders::OPEN, true)) {
            Response::redirect(Auth::can('cash.pay') ? '/cashier' : '/tables');
        }
        $this->page($o, ['channel' => $o['channel']]);
    }

    /** New takeaway at the counter ("Paket sipariş"): the order is created with the first item. */
    public function newTakeaway(Request $req): void
    {
        $this->page(null, ['channel' => 'takeaway']);
    }

    /** Renders W2/W10 for an order, or for a table/takeaway that has no order yet. */
    public function page(?array $o, array $ctx): never
    {
        $items = array_values(array_filter(Menu::items(), static fn(array $i): bool => (bool) $i['active']));
        $cats = array_values(array_filter(Menu::categories(), static fn(array $c): bool => (bool) $c['active']));
        $count = [];
        foreach ($items as $i) {
            $count[$i['category_id']] = ($count[$i['category_id']] ?? 0) + 1;
        }
        $cats = array_values(array_filter($cats, static fn(array $c): bool => !empty($count[$c['id']])));
        // items with options open the W4 sheet on a long press; with a required choice, on every tap
        $groups = Db::pairs('SELECT g.item_id, MAX(m.min_sel) FROM item_modifier_groups g JOIN modifier_groups m ON m.id = g.group_id WHERE g.deleted = 0 AND m.deleted = 0 GROUP BY g.item_id');
        $withGroups = array_fill_keys(array_keys($groups), true);
        $required = array_fill_keys(array_keys(array_filter($groups, static fn($min): bool => (int) $min > 0)), true);
        View::page('orders/take', [
            'o' => $o,
            'ctx' => $ctx,
            'items' => $items,
            'cats' => $cats,
            'count' => $count,
            'popular' => self::popular($items),
            'withGroups' => $withGroups,
            'required' => $required,
            'incart' => $o ? self::inCart($o) : [],
            'nav' => ($ctx['channel'] ?? 'table') === 'table' ? 'tables' : (Auth::can('cash.pay') ? 'cashier' : 'tables'),
            'scripts' => ['js/order.js'],
        ]);
    }

    /** W3: phone order summary. */
    public function summary(Request $req): void
    {
        $o = Orders::openBill($req->param('id'));
        View::page('orders/summary', ['o' => $o, 'nav' => 'tables', 'scripts' => ['js/order.js']]);
    }

    // ------------------------------------------------------------ lines

    public function add(Request $req): void
    {
        $orderId = $req->str('order_id');
        if ($orderId === '') {
            $orderId = $req->str('table_id') !== ''
                ? Orders::forTable($req->str('table_id'), max(0, $req->int('guests')))
                : Orders::create('takeaway');
        }
        $lineId = Orders::addItem($orderId, $req->str('item_id'), (float) ($req->input('qty') ?? 1), $req->arr('mods'), $req->str('note'));
        $l = Orders::line($lineId);
        $this->state($orderId, ['message' => $req->bool('quiet') ? null : I18n::t('order.added', ['name' => tn(Db::value('SELECT names FROM items WHERE id = ?', [$l['item_id']]))])]);
    }

    public function updateLine(Request $req): void
    {
        $l = Orders::line($req->param('id'));
        Orders::updateLine($l['id'], $req->input('qty') !== null ? read_num($req->input('qty'), 'qty') : null, $req->input('note') !== null ? $req->str('note') : null);
        $this->state($l['order_id']);
    }

    public function voidLine(Request $req): void
    {
        $l = Orders::line($req->param('id'));
        Orders::voidLine($l['id'], $req->str('reason'), (float) ($req->input('qty') ?? 0));
        $this->state($l['order_id'], ['message' => $l['status'] === 'new' ? null : I18n::t('line.voided')]);
    }

    /** Line sheet: quantity/note for a new line, void (with reason) for a sent one. */
    public function lineSheet(Request $req): void
    {
        $l = Orders::line($req->param('id'));
        Orders::openBill($l['order_id']);
        Response::json(['ok' => true, 'html' => View::partial('orders/_sheet_line', ['l' => $l])]);
    }

    public function send(Request $req): void
    {
        $o = Orders::openBill($req->param('id'));
        $n = Orders::send($o['id']);
        $this->state($o['id'], ['message' => $n ? I18n::t('order.sent', ['n' => digits($n)]) : I18n::t('order.nothing_new'), 'sent' => $n]);
    }

    public function preBill(Request $req): void
    {
        $o = Orders::openBill($req->param('id'));
        if (Db::value("SELECT 1 FROM order_items WHERE order_id = ? AND status = 'new' AND deleted = 0", [$o['id']])) {
            Orders::send($o['id']);
        }
        Orders::preBill($o['id']);
        Notify::closeFor($o['id'], 'bill');
        $this->state($o['id'], ['message' => I18n::t('order.prebill_done')]);
    }

    /** W4 options sheet for an item. */
    public function options(Request $req): void
    {
        $item = Menu::get($req->param('id'));
        if (!$item['orderable']) {
            throw new \InvalidArgumentException(I18n::t('order.err_soldout', ['name' => tn($item['names'])]));
        }
        $groups = Db::rows('SELECT m.* FROM item_modifier_groups g JOIN modifier_groups m ON m.id = g.group_id WHERE g.item_id = ? AND g.deleted = 0 AND m.deleted = 0 ORDER BY g.sort, m.sort', [$item['id']]);
        foreach ($groups as &$g) {
            $g['options'] = Db::rows('SELECT * FROM modifiers WHERE group_id = ? AND deleted = 0 ORDER BY sort, id', [$g['id']]);
        }
        unset($g);
        Response::json(['ok' => true, 'html' => View::partial('orders/_sheet_options', ['item' => $item, 'groups' => array_values(array_filter($groups, static fn(array $g): bool => (bool) $g['options']))])]);
    }

    // ------------------------------------------------------------ helpers

    /** JSON with the refreshed order panel, the send bar numbers and the in-cart counts. */
    private function state(string $orderId, array $extra = []): never
    {
        $o = Orders::get($orderId);
        $new = array_values(array_filter($o['lines'], static fn(array $l): bool => $l['status'] === 'new'));
        $newTotal = array_sum(array_map([Board::class, 'lineTotal'], $new));
        $newQty = (float) array_sum(array_column($new, 'qty'));
        Response::json(array_filter([
            'ok' => true,
            'order_id' => $o['id'],
            'url' => '/orders/' . $o['id'],
            'panel' => View::partial('orders/_order_panel', ['o' => $o]),
            'new' => count($new),
            'newText' => I18n::t('order.n_new', ['n' => digits(Orders::qtyText($newQty))]),
            'newTotal' => money($newTotal),
            'total' => money((int) $o['total']),
            'incart' => self::inCart($o),
        ], static fn($v): bool => $v !== null) + $extra);
    }

    /** item_id => quantity on unsent lines (the InCart badge on the menu tiles). */
    public static function inCart(array $o): array
    {
        $out = [];
        foreach ($o['lines'] as $l) {
            if ($l['status'] === 'new' && $l['item_id']) {
                $out[$l['item_id']] = ($out[$l['item_id']] ?? 0) + (float) $l['qty'];
            }
        }
        return $out;
    }

    /** The 12 best sellers of the last two weeks ("Popüler"); the first items of the menu when nothing sold yet. */
    public static function popular(array $items): array
    {
        $ids = array_keys(Db::pairs("SELECT oi.item_id, SUM(oi.qty) AS q FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE oi.item_id IS NOT NULL AND oi.status <> 'void' AND oi.deleted = 0 AND o.opened_at > ? GROUP BY oi.item_id ORDER BY q DESC LIMIT 12", [Clock::ms() - 14 * 86_400_000]));
        $known = array_flip(array_column($items, 'id'));
        $ids = array_values(array_filter($ids, static fn($id): bool => isset($known[$id])));
        if (count($ids) < 12) {
            foreach ($items as $i) {
                if (count($ids) >= 12) {
                    break;
                }
                if (!in_array($i['id'], $ids, true)) {
                    $ids[] = $i['id'];
                }
            }
        }
        return array_flip($ids);
    }
}
