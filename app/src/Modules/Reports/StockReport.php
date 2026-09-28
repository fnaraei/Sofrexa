<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Reports;

use Sofrexa\Core\Db;
use Sofrexa\Modules\Stock\Stock;

/** Stock movement report: per item what came in, what sales and waste took, count differences, what is left and its value. */
final class StockReport
{
    /** Per stock item: unit, in, sale, waste, count, on_hand (now), start and end of the period, value (now). */
    public static function moves(int $from, int $to): array
    {
        $m = [];
        foreach (Db::rows("SELECT stock_item_id AS id,
                SUM(CASE WHEN reason = 'purchase' THEN qty ELSE 0 END) AS inn,
                SUM(CASE WHEN reason IN ('sale', 'void') THEN -qty ELSE 0 END) AS sale,
                SUM(CASE WHEN reason LIKE 'waste%' OR reason = 'return' THEN -qty ELSE 0 END) AS waste,
                SUM(CASE WHEN reason = 'count' THEN qty ELSE 0 END) AS cnt
            FROM stock_moves WHERE at >= ? AND at < ? GROUP BY stock_item_id", [$from, $to]) as $r) {
            $m[$r['id']] = $r;
        }
        $after = Db::pairs('SELECT stock_item_id, SUM(qty) FROM stock_moves WHERE at >= ? GROUP BY stock_item_id', [$to]);
        $rows = [];
        foreach (Stock::items() as $s) {
            $r = $m[$s['id']] ?? null;
            if (!$r && (float) $s['on_hand'] == 0.0) {
                continue;
            }
            $end = (float) $s['on_hand'] - (float) ($after[$s['id']] ?? 0);
            $in = (float) ($r['inn'] ?? 0);
            $sale = (float) ($r['sale'] ?? 0);
            $waste = (float) ($r['waste'] ?? 0);
            $cnt = (float) ($r['cnt'] ?? 0);
            $rows[] = ['id' => $s['id'], 'name' => $s['name'], 'unit' => Stock::unitLabel($s['unit']), 'category' => (string) $s['category'],
                'start' => round($end - $in + $sale + $waste - $cnt, 3), 'in' => round($in, 3), 'sale' => round($sale, 3), 'waste' => round($waste, 3), 'count' => round($cnt, 3),
                'end' => round($end, 3), 'on_hand' => round((float) $s['on_hand'], 3), 'cost' => (float) $s['avg_cost'],
                'value' => (int) round($end * (float) $s['avg_cost']), 'state' => $s['state']];
        }
        usort($rows, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
        return $rows;
    }

    /** Money figures of the period: purchases, cost of sales, waste, count difference, stock value at the end. */
    public static function totals(int $from, int $to): array
    {
        $fromDay = date('Y-m-d', intdiv($from, 1000));
        $toDay = date('Y-m-d', intdiv($to - 1, 1000));
        $pur = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS t, COALESCE(SUM(vat), 0) AS v FROM stock_docs WHERE kind = 'purchase' AND day >= ? AND day <= ?", [$fromDay, $toDay]);
        $count = (int) round((float) Db::value("SELECT COALESCE(SUM(qty * unit_cost), 0) FROM stock_moves WHERE reason = 'count' AND at >= ? AND at < ?", [$from, $to]));
        return ['purchases' => (int) $pur['t'], 'purchases_n' => (int) $pur['n'], 'purchases_vat' => (int) $pur['v'], 'cost' => Reports::costOfSales($from, $to),
            'waste' => Reports::waste($from, $to), 'count' => $count, 'value' => array_sum(array_column(self::moves($from, $to), 'value'))];
    }
}
