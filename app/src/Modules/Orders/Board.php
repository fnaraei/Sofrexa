<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Clock, Db};
use Sofrexa\Modules\Menu\Floor;

/**
 * The table map (W1, W8, W9, W11): every table with the state of its open order.
 * States as on the Figma TableTile: free, occupied, bill, qr, ready, late (> 20 min in the kitchen).
 */
final class Board
{
    public const LATE_MINUTES = 20;

    /** Areas with their tables, each table carrying 'order' (open order row or null), 'state', 'info', 'amount'. */
    public static function areas(): array
    {
        $open = [];
        $qr = [];
        foreach (Orders::open(['table', 'qr']) as $o) {
            // a guest's QR order waiting for approval is not part of the bill yet: the tile asks for approval (W5)
            if ($o['channel'] === 'qr' && $o['status'] === 'pending') {
                $qr[$o['table_id']] ??= $o;
                continue;
            }
            // a split bill shares the table: show the main one, add the parts' totals
            if (isset($open[$o['table_id']])) {
                $open[$o['table_id']]['total'] += (int) $o['total'];
                $open[$o['table_id']]['parts'][] = $o['id'];
                continue;
            }
            $o['parts'] = [];
            $open[$o['table_id']] = $o;
        }
        $now = Clock::ms();
        $areas = Floor::areas();
        foreach ($areas as &$a) {
            $a['busy'] = 0;
            foreach ($a['tables'] as &$t) {
                $o = $open[$t['id']] ?? null;
                $t['qr'] = $qr[$t['id']] ?? null;
                $t['order'] = $o ?? $t['qr'];
                $o = $t['order'];
                [$t['state'], $t['info'], $t['info_s'], $t['amount']] = self::tile($o, $now, $t['qr']);
                if ($o) {
                    $a['busy']++;
                }
            }
            unset($t);
            $a['icon'] = self::areaIcon($a);
        }
        return $areas;
    }

    /** [state, info text, short info (desktop), amount text] for a table. */
    public static function tile(?array $o, int $now, ?array $qr = null): array
    {
        if ($qr || ($o && $o['status'] === 'pending')) {
            $q = $qr ?? $o;
            return ['qr', t('tables.qr_wait'), t('tables.qr_wait_s'), t('tables.items', ['n' => digits((int) $q['line_count'])])];
        }
        if (!$o) {
            return ['free', t('tables.free'), t('tables.free'), ''];
        }
        $amount = money((int) $o['total']);
        if ($o['bill_at'] || $o['status'] === 'billed') {
            return ['bill', t('tables.bill_asked'), t('tables.bill_asked'), $amount];
        }
        if ((int) $o['ready'] > 0) {
            return ['ready', t('tables.ready'), t('tables.ready'), $amount];
        }
        if ($o['oldest_sent'] && $now - (int) $o['oldest_sent'] > self::LATE_MINUTES * 60_000) {
            $late = t('tables.late', ['t' => dur((int) $o['oldest_sent'], $now)]);
            return ['late', $late, $late, $amount];
        }
        $info = t('tables.guests_time', ['g' => digits(max(1, (int) $o['guests'])), 't' => dur((int) $o['opened_at'], $now)]);
        return ['occupied', $info, $info, $amount];
    }

    /** Area icon on the desktop map: leaf for a garden or terrace, smoking, utensils otherwise. */
    public static function areaIcon(array $a): string
    {
        if (!empty($a['smoking'])) {
            return 'smoking';
        }
        $names = mb_strtolower($a['name'] . ' ' . implode(' ', json_arr($a['names'] ?? '{}')), 'UTF-8');
        foreach (['bahçe', 'garden', 'teras', 'terrace', 'باغ', 'حیاط', 'сад', 'террас'] as $w) {
            if (str_contains($names, $w)) {
                return 'leaf';
            }
        }
        return 'utensils';
    }

