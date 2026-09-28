<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Menu;

use Sofrexa\Core\{Audit, Clock, Db, I18n, ValidationError};

/**
 * Time-based promotions (stage 13): a percentage off some dishes on chosen days and hours, for chosen channels.
 * Orders take the promotion that applies when a dish is added (the best one when several do); the line keeps
 * its menu price in list_price and the promotion in promo_id. Promoted prices are rounded to whole lira.
 */
final class Promotions
{
    public const CHANNELS = ['table', 'takeaway', 'delivery', 'online'];

    private static ?array $cache = null;

    public static function all(): array
    {
        return Db::rows('SELECT * FROM promotions WHERE deleted = 0 ORDER BY active DESC, sort, created_at, rowid');
    }

    public static function get(string $id): array
    {
        $p = Db::row('SELECT * FROM promotions WHERE id = ? AND deleted = 0', [$id]);
        if (!$p) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        return $p;
    }

    /**
     * $in: id?, names[tr…] or name, pct, scope, targets[], days[], time_from, time_to, date_from, date_to, channels[], active.
     */
    public static function save(array $in): string
    {
        $names = Menu::langsIn($in['names'] ?? ['tr' => $in['name'] ?? '']);
        $pct = (float) str_replace(',', '.', (string) ($in['pct'] ?? '0'));
        $scope = in_array($in['scope'] ?? '', ['all', 'categories', 'items'], true) ? $in['scope'] : 'all';
        $targets = $scope === 'all' ? [] : array_values(array_unique(array_filter(array_map('strval', (array) ($in['targets'] ?? [])))));
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), static fn(int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        $channels = array_values(array_intersect(self::CHANNELS, array_map('strval', (array) ($in['channels'] ?? []))));
        $time = static fn(string $k): ?string => preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', trim((string) ($in[$k] ?? '')), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        $date = static fn(string $k): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) ? (string) $in[$k] : null;
        $err = [];
        if (($names['tr'] ?? '') === '') {
            $err['names[tr]'] = I18n::t('promo.err_name');
        }
        if ($pct < 1 || $pct > 90) {
            $err['pct'] = I18n::t('promo.err_pct');
        }
        if ($scope !== 'all' && !$targets) {
            $err['targets'] = I18n::t('promo.err_targets');
        }
        if (!$days) {
            $err['days'] = I18n::t('promo.err_days');
        }
        if (!$channels) {
            $err['channels'] = I18n::t('promo.err_channels');
        }
        $from = $time('time_from');
        $to = $time('time_to');
        if (($from === null) !== ($to === null) || ($from !== null && $from === $to)) {
            $err['time_to'] = I18n::t('promo.err_time');
        }
        $dFrom = $date('date_from');
        $dTo = $date('date_to');
        if ($dFrom && $dTo && $dTo < $dFrom) {
            $err['date_to'] = I18n::t('promo.err_dates');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        $row = ['name' => $names['tr'], 'names' => $names, 'pct' => round($pct, 1), 'scope' => $scope, 'targets' => $targets, 'days' => $days,
            'time_from' => $from, 'time_to' => $to, 'date_from' => $dFrom, 'date_to' => $dTo, 'channels' => $channels,
            'active' => !isset($in['active']) || !empty($in['active']) ? 1 : 0];
        $id = (string) ($in['id'] ?? '');
        if ($id !== '') {
            self::get($id);
            $row['id'] = $id;
        } else {
            $row['created_at'] = Clock::ms();
        }
        $id = Db::save('promotions', $row);
        self::$cache = null;
        Audit::log('menu.promo', $names['tr'] . ' · %' . $row['pct'], 'promotion', $id);
        return $id;
    }

    public static function toggle(string $id, bool $on): void
    {
        $p = self::get($id);
        Db::save('promotions', ['id' => $id, 'active' => $on ? 1 : 0]);
        self::$cache = null;
        Audit::log('menu.promo', $p['name'] . ($on ? ' · açık' : ' · kapalı'), 'promotion', $id);
    }

    public static function delete(string $id): void
    {
        $p = self::get($id);
        Db::softDelete('promotions', $id);
        self::$cache = null;
        Audit::log('menu.promo', $p['name'] . ' · silindi', 'promotion', $id);
    }

    // ------------------------------------------------------------ when and where

    /** Local wall clock at $ms: [Y-m-d, weekday 1–7, minute of the day]. */
    private static function clock(int $ms): array
    {
        $t = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return [$t->format('Y-m-d'), (int) $t->format('N'), (int) $t->format('G') * 60 + (int) $t->format('i')];
    }

    private static function minutes(?string $hhmm): ?int
    {
        return $hhmm ? (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2) : null;
    }

    /** Whether a promotion runs at $ms (ignoring dishes and channels). */
    public static function runs(array $p, ?int $ms = null): bool
    {
        if (!(int) $p['active']) {
            return false;
        }
        $ms ??= Clock::ms();
        [$date, $dow, $min] = self::clock($ms);
        $days = array_map('intval', json_arr(is_array($p['days']) ? json_encode($p['days']) : $p['days']));
        $from = self::minutes($p['time_from']);
        $to = self::minutes($p['time_to']);
        // the day that counts is the one the window started on (a 22:00–02:00 window at 01:00 belongs to yesterday)
        $startDate = $date;
        $startDow = $dow;
        if ($from !== null && $to !== null && $from > $to && $min < $to) {
            $startDate = date('Y-m-d', strtotime($date . ' -1 day'));
            $startDow = $dow === 1 ? 7 : $dow - 1;
        } elseif ($from !== null && $to !== null && !($from <= $to ? ($min >= $from && $min < $to) : ($min >= $from))) {
            return false;
        }
        if (!in_array($startDow, $days, true)) {
            return false;
        }
        return (!$p['date_from'] || $startDate >= $p['date_from']) && (!$p['date_to'] || $startDate <= $p['date_to']);
    }

    /** now (running) · planned (active, not running now) · ended (its last date passed) · off (switched off). */
    public static function state(array $p, ?int $ms = null): string
    {
        if (!(int) $p['active']) {
            return 'off';
        }
        [$date] = self::clock($ms ?? Clock::ms());
        if ($p['date_to'] && $date > $p['date_to']) {
            return 'ended';
        }
        return self::runs($p, $ms) ? 'now' : 'planned';
    }

    /** table | takeaway | delivery | online for an order channel (QR orders count as table). */
    public static function channelOf(string $orderChannel): string
    {
        return match ($orderChannel) {
            'qr', 'table' => 'table',
            'takeaway' => 'takeaway',
            'delivery' => 'delivery',
            default => 'online',
        };
    }

    /** The best promotion for a dish (needs id and category_id) on a channel at $ms, or null. */
    public static function best(array $item, string $channel, ?int $ms = null): ?array
    {
        $list = (int) ($item['price'] ?? 0);
        if ($list <= 0) {
            return null; // priced by its options only: nothing to lower
        }
        $ms ??= Clock::ms();
        if (self::$cache === null || self::$cache['ms'] !== intdiv($ms, 60_000)) {
            $running = [];
            foreach (Db::rows('SELECT * FROM promotions WHERE deleted = 0 AND active = 1') as $p) {
                if (self::runs($p, $ms)) {
                    $p['targets_a'] = json_arr($p['targets']);
                    $p['channels_a'] = json_arr($p['channels']);
                    $running[] = $p;
                }
            }
            self::$cache = ['ms' => intdiv($ms, 60_000), 'running' => $running];
        }
        $best = null;
        foreach (self::$cache['running'] as $p) {
            if (!in_array($channel, $p['channels_a'], true)) {
                continue;
            }
            $hit = match ($p['scope']) {
                'categories' => in_array((string) ($item['category_id'] ?? ''), $p['targets_a'], true),
                'items' => in_array((string) ($item['id'] ?? ''), $p['targets_a'], true),
                default => true,
            };
            if ($hit && (!$best || (float) $p['pct'] > (float) $best['pct'])) {
                $best = $p;
            }
        }
        // a small percent on a cheap dish can round back to the same lira
        return $best && self::price($list, (float) $best['pct']) < $list ? $best : null;
    }

    /** ₺650 at %20 → ₺520: whole lira. */
    public static function price(int $kurus, float $pct): int
    {
        return (int) (round($kurus * (1 - $pct / 100) / 100) * 100);
    }

    /** Forget what is running (tests and after edits). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** What and when for lists: [what "%20 · Pizzalar", when "Pzt–Cum · 17:00–19:00"] (Figma PR1/PR3). */
    public static function parts(array $p): array
    {
        $what = '%' . I18n::numAuto((float) $p['pct']);
        $targets = json_arr($p['targets']);
        if ($p['scope'] === 'categories' && $targets) {
            $names = Db::pairs('SELECT id, names FROM categories WHERE id IN (' . Db::in($targets) . ')', $targets);
            $what .= ' · ' . implode(', ', array_map(static fn(string $n): string => tn($n), $names));
        } elseif ($p['scope'] === 'items' && $targets) {
            $what .= ' · ' . I18n::t('promo.n_items', ['n' => digits(count($targets))]);
        } else {
            $what .= ' · ' . I18n::t('promo.all_menu');
        }
        // a single-day date range stands for the days ("29 Eki")
        if ($p['date_from'] && $p['date_from'] === $p['date_to']) {
            $ts = strtotime($p['date_from']);
            $days = digits(date('j', $ts)) . ' ' . mb_substr(I18n::t('date.m' . date('n', $ts)), 0, 3);
        } else {
            $days = self::daysText(array_map('intval', json_arr($p['days'])));
        }
        $time = $p['time_from'] && $p['time_to'] ? digits($p['time_from'] . '–' . $p['time_to']) : I18n::t('promo.all_day');
        return [$what, $days . ' · ' . $time];
    }

    /** "%20 · Odun Fırınında Pizza · Pzt–Cum · 17:00–19:00" */
    public static function summary(array $p): string
    {
        return implode(' · ', self::parts($p));
    }

    // ------------------------------------------------------------ lists and the editor (Figma PR1–PR4)

    /** Local time of today at $minute (or $days later). */
    private static function at(int $ms, int $days, int $minute): int
    {
        $t = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0)->modify('+' . $days . ' days')->modify('+' . $minute . ' minutes');
        return $t->getTimestamp() * 1000;
    }

