<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Stock;

use Sofrexa\Core\{Db, Flash, I18n, Request, Response, View};
use Sofrexa\Modules\Menu\Menu;

/** Stock screens: S1/S6 list, S2 goods in, S3 count, S4 waste, S5 recipe and cost, S7 shopping list. */
final class StockController
{
    public function index(Request $req): void
    {
        $all = Stock::items();
        $f = $req->str('f');
        $items = Stock::items(['q' => $req->str('q'), 'state' => in_array($f, ['critical', 'low'], true) ? $f : '', 'category' => in_array($f, ['critical', 'low', ''], true) ? '' : $f]);
        View::page('stock/index', [
            'title' => I18n::t('stock.title'),
            'nav' => 'stock',
            'tab' => 'stock',
            'all' => $all,
            'items' => $items,
            'kpi' => Stock::kpis($all),
            'filter' => $f,
            'q' => $req->str('q'),
            'scripts' => ['js/stock.js'],
        ]);
    }

    /** Add / edit sheet with the recent moves. */
    public function itemSheet(Request $req): void
    {
        $id = $req->param('id');
        $item = $id === 'new' ? null : Stock::item($id);
        Response::json(['ok' => true, 'html' => View::partial('stock/_sheet_item', [
            'item' => $item, 'suppliers' => Stock::suppliers(), 'groups' => Stock::categories(), 'locations' => Stock::locations(),
            'moves' => $item ? Stock::moves($item['id'], 12) : [], 'name' => $req->str('name'),
        ])]);
    }

    public function saveItem(Request $req): void
    {
        $id = Stock::saveItem($req->all());
        Response::json(['ok' => true, 'id' => $id, 'item' => self::pick(Stock::item($id)), 'message' => I18n::t('stock.saved'), 'reload' => $req->bool('reload')]);
    }

    public function deleteItem(Request $req): void
    {
        $it = Stock::item($req->param('id'));
        Db::softDelete('stock_items', $it['id']);
        \Sofrexa\Core\Audit::log('stock.item_delete', $it['name'], 'stock_item', $it['id']);
        Response::json(['ok' => true, 'message' => I18n::t('stock.deleted'), 'reload' => true]);
    }

    /** Item search for the add-line fields (S2, S4, S5). */
    public function search(Request $req): void
    {
        $q = mb_strtolower($req->str('q'), 'UTF-8');
        $kind = $req->str('kind');
        $rows = [];
        foreach (Stock::items() as $r) {
            if (($q === '' || str_contains(mb_strtolower($r['name'], 'UTF-8'), $q)) && ($kind === '' || $r['kind'] === $kind)) {
                $rows[] = self::pick($r);
            }
            if (count($rows) >= 12) {
                break;
            }
        }
        Response::json(['ok' => true, 'rows' => $rows]);
    }

    public function saveSupplier(Request $req): void
    {
        $id = Stock::saveSupplier($req->all());
        Response::json(['ok' => true, 'id' => $id, 'name' => trim($req->str('name'))]);
    }

    // ------------------------------------------------------------ S2
    public function purchase(Request $req): void
    {
        View::page('stock/purchase', ['title' => I18n::t('pur.title'), 'nav' => 'stock', 'suppliers' => Stock::suppliers(), 'scripts' => ['js/stock.js']]);
    }

    public function savePurchase(Request $req): void
    {
        $lines = $req->arr('lines');
        Stock::document('purchase', $lines, [
            'supplier_id' => $req->str('supplier_id'), 'doc_no' => $req->str('doc_no'), 'day' => $req->str('day'),
            'pay_method' => $req->str('pay'), 'note' => $req->str('note'),
        ]);
        Flash::set('success', I18n::t('pur.saved', ['n' => count($lines)]));
        Response::json(['ok' => true, 'redirect' => '/stock']);
    }

    // ------------------------------------------------------------ S3
    public function count(Request $req): void
    {
        $locations = Stock::locations();
        $loc = in_array($req->str('loc'), $locations, true) ? $req->str('loc') : ($locations[0] ?? '');
        View::page('stock/count', ['title' => I18n::t('cnt.title'), 'nav' => 'stock', 'locations' => $locations, 'loc' => $loc,
            // semi-finished items made on demand have no stock of their own to count
            'items' => array_values(array_filter(Stock::items(['location' => $loc]), static fn(array $r): bool => (bool) $r['active'] && !($r['kind'] === 'semi' && $r['on_hand'] <= 0))), 'scripts' => ['js/stock.js']]);
    }

    public function saveCount(Request $req): void
    {
        $counted = array_filter($req->arr('counted'), static fn($v): bool => $v !== '' && $v !== null);
        if (!$counted) {
            throw new \InvalidArgumentException(I18n::t('cnt.none'));
        }
        [, $diffs] = Stock::count($counted, $req->str('loc'));
        Flash::set('success', I18n::t('cnt.done', ['n' => count($diffs)]));
        Response::json(['ok' => true, 'redirect' => '/stock']);
    }

