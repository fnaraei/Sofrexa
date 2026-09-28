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
    public const UNITS = ['kg', 'g', 'lt', 'ml', 'adet', 'porsiyon', 'paket', 'kutu'];
    public const MAX_DEPTH = 5;

    /** "6,2", "38", "0,15" — Turkish decimals, no trailing zeros (Persian digits for fa). */
    public static function qty(float $q): string
    {
        $dec = abs($q - round($q)) < 0.0005 ? 0 : (abs($q * 10 - round($q * 10)) < 0.005 ? 1 : (abs($q * 100 - round($q * 100)) < 0.05 ? 2 : 3));
        return I18n::num($q, $dec);
    }

    /** Units as on the designs: "L" for litres. */
    public static function unitLabel(string $unit): string
    {
        return $unit === 'lt' ? 'L' : $unit;
    }

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
            // a semi-finished item made from its recipe on demand has no stock of its own
            $r['state'] = $r['kind'] === 'semi' && (float) $r['min_qty'] <= 0 && $r['on_hand'] <= 0 ? 'ok' : self::state($r['on_hand'], (float) $r['min_qty']);
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
            if (($f['location'] ?? '') !== '' && (string) $r['location'] !== $f['location']) {
                continue;
            }
            $out[] = $r;
        }
        // critical first, then low, then the rest by name (as on S1)
        $rank = ['critical' => 0, 'low' => 1, 'ok' => 2];
        usort($out, static fn(array $a, array $b): int => [$rank[$a['state']], mb_strtolower($a['name'], 'UTF-8')] <=> [$rank[$b['state']], mb_strtolower($b['name'], 'UTF-8')]);
        return $out;
    }

    /** Kritik: under the minimum (or nothing left); Az: less than a quarter above the minimum; else Yeterli. */
    public static function state(float $onHand, float $min): string
    {
        if ($onHand <= 0 || $onHand < $min) {
            return 'critical';
        }
        return $min > 0 && $onHand < $min * 1.25 ? 'low' : 'ok';
    }

    public static function locations(): array
    {
        // the place with the most items first (Soğuk oda before Bar)
        return array_column(Db::rows("SELECT location, COUNT(*) AS n FROM stock_items WHERE deleted = 0 AND location IS NOT NULL AND location <> '' GROUP BY location ORDER BY n DESC, location"), 'location');
    }

    /** S1 KPIs: stock value, critical items, today's use by sales and waste vs yesterday, the last count. */
    public static function kpis(array $items): array
    {
        $rollover = (int) \Sofrexa\Core\Settings::get('day.rollover_hour', 5);
        [$from, $to] = Clock::dayRange(\Sofrexa\Modules\Orders\Orders::businessDay(), $rollover);
        $use = static fn(int $a, int $b): int => (int) round(-(float) Db::value("SELECT COALESCE(SUM(qty * unit_cost), 0) FROM stock_moves WHERE at >= ? AND at < ? AND (reason IN ('sale', 'void') OR reason LIKE 'waste%')", [$a, $b]));
        $last = Db::row("SELECT id, at FROM stock_docs WHERE kind = 'count' ORDER BY at DESC LIMIT 1");
        $lastDiff = $last ? (int) round((float) Db::value("SELECT COALESCE(SUM(qty * unit_cost), 0) FROM stock_moves WHERE doc_id = ?", [$last['id']])) : 0;
        $critical = array_values(array_filter($items, static fn(array $r): bool => $r['state'] === 'critical' && $r['active']));
        return [
            'value' => (int) array_sum(array_column($items, 'value')),
            'critical' => $critical,
            'today' => $use($from, $to),
            'yesterday' => $use($from - 86_400_000, $to - 86_400_000),
            'last_count' => $last ? (int) $last['at'] : null,
            'last_diff' => $lastDiff,
        ];
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
            'kind' => ($in['kind'] ?? '') === 'semi' ? 'semi' : 'raw', 'min_qty' => max(0, read_num($in['min_qty'] ?? 0, 'min_qty')),
            'supplier_id' => ($in['supplier_id'] ?? '') ?: null, 'active' => isset($in['active']) ? (int) (bool) $in['active'] : 1,
            'location' => trim((string) ($in['location'] ?? '')) ?: null,
            'vat_rate' => max(0, read_num($in['vat_rate'] ?? 10, 'vat_rate', 10.0)),
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
        $waste = [];
        foreach ($lines as $l) {
            $sid = (string) ($l['stock_item_id'] ?? $l[0] ?? '');
            $qty = read_num($l['qty'] ?? $l[1] ?? 0, 'qty');
            if ($sid !== '' && $qty > 0) {
                $clean[$sid] = ($clean[$sid] ?? 0) + $qty;
                $waste[$sid] = max(0, min(90, read_num($l['waste_pct'] ?? 0, 'waste_pct')));
            }
        }
        if ($kind === 'stock') {
            foreach (array_keys($clean) as $sid) {
                if ($sid === $parentId || self::uses($sid, $parentId)) {
                    throw new ValidationError(['recipe' => I18n::t('stock.err_loop')]);
                }
            }
        }
        Db::tx(static function () use ($kind, $parentId, $clean, $waste): void {
            foreach (Db::rows('SELECT id FROM recipes WHERE parent_kind = ? AND parent_id = ? AND deleted = 0', [$kind, $parentId]) as $r) {
                Db::softDelete('recipes', $r['id']);
            }
            foreach ($clean as $sid => $qty) {
                Db::save('recipes', ['parent_kind' => $kind, 'parent_id' => $parentId, 'stock_item_id' => $sid, 'qty' => round($qty, 6), 'waste_pct' => $waste[$sid] ?? 0]);
            }
        });
        Audit::log('stock.recipe', ($kind === 'item' ? tn(Db::value('SELECT names FROM items WHERE id = ?', [$parentId]) ?? '{}', 'tr') : (string) Db::value('SELECT name FROM stock_items WHERE id = ?', [$parentId])) . ' · ' . count($clean) . ' malzeme', $kind === 'item' ? 'item' : 'stock_item', $parentId);
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
    /**
     * What a recipe line takes from stock: its quantity is what goes on the plate, and "Fire %" is the part of the raw
     * ingredient lost preparing it (peel, bones, trimming), so 100 g at 20% fire takes 125 g — gross = net / (1 − fire).
     * The same quantity everywhere: the cost, the stock a sale takes, a semi-finished item's cost (decision 53, audit 8 O01).
     */
    public static function gross(array $line): float
    {
        return (float) $line['qty'] / (1 - min(90.0, max(0.0, (float) ($line['waste_pct'] ?? 0))) / 100);
    }

    public static function unitCost(string $stockId, int $depth = 0): float
    {
        $s = Db::row('SELECT kind, avg_cost FROM stock_items WHERE id = ?', [$stockId]);
        if (!$s) {
            return 0.0;
        }
        if ($s['kind'] === 'semi' && (float) $s['avg_cost'] <= 0 && $depth < self::MAX_DEPTH) {
            $sum = 0.0;
            foreach (self::recipe('stock', $stockId) as $l) {
                $sum += self::gross($l) * self::unitCost($l['stock_item_id'], $depth + 1);
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
            $c = self::gross($l) * self::unitCost($l['stock_item_id']);
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
            $need = self::gross($l) * $qty;
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

    /**
     * What a sent line really took from stock and how much of it already left the sale again (void moves):
     * stock_item_id => [taken, back, cost]; cost = the unit cost it was taken at.
     */
    private static function taken(string $lineId): array
    {
        $out = [];
        foreach (Db::rows("SELECT stock_item_id AS id, reason, SUM(qty) AS q, SUM(qty * unit_cost) AS v FROM stock_moves
            WHERE order_item_id = ? AND reason IN ('sale', 'void') GROUP BY stock_item_id, reason", [$lineId]) as $m) {
            $out[$m['id']] ??= ['taken' => 0.0, 'back' => 0.0, 'cost' => 0.0];
            if ($m['reason'] === 'sale') {
                $out[$m['id']]['taken'] = -(float) $m['q'];
                $out[$m['id']]['cost'] = (float) $m['q'] != 0.0 ? (float) $m['v'] / (float) $m['q'] : 0.0;
            } else {
                $out[$m['id']]['back'] = (float) $m['q'];
            }
        }
        return $out;
    }

    /**
     * The ingredients of a voided line leave the sale: $how 'return' puts them back on the shelf (not cooked), 'waste'
     * books them as waste (cooked). Worked out from what the line really took when it was sent — not from today's recipe or
     * price — per portion sent, and never more than is still out: voiding the same portions again gives nothing back.
     */
    public static function settleVoid(string $voidLineId, string $how): void
    {
        $v = Db::row('SELECT id, qty, void_of FROM order_items WHERE id = ?', [$voidLineId]);
        if (!$v) {
            return;
        }
        $source = $v['void_of'] ?: $v['id'];
        $src = Db::row('SELECT qty, sent_qty FROM order_items WHERE id = ?', [$source]);
        $sent = (float) ($src['sent_qty'] ?? 0) > 0 ? (float) $src['sent_qty'] : (float) ($src['qty'] ?? 0);
        if ($sent <= 0) {
            return;
        }
        $share = min(1.0, (float) $v['qty'] / $sent);
        $now = Clock::ms();
        foreach (self::taken($source) as $sid => $t) {
            $q = round(min($t['taken'] * $share, $t['taken'] - $t['back']), 4);
            if ($q <= 0) {
                continue;
            }
            Db::append('stock_moves', ['stock_item_id' => $sid, 'qty' => $q, 'unit_cost' => $t['cost'], 'reason' => 'void', 'order_item_id' => $source, 'at' => $now]);
            if ($how === 'waste') {
                Db::append('stock_moves', ['stock_item_id' => $sid, 'qty' => -$q, 'unit_cost' => $t['cost'], 'reason' => 'waste:iptal', 'order_item_id' => $voidLineId, 'at' => $now]);
            }
        }
    }

    /** A cancelled dish booked as waste went to another bill after all: its ingredients count as that line's sale. */
    public static function reuseWaste(string $voidLineId, string $newLineId): void
    {
        $now = Clock::ms();
        foreach (Db::rows("SELECT stock_item_id AS id, SUM(qty) AS q, SUM(qty * unit_cost) AS v FROM stock_moves WHERE order_item_id = ? AND reason LIKE 'waste%' GROUP BY stock_item_id", [$voidLineId]) as $m) {
            $q = -(float) $m['q'];
            if ($q <= 0) {
                continue;
            }
            $cost = (float) $m['v'] / (float) $m['q'];
            Db::append('stock_moves', ['stock_item_id' => $m['id'], 'qty' => round($q, 4), 'unit_cost' => $cost, 'reason' => 'waste:iptal', 'order_item_id' => $voidLineId, 'at' => $now]);
            Db::append('stock_moves', ['stock_item_id' => $m['id'], 'qty' => -round($q, 4), 'unit_cost' => $cost, 'reason' => 'sale', 'order_item_id' => $newLineId, 'at' => $now]);
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
            $qty = read_num($l['qty'] ?? 0, 'qty');
            if (($l['stock_item_id'] ?? '') === '' || $qty <= 0) {
                continue;
            }
            $price = $kind === 'purchase' ? (isset($l['total']) && $l['total'] !== '' ? Money::parse($l['total']) / $qty : Money::parse($l['unit_price'] ?? 0)) : self::unitCost($l['stock_item_id']);
            $vatRate = isset($l['vat']) && $l['vat'] !== '' ? read_num($l['vat'], 'vat') : (float) Db::value('SELECT vat_rate FROM stock_items WHERE id = ?', [$l['stock_item_id']]);
            $clean[] = ['id' => (string) $l['stock_item_id'], 'qty' => $qty, 'price' => (float) $price, 'vat' => $kind === 'purchase' ? max(0, $vatRate) : 0, 'reason' => trim((string) ($l['reason'] ?? ''))];
        }
        if (!$clean) {
            throw new ValidationError(['lines' => I18n::t('stock.err_lines')]);
        }
        $cash = $kind === 'purchase' && ($head['pay_method'] ?? '') === 'cash';
        if ($cash) {
            \Sofrexa\Modules\Orders\Shifts::forCash(); // paid from the drawer: the till and an open shift, or nothing is booked
        }
        // purchase prices are without VAT (the stock cost); the invoice total adds the VAT
        $net = (int) round(array_sum(array_map(static fn(array $l): float => $l['qty'] * $l['price'], $clean)));
        $vat = (int) round(array_sum(array_map(static fn(array $l): float => $l['qty'] * $l['price'] * $l['vat'] / 100, $clean)));
        $total = $net + $vat;
        $u = Auth::user();
        $now = Clock::ms();
        $supplier = ($head['supplier_id'] ?? '') ? (string) Db::value('SELECT name FROM suppliers WHERE id = ?', [$head['supplier_id']]) : '';
        $docId = Db::tx(static function () use ($kind, $clean, $head, $total, $vat, $u, $now, $cash, $supplier): string {
            $doc = Db::append('stock_docs', ['kind' => $kind, 'supplier_id' => ($head['supplier_id'] ?? '') ?: null, 'doc_no' => trim((string) ($head['doc_no'] ?? '')) ?: null,
                'day' => ($head['day'] ?? '') ?: \Sofrexa\Modules\Orders\Orders::businessDay(), 'total' => $total, 'vat' => $vat, 'pay_method' => ($head['pay_method'] ?? '') ?: null,
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
            if ($cash) {
                // the invoice, the stock and the cash leaving the drawer: one step, all or nothing
                \Sofrexa\Modules\Orders\Shifts::move('out', 'TRY', $total, I18n::t('moves.r_supplier', [], 'tr'), trim($supplier . ' · ' . ($head['doc_no'] ?? ''), ' ·'), null, null, null, 'stock_doc:' . $doc);
            }
            return $doc;
        });
        Audit::log('stock.' . $kind, trim($supplier . ' · ' . count($clean) . ' kalem · ' . Money::fmt($total, false, 'tr'), ' ·'), 'stock_doc', $docId);
        if ($kind === 'purchase') {
            foreach (Db::rows("SELECT DISTINCT parent_id FROM recipes WHERE parent_kind = 'item' AND deleted = 0") as $r) {
                self::refreshItemCost($r['parent_id']);
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
                $c = read_num($v, 'counted[' . $sid . ']');
                if ($c < 0) {
                    throw new ValidationError(['counted[' . $sid . ']' => I18n::t('err.number')]);
                }
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
            // order in whole kilos / litres / pieces, grams and millilitres by ten
            $step = in_array($r['unit'], ['g', 'ml'], true) ? 10 : 1;
            $suggest = ceil($suggest / $step) * $step;
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