    /** When a running promotion ends (null: at the end of the day) — for "19:00'a kadar". */
    public static function endsAt(array $p, ?int $ms = null): ?int
    {
        $ms ??= Clock::ms();
        $to = self::minutes($p['time_to']);
        if ($to === null) {
            return null;
        }
        [, , $min] = self::clock($ms);
        return self::at($ms, $min < $to ? 0 : 1, $to);
    }

    /** Next start of a promotion that is not running now, within a year, or null. */
    public static function nextStart(array $p, ?int $ms = null): ?int
    {
        $ms ??= Clock::ms();
        $from = self::minutes($p['time_from']) ?? 0;
        $days = array_map('intval', json_arr($p['days']));
        for ($d = 0; $d <= 370; $d++) {
            $t = self::at($ms, $d, $from);
            [$date, $dow] = self::clock($t);
            if ($t <= $ms || !in_array($dow, $days, true) || ($p['date_from'] && $date < $p['date_from'])) {
                continue;
            }
            return $p['date_to'] && $date > $p['date_to'] ? null : $t;
        }
        return null;
    }

    /** Turkish dative after a time, as it is read: 19:00'a, 17:00'ye, 15:00'e, 12:30'a. */
    public static function trTimeSuffix(string $hhmm): string
    {
        $units = [1 => 'e', 2 => 'ye', 3 => 'e', 4 => 'e', 5 => 'e', 6 => 'ya', 7 => 'ye', 8 => 'e', 9 => 'a'];
        $tens = [1 => 'a', 2 => 'ye', 3 => 'a', 4 => 'a', 5 => 'ye'];
        $n = substr($hhmm, 3, 2) === '00' ? (int) substr($hhmm, 0, 2) : (int) substr($hhmm, 3, 2);
        return $n === 0 ? 'a' : ($n % 10 ? $units[$n % 10] : $tens[intdiv($n, 10)]);
    }