    /** Filter counts for the chips: mine, free, busy, bill. */
    public static function counts(array $areas, ?string $areaId = null): array
    {
        $me = Auth::user()['id'] ?? '';
        $c = ['mine' => 0, 'free' => 0, 'busy' => 0, 'bill' => 0, 'all' => 0];
        foreach ($areas as $a) {
            if ($areaId && $a['id'] !== $areaId) {
                continue;
            }
            foreach ($a['tables'] as $t) {
                $c['all']++;
                if (!$t['order']) {
                    $c['free']++;
                    continue;
                }
                $c['busy']++;
                if ($t['state'] === 'bill') {
                    $c['bill']++;
                }
                if (($t['order']['waiter_id'] ?? '') === $me) {
                    $c['mine']++;
                }
            }
        }
        return $c;
    }

    public static function matches(array $t, string $filter): bool
    {
        return match ($filter) {
            'mine' => $t['order'] && ($t['order']['waiter_id'] ?? '') === (Auth::user()['id'] ?? ''),
            'free' => !$t['order'],
            'busy' => (bool) $t['order'],
            'bill' => $t['state'] === 'bill',
            default => true,
        };
    }

    /** Average stay of the occupied tables, for the desktop subtitle. */
    public static function averageStay(array $areas): string
    {
        $sum = 0;
        $n = 0;
        foreach ($areas as $a) {
            foreach ($a['tables'] as $t) {
                if ($t['order']) {
                    $sum += Clock::ms() - (int) $t['order']['opened_at'];
                    $n++;
                }
            }
        }
        return $n ? dur(Clock::ms() - intdiv($sum, $n)) : t('dur.min', ['m' => digits(0)]);
    }

    /** "Masa 12 · Bahçe"-style name for any order, in the viewer's language. */
    public static function title(array $o, bool $withArea = true): string
    {
        return match ($o['channel']) {
            'table', 'qr' => $withArea && !empty($o['area_name'])
                ? t('order.table_area', ['n' => $o['table_no'], 'area' => tn(json_arr($o['area_names'] ?? '{}') ?: $o['area_name'])])
                : t('order.table', ['n' => $o['table_no']]),
            'takeaway' => t('order.takeaway', ['no' => digits((int) $o['no'])]),
            'delivery' => t('order.delivery', ['no' => digits(sprintf('%04d', (int) $o['no']))]),
            default => t('order.online', ['no' => digits(sprintf('%04d', (int) $o['no']))]),
        };
    }

    public static function areaName(array $o): string
    {
        return !empty($o['area_name']) ? tn(json_arr($o['area_names'] ?? '{}') ?: $o['area_name']) : '';
    }

    /** Line status for the OrderLine component: [class, icon, label, tone]. */
    public static function lineStatus(array $l): array
    {
        return match ($l['status']) {
            'new' => ['new', null, t('order.st_new'), 'accent'],
            'ready' => ['ready', 'bell', t('order.st_ready'), 'success'],
            'served' => ['served', 'check-circle', t('order.st_served'), 'muted'],
            'void' => ['void', 'x-circle', t('order.st_void', ['reason' => $l['void_reason'] ?: '—']), 'danger'],
            default => ['sent', 'check', $l['station'] === 'bar' ? t('order.st_bar') : t('order.st_sent'), 'info'],
        };
    }

    /** Options and note of a line as one muted line: "Az pişmiş · soğansız · Sos ayrı". */
    public static function lineMods(array $l): string
    {
        $parts = array_map(static fn(array $m): string => (string) $m['name'], json_arr(is_string($l['mods']) ? $l['mods'] : json_encode($l['mods'])));
        if (!empty($l['note'])) {
            $parts[] = $l['note'];
        }
        return implode(' · ', $parts);
    }

    public static function lineTotal(array $l): int
    {
        return (int) round((float) $l['qty'] * ((int) $l['unit_price'] + (int) $l['mods_price']));
    }

    public static function qty(float $q): string
    {
        return digits(Orders::qtyText($q)) . '×';
    }

    /** Waiters and cashiers who can take a table (for "Garson"). */
    public static function staff(): array
    {
        return Db::rows("SELECT u.id, u.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.active = 1 AND u.deleted = 0 AND r.code IN ('waiter', 'cashier', 'manager') ORDER BY u.name");
    }
}
