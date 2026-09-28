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
        return $best;
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

    /** "%20 · Pizzalar · Sal Per · 17:00–19:00" for lists. */
    public static function summary(array $p): string
    {
        $parts = ['%' . I18n::numAuto((float) $p['pct'])];
        $targets = json_arr($p['targets']);
        if ($p['scope'] === 'categories' && $targets) {
            $names = Db::pairs('SELECT id, names FROM categories WHERE id IN (' . Db::in($targets) . ')', $targets);
            $parts[] = implode(', ', array_map(static fn(string $n): string => tn($n), $names));
        } elseif ($p['scope'] === 'items' && $targets) {
            $parts[] = I18n::t('promo.n_items', ['n' => count($targets)]);
        } else {
            $parts[] = I18n::t('promo.all_menu');
        }
        $days = array_map('intval', json_arr($p['days']));
        $parts[] = count($days) === 7 ? I18n::t('promo.every_day') : implode(' ', array_map(static fn(int $d): string => I18n::t('promo.d' . $d), $days));
        if ($p['time_from'] && $p['time_to']) {
            $parts[] = $p['time_from'] . '–' . $p['time_to'];
        }
        return implode(' · ', $parts);
    }
}