    /** Status hint of PR1/PR3: "19:00'a kadar", "Yarın 12:00", "Cumartesi", "29 Ekim'de", "Elle kapatıldı". */
    public static function hint(array $p, ?int $ms = null): string
    {
        $ms ??= Clock::ms();
        $lang = I18n::lang();
        switch (self::state($p, $ms)) {
            case 'off':
                return I18n::t('promo.h_off');
            case 'ended':
                return I18n::t('promo.h_ended');
            case 'now':
                $end = self::endsAt($p, $ms);
                if ($end === null) {
                    return I18n::t('promo.h_day_end');
                }
                $hm = date('H:i', intdiv($end, 1000));
                return I18n::t('promo.h_until', ['t' => digits($hm), 'sfx' => $lang === 'tr' ? '’' . self::trTimeSuffix($hm) : '']);
        }
        $next = self::nextStart($p, $ms);
        if ($next === null) {
            return I18n::t('promo.h_ended');
        }
        $hm = digits(date('H:i', intdiv($next, 1000)));
        $days = (int) round((self::at($next, 0, 0) - self::at($ms, 0, 0)) / 86_400_000);
        return match (true) {
            $days === 0 => I18n::t('promo.h_today', ['t' => $hm]),
            $days === 1 => I18n::t('promo.h_tomorrow', ['t' => $hm]),
            $days < 7 => I18n::t('date.d' . date('w', intdiv($next, 1000))),
            default => I18n::t('promo.h_on', ['d' => digits(date('j', intdiv($next, 1000))), 'month' => I18n::t('date.m' . date('n', intdiv($next, 1000))),
                'sfx' => $lang === 'tr' ? '’' . self::TR_MONTH_LOC[(int) date('n', intdiv($next, 1000))] : '']),
        };
    }

