<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Stock;

use Sofrexa\Core\{Audit, Auth, Clock, Db, I18n, Money, ValidationError};

/**
 * Stock (S1–S7). Quantity on hand is the sum of stock_moves (append-only). Recipes are multi-level:
 * a menu item uses raw or semi-finished stock items; a semi-finished item has its own recipe per 1 unit.
 * A sale (lines sent to the kitchen) deducts the raw materials behind it; a void gives them back.
 */
final class Stock
{
    public const UNITS = ['kg', 'g', 'lt', 'ml', 'adet', 'paket', 'kutu'];
    public const MAX_DEPTH = 5;

    // ------------------------------------------------------------ reading

    /** Stock items with on-hand quantity, value and state (ok | low | out). $f: q, category, state, supplier. */
    public static function items(array $f = []): array
    {
        $rows = Db::rows("SELECT s.*, sp.name AS supplier_name, COALESCE(m.qty, 0) AS on_hand, m.last_in
            FROM stock_items s LEFT JOIN suppliers sp ON sp.id = s.supplier_id
            LEFT JOIN (SELECT stock_item_id, SUM(qty) AS qty, MAX(CASE WHEN qty > 0 AND reason = 'purchase' THEN at END) AS last_in FROM stock_moves GROUP BY stock_item_id) m ON m.stock_item_id = s.id
            WHERE s.deleted = 0 ORDER BY s.category, s.name");
        $out = [];
        foreach ($rows as $r) {
            $r['on_hand'] = round((float) $r['on_hand'], 3);
            $r['state'] = $r['on_hand'] <= 0 ? 'out' : ($r['on_hand'] < (float) $r['min_qty'] ? 'low' : 'ok');
            $r['value'] = (int) round(max(0, $r['on_hand']) * (float) $r['avg_cost']);
            if (($f['q'] ?? '') !== '' && !str_contains(mb_strtolower($r['name'], 'UTF-8'), mb_strtolower((string) $f['q'], 'UTF-8'))) {
                continue;
            }
            if (($f['category'] ?? '') !== '' && $r['category'] !== $f['category']) {
                continue;
            }
            if (($f['state'] ?? '') !== '' && ($f['state'] === 'alert' ? $r['state'] === 'ok' : $r['state'] !== $f['state'])) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    public static function item(string $id): array
    {
        $r = Db::row('SELECT * FROM stock_items WHERE id = ? AND deleted = 0', [$id]);
        if (!$r) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $r['on_hand'] = self::onHand($id);
        return $r;
    }

    public static function onHand(string $id): float
    {
        return round((float) Db::value('SELECT COALESCE(SUM(qty), 0) FROM stock_moves WHERE stock_item_id = ?', [$id]), 3);
    }

    public static function categories(): array
    {
        return array_column(Db::rows("SELECT DISTINCT category FROM stock_items WHERE deleted = 0 AND category IS NOT NULL AND category <> '' ORDER BY category"), 'category');
    }

    public static function suppliers(): array
    {
        return Db::rows('SELECT * FROM suppliers WHERE deleted = 0 ORDER BY name');
    }

    /** Total value of the stock on hand (kuruş). */
    public static function value(): int
    {
        return (int) array_sum(array_column(self::items(), 'value'));
    }

    // ------------------------------------------------------------ items and suppliers

    public static function saveItem(array $in): string
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ValidationError(['name' => I18n::t('stock.err_name')]);
        }
        $unit = in_array($in['unit'] ?? '', self::UNITS, true) ? $in['unit'] : 'kg';
        $row = [
            'name' => mb_substr($name, 0, 120), 'unit' => $unit, 'category' => trim((string) ($in['category'] ?? '')) ?: null,
            'kind' => ($in['kind'] ?? '') === 'semi' ? 'semi' : 'raw', 'min_qty' => max(0, (float) str_replace(',', '.', (string) ($in['min_qty'] ?? 0))),
            'supplier_id' => ($in['supplier_id'] ?? '') ?: null, 'active' => isset($in['active']) ? (int) (bool) $in['active'] : 1,
        ];
        if (isset($in['avg_cost']) && $in['avg_cost'] !== '') {
            $row['avg_cost'] = Money::parse($in['avg_cost']);
        }
        $id = ($in['id'] ?? '') ?: null;
        $new = $id === null;
        $id = Db::save('stock_items', ($id ? ['id' => $id] : []) + $row);
        Audit::log($new ? 'stock.item_add' : 'stock.item_edit', $row['name'] . ' · ' . $unit, 'stock_item', $id);
        return $id;
    }

    public static function saveSupplier(array $in): string
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ValidationError(['name' => I18n::t('stock.err_name')]);
        }
        return Db::save('suppliers', (($in['id'] ?? '') ? ['id' => $in['id']] : []) + ['name' => mb_substr($name, 0, 120), 'phone' => trim((string) ($in['phone'] ?? '')) ?: null, 'note' => trim((string) ($in['note'] ?? '')) ?: null]);
    }

    // ------------------------------------------------------------ recipes and cost

    /** Recipe lines of a menu item ('item') or a semi-finished stock item ('stock', per 1 unit). */
    public static function recipe(string $kind, string $parentId): array
    {
        return Db::rows('SELECT r.*, s.name, s.unit, s.kind AS item_kind, s.avg_cost FROM recipes r JOIN stock_items s ON s.id = r.stock_item_id
            WHERE r.parent_kind = ? AND r.parent_id = ? AND r.deleted = 0 ORDER BY s.name', [$kind, $parentId]);
    }

    /** Replaces a recipe: $lines = [[stock_item_id, qty], ...]. Refuses a semi item that ends up in its own recipe. */
    public static function setRecipe(string $kind, string $parentId, array $lines): void
    {
        $kind = $kind === 'stock' ? 'stock' : 'item';
        $clean = [];
        foreach ($lines as $l) {
            $sid = (string) ($l['stock_item_id'] ?? $l[0] ?? '');
            $qty = (float) str_replace(',', '.', (string) ($l['qty'] ?? $l[1] ?? 0));
            if ($sid !== '' && $qty > 0) {
                $clean[$sid] = ($clean[$sid] ?? 0) + $qty;
            }
        }
        if ($kind === 'stock') {
            foreach (array_keys($clean) as $sid) {
                if ($sid === $parentId || self::uses($sid, $parentId)) {
                    throw new ValidationError(['recipe' => I18n::t('stock.err_loop')]);
                }
            }
        }
        Db::tx(static function () use ($kind, $parentId, $clean): void {
            foreach (Db::rows('SELECT id FROM recipes WHERE parent_kind = ? AND parent_id = ? AND deleted = 0', [$kind, $parentId]) as $r) {
                Db::softDelete('recipes', $r['id']);
            }
            foreach ($clean as $sid => $qty) {
                Db::save('recipes', ['parent_kind' => $kind, 'parent_id' => $parentId, 'stock_item_id' => $sid, 'qty' => round($qty, 4)]);
            }
        });
        if ($kind === 'item') {
            self::refreshItemCost($parentId);
        } else {
            foreach (Db::rows("SELECT DISTINCT parent_id FROM recipes WHERE parent_kind = 'item' AND deleted = 0") as $r) {
                self::refreshItemCost($r['parent_id']);
            }
        }
    }

    /** Does semi item $semiId use $target somewhere down its recipe? */
    private static function uses(string $semiId, string $target, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            return true;
        }
        foreach (self::recipe('stock', $semiId) as $l) {
            if ($l['stock_item_id'] === $target || self::uses($l['stock_item_id'], $target, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /** Unit cost (kuruş per unit) of a stock item: its average purchase cost, or its recipe for a semi item without purchases. */
    public static function unitCost(string $stockId, int $depth = 0): float
    {
        $s = Db::row('SELECT kind, avg_cost FROM stock_items WHERE id = ?', [$stockId]);
        if (!$s) {
            return 0.0;
        }
        if ($s['kind'] === 'semi' && (float) $s['avg_cost'] <= 0 && $depth < self::MAX_DEPTH) {
            $sum = 0.0;
            foreach (self::recipe('stock', $stockId) as $l) {
                $sum += (float) $l['qty'] * self::unitCost($l['stock_item_id'], $depth + 1);
            }
            return $sum;
        }
        return (float) $s['avg_cost'];
    }

    /** Cost of one portion of a menu item from its recipe (kuruş); lines with their share for S5. */
    public static function itemCost(string $itemId): array
    {
        $lines = [];
        $total = 0.0;
        foreach (self::recipe('item', $itemId) as $l) {
            $c = (float) $l['qty'] * self::unitCost($l['stock_item_id']);
            $lines[] = $l + ['cost' => (int) round($c)];
            $total += $c;
        }
        return ['total' => (int) round($total), 'lines' => $lines];
    }

    /** Stores the recipe cost on the menu item (M1 "Maliyet · kâr"). */
    public static function refreshItemCost(string $itemId): void
    {
        if (!Db::value('SELECT 1 FROM recipes WHERE parent_kind = ? AND parent_id = ? AND deleted = 0', ['item', $itemId])) {
            return;
        }
        $cost = self::itemCost($itemId)['total'];
        if ((int) Db::value('SELECT cost FROM items WHERE id = ?', [$itemId]) !== $cost) {
            Db::save('items', ['id' => $itemId, 'cost' => $cost]);
        }
    }

    /** Raw needs for $qty portions of a menu item: stock_item_id => quantity (semi items without their own stock are expanded). */
    public static function needs(string $kind, string $parentId, float $qty, int $depth = 0, array &$out = []): array
    {
        foreach (self::recipe($kind, $parentId) as $l) {
            $need = (float) $l['qty'] * $qty;
            $expand = $l['item_kind'] === 'semi' && $depth < self::MAX_DEPTH && Db::value('SELECT 1 FROM recipes WHERE parent_kind = ? AND parent_id = ? AND deleted = 0', ['stock', $l['stock_item_id']])
                && !Db::value("SELECT 1 FROM stock_moves WHERE stock_item_id = ? AND reason IN ('purchase', 'production') LIMIT 1", [$l['stock_item_id']]);
            if ($expand) {
                self::needs('stock', $l['stock_item_id'], $need, $depth + 1, $out);
            } else {
                $out[$l['stock_item_id']] = ($out[$l['stock_item_id']] ?? 0) + $need;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ sales

    /** Deducts the ingredients of lines just sent to the kitchen (called by Orders::send). */
    public static function consume(array $lineIds): void
    {
        if (!$lineIds) {
            return;
        }
        $lines = Db::rows("SELECT id, item_id, qty FROM order_items WHERE id IN (" . Db::in($lineIds) . ') AND item_id IS NOT NULL', $lineIds);
        $now = Clock::ms();
        foreach ($lines as $l) {
            foreach (self::needs('item', $l['item_id'], (float) $l['qty']) as $sid => $q) {
                Db::append('stock_moves', ['stock_item_id' => $sid, 'qty' => -round($q, 4), 'unit_cost' => self::unitCost($sid), 'reason' => 'sale', 'order_item_id' => $l['id'], 'at' => $now]);
            }
        }
    }

    /** Gives back the ingredients of a voided sent line (all of it, or $qty portions). */
    public static function giveBack(string $lineId, float $qty): void
    {
        $l = Db::row('SELECT item_id FROM order_items WHERE id = ?', [$lineId]);
        if (!$l || !$l['item_id'] || !Db::value("SELECT 1 FROM stock_moves WHERE order_item_id = ? AND reason = 'sale'", [$lineId])) {
            return;
        }
        $now = Clock::ms();
        foreach (self::needs('item', $l['item_id'], $qty) as $sid => $q) {
            Db::append('stock_moves', ['stock_item_id' => $sid, 'qty' => round($q, 4), 'unit_cost' => self::unitCost($sid), 'reason' => 'void', 'order_item_id' => $lineId, 'at' => $now]);
        }
    }

    // ------------------------------------------------------------ documents

    /**
     * Purchase (S2), waste (S4), return to supplier. $lines = [['stock_item_id', 'qty', 'unit_price' (kuruş per unit, purchase)
     * , 'reason' (waste)], ...]. Returns the document id.
     */
    public static function document(string $kind, array $lines, array $head = []): string
    {
        if (!in_array($kind, ['purchase', 'waste', 'return'], true)) {
            throw new \InvalidArgumentException('kind');
        }
        $clean = [];
        foreach ($lines as $l) {
            $qty = (float) str_replace(',', '.', (string) ($l['qty'] ?? 0));
            if (($l['stock_item_id'] ?? '') === '' || $qty <= 0) {
                continue;
            }
            $price = $kind === 'purchase' ? (isset($l['total']) && $l['total'] !== '' ? Money::parse($l['total']) / $qty : Money::parse($l['unit_price'] ?? 0)) : self::unitCost($l['stock_item_id']);
            $clean[] = ['id' => (string) $l['stock_item_id'], 'qty' => $qty, 'price' => (float) $price, 'reason' => trim((string) ($l['reason'] ?? ''))];
        }
        if (!$clean) {
            throw new ValidationError(['lines' => I18n::t('stock.err_lines')]);
        }
        $total = (int) round(array_sum(array_map(static fn(array $l): float => $l['qty'] * $l['price'], $clean)));
        $u = Auth::user();
        $now = Clock::ms();
        $docId = Db::tx(static function () use ($kind, $clean, $head, $total, $u, $now): string {
            $doc = Db::append('stock_docs', ['kind' => $kind, 'supplier_id' => ($head['supplier_id'] ?? '') ?: null, 'doc_no' => trim((string) ($head['doc_no'] ?? '')) ?: null,
                'day' => ($head['day'] ?? '') ?: \Sofrexa\Modules\Orders\Orders::businessDay(), 'total' => $total, 'pay_method' => ($head['pay_method'] ?? '') ?: null,
                'note' => trim((string) ($head['note'] ?? '')) ?: null, 'user_id' => $u['id'] ?? null, 'at' => $now]);
            foreach ($clean as $l) {
                if ($kind === 'purchase') {
                    // weighted average cost with what is on hand
                    $onHand = max(0.0, self::onHand($l['id']));
                    $old = (float) Db::value('SELECT avg_cost FROM stock_items WHERE id = ?', [$l['id']]);
                    $avg = $onHand + $l['qty'] > 0 ? ($onHand * $old + $l['qty'] * $l['price']) / ($onHand + $l['qty']) : $l['price'];
                    Db::save('stock_items', ['id' => $l['id'], 'avg_cost' => round($avg, 2)]);
                }
                Db::append('stock_moves', ['doc_id' => $doc, 'stock_item_id' => $l['id'], 'qty' => $kind === 'purchase' ? $l['qty'] : -$l['qty'],
                    'unit_cost' => $l['price'], 'reason' => $kind === 'waste' && $l['reason'] !== '' ? 'waste:' . mb_substr($l['reason'], 0, 60) : $kind, 'at' => $now, 'user_id' => $u['id'] ?? null]);
            }
            return $doc;
        });
        $supplier = ($head['supplier_id'] ?? '') ? (string) Db::value('SELECT name FROM suppliers WHERE id = ?', [$head['supplier_id']]) : '';
        Audit::log('stock.' . $kind, trim($supplier . ' · ' . count($clean) . ' kalem · ' . Money::fmt($total, false, 'tr'), ' ·'), 'stock_doc', $docId);
        if ($kind === 'purchase') {
            foreach (Db::rows("SELECT DISTINCT parent_id FROM recipes WHERE parent_kind = 'item' AND deleted = 0") as $r) {
                self::refreshItemCost($r['parent_id']);
            }
            // paid from the till: the cash leaves the drawer
            if (($head['pay_method'] ?? '') === 'cash' && \Sofrexa\Modules\Orders\Shifts::currentId()) {
                \Sofrexa\Modules\Orders\Shifts::move('out', 'TRY', $total, I18n::t('moves.r_supplier', [], 'tr'), trim($supplier . ' · ' . ($head['doc_no'] ?? ''), ' ·'));
            }
        }
        return $docId;
    }

    /** Stock count (S3): counted quantities → count lines and the adjustment moves. Returns [doc id, lines with difference]. */
    public static function count(array $counted, string $note = ''): array
    {
        $u = Auth::user();
        $now = Clock::ms();
        $diffs = [];
        $docId = Db::tx(static function () use ($counted, $note, $u, $now, &$diffs): string {
            $doc = Db::append('stock_docs', ['kind' => 'count', 'day' => \Sofrexa\Modules\Orders\Orders::businessDay(), 'total' => 0, 'note' => $note ?: null, 'user_id' => $u['id'] ?? null, 'at' => $now]);
            $value = 0;
            foreach ($counted as $sid => $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                $c = (float) str_replace(',', '.', (string) $v);
                $expected = self::onHand((string) $sid);
                Db::append('stock_count_lines', ['doc_id' => $doc, 'stock_item_id' => (string) $sid, 'expected' => $expected, 'counted' => $c]);
                $d = round($c - $expected, 3);
                if (abs($d) > 0.0005) {
                    $cost = self::unitCost((string) $sid);
                    Db::append('stock_moves', ['doc_id' => $doc, 'stock_item_id' => (string) $sid, 'qty' => $d, 'unit_cost' => $cost, 'reason' => 'count', 'at' => $now, 'user_id' => $u['id'] ?? null]);
                    $diffs[(string) $sid] = ['expected' => $expected, 'counted' => $c, 'diff' => $d, 'value' => (int) round($d * $cost)];
                    $value += (int) round($d * $cost);
                }
            }
            return $doc;
        });
        Audit::log('stock.count', count($counted) . ' kalem sayıldı · ' . count($diffs) . ' fark', 'stock_doc', $docId);
        return [$docId, $diffs];
    }

    /** Shopping list (S7): items under their minimum, grouped by supplier, with a suggested quantity (up to twice the minimum). */
    public static function shoppingList(): array
    {
        $out = [];
        foreach (self::items() as $r) {
            if ($r['state'] === 'ok' || (float) $r['min_qty'] <= 0 || !$r['active']) {
                continue;
            }
            $suggest = max(0, (float) $r['min_qty'] * 2 - max(0, $r['on_hand']));
            $key = $r['supplier_name'] ?? '';
            $out[$key][] = $r + ['suggest' => round($suggest, 2)];
        }
        ksort($out);
        return $out;
    }

    /** Recent moves of an item (history). */
    public static function moves(string $stockId, int $limit = 30): array
    {
        return Db::rows('SELECT m.*, d.kind AS doc_kind, d.doc_no, sp.name AS supplier_name, u.name AS user_name FROM stock_moves m
            LEFT JOIN stock_docs d ON d.id = m.doc_id LEFT JOIN suppliers sp ON sp.id = d.supplier_id LEFT JOIN users u ON u.id = m.user_id
            WHERE m.stock_item_id = ? ORDER BY m.at DESC LIMIT ' . $limit, [$stockId]);
    }
}