    // ------------------------------------------------------------ S4
    public function waste(Request $req): void
    {
        $recent = Db::rows("SELECT m.*, s.name, s.unit, u.name AS user_name FROM stock_moves m JOIN stock_items s ON s.id = m.stock_item_id LEFT JOIN users u ON u.id = m.user_id
            WHERE m.reason LIKE 'waste%' ORDER BY m.at DESC LIMIT 20");
        View::page('stock/waste', ['title' => I18n::t('waste.title'), 'nav' => 'stock', 'recent' => $recent, 'scripts' => ['js/stock.js']]);
    }

    public function saveWaste(Request $req): void
    {
        if ($req->str('stock_item_id') === '') {
            throw new \Sofrexa\Core\ValidationError(['stock_item_id' => I18n::t('waste.pick')]);
        }
        $reason = trim($req->str('reason') . ($req->str('note') !== '' ? ' · ' . $req->str('note') : ''));
        Stock::document('waste', [['stock_item_id' => $req->str('stock_item_id'), 'qty' => $req->str('qty'), 'reason' => $reason]], ['note' => $req->str('note')]);
        Flash::set('success', I18n::t('waste.saved'));
        Response::json(['ok' => true, 'redirect' => '/stock']);
    }

    // ------------------------------------------------------------ S5
    public function recipe(Request $req): void
    {
        $kind = $req->param('kind') === 'stock' ? 'stock' : 'item';
        $id = $req->param('id');
        if ($kind === 'item') {
            $item = Menu::get($id);
            $head = ['name' => tn($item['names']), 'photo' => Menu::photoUrl($item['image'], 400), 'price' => (int) $item['price'], 'vat' => (float) $item['vat_eff'], 'back' => '/menu/items/' . $id];
        } else {
            $s = Stock::item($id);
            $head = ['name' => $s['name'], 'photo' => null, 'price' => null, 'vat' => 0, 'back' => '/stock', 'unit' => $s['unit']];
        }
        $lines = [];
        foreach (Stock::recipe($kind, $id) as $l) {
            $l['cost'] = (int) round(Stock::gross($l) * Stock::unitCost($l['stock_item_id']));
            $l['children'] = $l['item_kind'] === 'semi' ? array_map(static fn(array $c): array => $c + ['cost' => (int) round(Stock::gross($c) * Stock::gross($l) * Stock::unitCost($c['stock_item_id']))], Stock::recipe('stock', $l['stock_item_id'])) : [];
            $lines[] = $l;
        }
        View::page('stock/recipe', ['title' => I18n::t('rec.title', ['name' => $head['name']]), 'nav' => $kind === 'item' ? 'menu' : 'stock', 'kind' => $kind, 'id' => $id,
            'head' => $head, 'lines' => $lines, 'cost' => array_sum(array_column($lines, 'cost')), 'scripts' => ['js/stock.js']]);
    }

    public function saveRecipe(Request $req): void
    {
        $kind = $req->param('kind') === 'stock' ? 'stock' : 'item';
        // quantities come in g / ml for items kept in kg / L (factor 1000)
        $lines = [];
        foreach ($req->arr('lines') as $l) {
            if (!is_array($l) || ($l['stock_item_id'] ?? '') === '') {
                continue;
            }
            $f = max(1.0, read_num($l['factor'] ?? 1, 'factor', 1.0));
            $lines[] = ['stock_item_id' => (string) $l['stock_item_id'], 'qty' => read_num($l['qty'] ?? 0, 'qty') / $f, 'waste_pct' => $l['waste_pct'] ?? 0];
        }
        Stock::setRecipe($kind, $req->param('id'), $lines);
        $cost = $kind === 'item' ? Stock::itemCost($req->param('id'))['total'] : (int) round(Stock::unitCost($req->param('id')));
        Response::json(['ok' => true, 'message' => I18n::t('rec.saved', ['cost' => money($cost)]), 'reload' => true]);
    }

    // ------------------------------------------------------------ S7
    public function shopping(Request $req): void
    {
        $suppliers = [];
        foreach (Stock::suppliers() as $s) {
            $suppliers[$s['name']] = $s;
        }
        View::page('stock/shopping', ['title' => I18n::t('shop.title'), 'nav' => 'stock', 'list' => Stock::shoppingList(), 'suppliers' => $suppliers, 'scripts' => ['js/stock.js']]);
    }

    /** What the search and the add-line fields need about an item. */
    private static function pick(array $r): array
    {
        return ['id' => $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'unit_label' => Stock::unitLabel($r['unit']), 'kind' => $r['kind'],
            'on_hand' => (float) ($r['on_hand'] ?? 0), 'on_hand_text' => Stock::qty((float) ($r['on_hand'] ?? 0)), 'cost' => (float) $r['avg_cost'],
            'cost_text' => money((int) round((float) $r['avg_cost'])), 'vat' => (float) ($r['vat_rate'] ?? 10), 'group' => (string) $r['category']];
    }
}