    private const TR_MONTH_LOC = [1 => 'ta', 'ta', 'ta', 'da', 'ta', 'da', 'da', 'ta', 'de', 'de', 'da', 'ta'];

    /** "Pzt–Cum", "Cmt, Paz", "her gün". */
    public static function daysText(array $days): string
    {
        sort($days);
        if (count($days) === 7) {
            return mb_strtolower(I18n::t('promo.every_day'), 'UTF-8');
        }
        $run = $days && $days === range($days[0], end($days)) && count($days) > 2;
        return $run ? I18n::t('promo.d' . $days[0]) . '–' . I18n::t('promo.d' . end($days)) : implode(', ', array_map(static fn(int $d): string => I18n::t('promo.d' . $d), $days));
    }

    /** "Masa · Gel-al · Online" or "Tüm kanallar". */
    public static function channelsText(array $channels): string
    {
        return count($channels) === count(self::CHANNELS) ? I18n::t('promo.ch_all') : implode(' · ', array_map(static fn(string $c): string => I18n::t('promo.ch_' . $c), $channels));
    }

    /**
     * Dishes a promotion (or the editor's current values) applies to, in menu order: [{id, name, price, new}].
     * $in has scope, targets and pct like save().
     */
    public static function affected(array $in): array
    {
        $scope = (string) ($in['scope'] ?? 'all');
        $targets = array_map('strval', is_array($in['targets'] ?? null) ? $in['targets'] : json_arr((string) ($in['targets'] ?? '[]')));
        $pct = (float) str_replace(',', '.', (string) ($in['pct'] ?? 0));
        $out = [];
        foreach (Db::rows('SELECT i.id, i.names, i.price, i.category_id FROM items i JOIN categories c ON c.id = i.category_id
            WHERE i.deleted = 0 AND i.active = 1 AND c.deleted = 0 ORDER BY c.sort, i.sort, i.id') as $i) {
            $hit = match ($scope) {
                'categories' => in_array($i['category_id'], $targets, true),
                'items' => in_array($i['id'], $targets, true),
                default => true,
            };
            if ($hit && (int) $i['price'] > 0) {
                $out[] = ['id' => $i['id'], 'name' => tn($i['names']), 'price' => (int) $i['price'], 'new' => $pct > 0 ? self::price((int) $i['price'], $pct) : (int) $i['price']];
            }
        }
        return $out;
    }

    /** The PR1 stat cards: running now, dishes discounted now, planned (and the next), discount given this week. */
    public static function stats(?int $ms = null): array
    {
        $ms ??= Clock::ms();
        $now = [];
        $planned = [];
        foreach (self::all() as $p) {
            $st = self::state($p, $ms);
            if ($st === 'now') {
                $now[] = $p;
            } elseif ($st === 'planned' && ($n = self::nextStart($p, $ms)) !== null) {
                $planned[] = $p + ['next' => $n];
            }
        }
        usort($planned, static fn(array $a, array $b): int => $a['next'] <=> $b['next']);
        $items = [];
        foreach ($now as $p) {
            foreach (self::affected($p) as $i) {
                $items[$i['id']] = $i;
            }
        }
        $week = Clock::ms() - 7 * 86_400_000;
        $given = Db::row("SELECT COALESCE(SUM(ROUND(l.qty * (l.list_price - l.unit_price))), 0) AS amount, COUNT(DISTINCT l.order_id) AS orders
            FROM order_items l JOIN orders o ON o.id = l.order_id WHERE l.promo_id IS NOT NULL AND l.list_price IS NOT NULL AND l.deleted = 0
              AND l.status <> 'void' AND o.status <> 'void' AND l.created_at >= ?", [$week]);
        $cats = [];
        foreach ($now as $p) {
            if ($p['scope'] === 'categories') {
                foreach (json_arr($p['targets']) as $c) {
                    $cats[$c] = true;
                }
            }
        }
        $catNames = $cats ? array_map(static fn(string $n): string => tn($n), Db::pairs('SELECT id, names FROM categories WHERE id IN (' . Db::in(array_keys($cats)) . ')', array_keys($cats))) : [];
        return ['now' => $now, 'planned' => $planned, 'items' => count($items), 'items_label' => implode(', ', $catNames),
            'given' => (int) $given['amount'], 'orders' => (int) $given['orders']];
    }

    /** Promotion shown on an order line: [name, percent, saving] or null (lines keep the menu price in list_price). */
    public static function onLine(array $l): ?array
    {
        if (empty($l['promo_id']) || empty($l['list_price']) || (int) $l['list_price'] <= (int) $l['unit_price']) {
            return null;
        }
        static $names = [];
        $names[$l['promo_id']] ??= (string) (Db::value('SELECT names FROM promotions WHERE id = ?', [$l['promo_id']]) ?? '');
        $pct = (int) round((1 - (int) $l['unit_price'] / (int) $l['list_price']) * 100);
        return ['name' => tn($names[$l['promo_id']]) ?: 'Promosyon', 'name_tr' => tn($names[$l['promo_id']], 'tr') ?: 'Promosyon', 'pct' => $pct,
            'saving' => (int) round((float) $l['qty'] * ((int) $l['list_price'] - (int) $l['unit_price']))];
    }
}
