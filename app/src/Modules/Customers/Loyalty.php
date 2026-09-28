<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Customers;

use Sofrexa\Core\{Audit, Auth, Clock, Db, HttpError, I18n, Money, Settings, ValidationError};
use Sofrexa\Modules\Orders\Orders;

/**
 * Loyalty (CU5, CU7, C12). Points live in loyalty_ledger (append-only): earn, redeem, refund, expire, adjust.
 * A tier (Bronz, Gümüş, Altın …) gives a discount and an earn rate (% of the paid amount); the tier follows the
 * customer's spending over the last months unless the manager set it by hand for that customer. The point value,
 * the rules and the tiers are settings the manager edits (CU5).
 */
final class Loyalty
{
    public const TONES = ['Neutral', 'Accent', 'Solid', 'Info', 'Success', 'Warning'];

    /** Reasons of the discounts the system adds itself when a customer is put on a bill. */
    private const AUTO = ['seviye:', 'müşteri:'];

    public static function enabled(): bool
    {
        return (bool) Settings::get('loyalty.enabled', true);
    }

    /** Kuruş one point is worth when redeemed. */
    public static function pointValue(): int
    {
        return max(1, (int) Settings::get('loyalty.point_value', 100));
    }

    public static function tiers(): array
    {
        return Db::rows('SELECT * FROM tiers WHERE deleted = 0 ORDER BY threshold, sort');
    }

    public static function tier(string $id): array
    {
        return Db::row('SELECT * FROM tiers WHERE id = ? AND deleted = 0', [$id]) ?? throw new HttpError(404);
    }

    /** Badge tone of a tier (Figma: Bronz neutral, Gümüş accent, Altın solid). */
    public static function tone(?array $tier): string
    {
        return strtolower((string) ($tier['tone'] ?? 'Neutral'));
    }

    public static function saveTier(array $in, bool $refresh = true): string
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ValidationError(['name' => I18n::t('loy.err_tier_name')]);
        }
        $row = [
            'name' => mb_substr($name, 0, 40),
            'threshold' => max(0, Money::parse((string) ($in['threshold'] ?? 0))),
            'discount_pct' => self::pct($in['discount_pct'] ?? 0),
            'earn_pct' => self::pct($in['earn_pct'] ?? 0),
            'tone' => in_array($in['tone'] ?? '', self::TONES, true) ? $in['tone'] : 'Neutral',
        ];
        $old = ($in['id'] ?? '') !== '' ? self::tier($in['id']) : null;
        if ($old && $old['name'] === $row['name'] && (int) $old['threshold'] === $row['threshold'] && abs((float) $old['discount_pct'] - $row['discount_pct']) < 0.001
            && abs((float) $old['earn_pct'] - $row['earn_pct']) < 0.001 && $old['tone'] === $row['tone']) {
            return $old['id'];
        }
        $id = Db::save('tiers', ($old ? ['id' => $old['id']] : ['sort' => ((int) Db::value('SELECT MAX(sort) FROM tiers')) + 10]) + $row);
        Audit::log('loyalty.tier', $row['name'] . ' · %' . I18n::num($row['discount_pct'], 1, 'tr') . ' indirim · %' . I18n::num($row['earn_pct'], 1, 'tr') . ' puan · eşik ' . Money::fmt($row['threshold'], false, 'tr'), 'tier', $id);
        if ($refresh) {
            self::refreshAll();
        }
        return $id;
    }

    /** Removes a tier; its customers fall back to the automatic rule. The last tier stays. */
    public static function deleteTier(string $id): void
    {
        $t = self::tier($id);
        if (count(self::tiers()) <= 1) {
            throw new \InvalidArgumentException(I18n::t('loy.err_last_tier'));
        }
        Db::softDelete('tiers', $id);
        foreach (Db::rows('SELECT id FROM customers WHERE tier_id = ? AND deleted = 0', [$id]) as $c) {
            Db::save('customers', ['id' => $c['id'], 'tier_id' => null, 'tier_manual' => 0, 'tier_note' => null]);
        }
        Audit::log('loyalty.tier_delete', $t['name'], 'tier', $id);
        self::refreshAll();
    }

    /**
     * The whole CU5 form: rules (point value in lira, tier window, minimum use, expiry, three switches)
     * and the tier table (existing rows by id, new rows under "new").
     */
    public static function saveProgram(array $in): void
    {
        $months = static fn($v, array $allowed, int $def): int => in_array((int) $v, $allowed, true) ? (int) $v : $def;
        $settings = [
            'loyalty.point_value' => max(1, Money::parse((string) ($in['point_value'] ?? '1'))),
            'loyalty.tier_window_months' => $months($in['tier_window'] ?? 12, [3, 6, 12, 24], 12),
            'loyalty.min_redeem' => max(0, (int) preg_replace('/\D+/', '', (string) ($in['min_redeem'] ?? '0'))),
            'loyalty.expiry_months' => $months($in['expiry'] ?? 12, [0, 6, 12, 24, 36], 12),
            'loyalty.no_points_on_discounted' => !empty($in['no_points_on_discounted']),
            'loyalty.points_on_delivery' => !empty($in['points_on_delivery']),
            'loyalty.no_points_on_account' => !empty($in['no_points_on_account']),
        ];
        $changed = array_filter($settings, static fn($v, string $k): bool => Settings::get($k) !== $v, ARRAY_FILTER_USE_BOTH);
        Db::tx(static function () use ($settings, $in): void {
            Settings::setMany($settings);
            foreach ((array) ($in['tiers'] ?? []) as $id => $t) {
                if (is_array($t)) {
                    $old = self::tier((string) $id);
                    self::saveTier(['id' => $old['id'], 'name' => $old['name'], 'tone' => $old['tone']] + $t, false);
                }
            }
            foreach ((array) ($in['new'] ?? []) as $t) {
                if (is_array($t) && trim((string) ($t['name'] ?? '')) !== '') {
                    self::saveTier($t, false);
                }
            }
        });
        if ($changed) {
            Audit::log('loyalty.settings', implode(', ', array_map(static fn(string $k): string => substr($k, 8) . '=' . json_encode($settings[$k]), array_keys($changed))));
        }
        self::refreshAll();
    }

    private static function pct(mixed $v): float
    {
        // "%3", "3,5", "۳٫۵"
        $s = strtr((string) $v, ['٫' => ',', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        return round(max(0, min(100, (float) str_replace(',', '.', preg_replace('/[^\d,.]/', '', $s) ?? ''))), 2);
    }

    // ------------------------------------------------------------ balance and tier

    public static function balance(string $customerId): int
    {
        return (int) Db::value('SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE customer_id = ?', [$customerId]);
    }

    /** Start (ms) of the tier window ("Son 12 ay"). */
    public static function windowStart(): int
    {
        return (int) strtotime('-' . (int) Settings::get('loyalty.tier_window_months', 12) . ' months') * 1000;
    }

    /** Spending in the tier window (kuruş, paid bills). */
    public static function spend(string $customerId): int
    {
        return (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE customer_id = ? AND status = 'paid' AND closed_at >= ? AND deleted = 0", [$customerId, self::windowStart()]);
    }

    /** The tier the spending earns (the highest threshold reached). */
    public static function earnedTier(string $customerId, ?int $spend = null): ?array
    {
        $spend ??= self::spend($customerId);
        $best = null;
        foreach (self::tiers() as $t) {
            if ($spend >= (int) $t['threshold']) {
                $best = $t;
            }
        }
        return $best;
    }

    public static function tierOf(string $customerId): ?array
    {
        $c = Db::row('SELECT tier_id FROM customers WHERE id = ?', [$customerId]);
        return $c && $c['tier_id'] ? Db::row('SELECT * FROM tiers WHERE id = ? AND deleted = 0', [$c['tier_id']]) : null;
    }

    /** Keeps an automatic tier up to date (a hand-set tier stays). */
    public static function refreshTier(string $customerId): void
    {
        $c = Db::row('SELECT tier_id, tier_manual FROM customers WHERE id = ?', [$customerId]);
        if (!$c || (int) $c['tier_manual'] === 1) {
            return;
        }
        $t = self::earnedTier($customerId);
        if (($t['id'] ?? null) !== $c['tier_id']) {
            Db::save('customers', ['id' => $customerId, 'tier_id' => $t['id'] ?? null]);
        }
    }

    /** Recomputes every automatic tier (after a tier change and every night). Returns how many changed. */
    public static function refreshAll(): int
    {
        $tiers = self::tiers();
        $spend = Db::pairs("SELECT customer_id, SUM(total) FROM orders WHERE customer_id IS NOT NULL AND status = 'paid' AND closed_at >= ? AND deleted = 0 GROUP BY customer_id", [self::windowStart()]);
        $n = 0;
        foreach (Db::rows('SELECT id, tier_id FROM customers WHERE deleted = 0 AND tier_manual = 0') as $c) {
            $best = null;
            foreach ($tiers as $t) {
                if ((int) ($spend[$c['id']] ?? 0) >= (int) $t['threshold']) {
                    $best = $t['id'];
                }
            }
            if ($best !== $c['tier_id']) {
                Db::save('customers', ['id' => $c['id'], 'tier_id' => $best]);
                $n++;
            }
        }
        return $n;
    }

    /** Manager sets the tier by hand (CU7), or gives it back to the automatic rule ($auto). */
    public static function setTier(string $customerId, ?string $tierId, bool $auto, string $note = ''): void
    {
        $c = Customers::find($customerId);
        if ($tierId) {
            self::tier($tierId);
        }
        if ($auto) {
            Db::save('customers', ['id' => $customerId, 'tier_manual' => 0, 'tier_note' => mb_substr(trim($note), 0, 200) ?: null]);
            self::refreshTier($customerId);
        } else {
            Db::save('customers', ['id' => $customerId, 'tier_id' => $tierId ?: null, 'tier_manual' => 1, 'tier_note' => mb_substr(trim($note), 0, 200) ?: null]);
        }
        $t = self::tierOf($customerId);
        Audit::log('customer.tier', $c['name'] . ' → ' . ($t['name'] ?? '—') . ($auto ? ' (otomatik)' : ' (elle)') . ($note !== '' ? ' · ' . $note : ''), 'customer', $customerId);
    }

    /** Discount % of a customer: their own discount or their tier's, whichever is bigger. */
    public static function discountPct(string $customerId): float
    {
        return max(...array_values(self::discountParts($customerId)));
    }

    /** ['own' => %, 'tier' => %] (the tier part only while the customer is in the program). */
    public static function discountParts(string $customerId): array
    {
        $c = Db::row('SELECT discount_pct, loyalty FROM customers WHERE id = ?', [$customerId]);
        $tier = $c && (int) $c['loyalty'] && self::enabled() ? (float) (self::tierOf($customerId)['discount_pct'] ?? 0) : 0.0;
        return ['own' => (float) ($c['discount_pct'] ?? 0), 'tier' => $tier];
    }

    // ------------------------------------------------------------ a customer on a bill

    /**
     * Puts a customer on an open bill (or takes them off with null): points used by the previous customer go back,
     * the automatic discount follows the new customer (own or tier %, the bigger), unless the bill already has a
     * discount given by hand.
     */
    public static function attach(string $orderId, ?string $customerId): void
    {
        $o = Orders::editable($orderId);
        $c = $customerId ? Customers::find($customerId) : null;
        Db::tx(static function () use ($o, $orderId, $customerId, $c): void {
            self::unredeem($orderId);
            self::rebuild($orderId, static fn(array $d): bool => !self::isAuto($d));
            Db::save('orders', ['id' => $orderId, 'customer_id' => $customerId]);
            if ($c && (!in_array($o['channel'], ['takeaway', 'delivery', 'online'], true) || Settings::get('loyalty.points_on_delivery', true)) && !self::activeDiscounts($orderId)) {
                $parts = self::discountParts($c['id']);
                $pct = max($parts['own'], $parts['tier']);
                if ($pct > 0) {
                    $sub = (int) Orders::recalc($orderId)['subtotal'];
                    $reason = $parts['own'] >= $parts['tier'] ? 'müşteri: özel' : 'seviye: ' . (self::tierOf($c['id'])['name'] ?? '');
                    self::rebuild($orderId, static fn(): bool => true, [['kind' => 'pct', 'value' => $pct, 'amount' => (int) round($sub * $pct / 100), 'reason' => $reason]]);
                }
            }
            Orders::recalc($orderId);
        });
        Audit::log('order.customer', Orders::where($o) . ' · ' . ($c['name'] ?? '—'), 'order', $orderId);
    }

    private static function isAuto(array $d): bool
    {
        foreach (self::AUTO as $p) {
            if (str_starts_with((string) $d['reason'], $p)) {
                return true;
            }
        }
        return false;
    }

    /** Discounts in force (since the last reset). */
    public static function activeDiscounts(string $orderId): array
    {
        $active = [];
        foreach (Db::rows('SELECT * FROM order_discounts WHERE order_id = ? ORDER BY at, rowid', [$orderId]) as $d) {
            if ($d['kind'] === 'reverse') {
                $active = [];
                continue;
            }
            $active[] = $d;
        }
        return $active;
    }

    /** Resets the discounts and puts back the ones $keep accepts, plus $add (the table is append-only). */
    private static function rebuild(string $orderId, callable $keep, array $add = []): void
    {
        $active = self::activeDiscounts($orderId);
        $kept = array_values(array_filter($active, $keep));
        if (count($kept) === count($active) && !$add) {
            return;
        }
        $uid = Auth::user()['id'] ?? null;
        if ($active) {
            $sum = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM order_discounts WHERE order_id = ?', [$orderId]);
            Db::append('order_discounts', ['order_id' => $orderId, 'kind' => 'reverse', 'value' => 0, 'amount' => -$sum, 'reason' => 'iptal', 'user_id' => $uid, 'at' => Clock::ms()]);
        }
        foreach (array_merge($kept, $add) as $d) {
            Db::append('order_discounts', ['order_id' => $orderId, 'kind' => $d['kind'], 'value' => $d['value'], 'amount' => $d['amount'], 'reason' => $d['reason'], 'user_id' => $d['user_id'] ?? $uid, 'at' => Clock::ms()]);
        }
        Orders::recalc($orderId);
    }

    // ------------------------------------------------------------ earning and redeeming

    /** Earn % for a customer (their tier's). */
    public static function earnPct(string $customerId): float
    {
        return (float) (self::tierOf($customerId)['earn_pct'] ?? 0);
    }

    /** Points a bill of $amount (kuruş) earns for this customer. */
    public static function pointsFor(string $customerId, int $amount): int
    {
        $pct = self::earnPct($customerId);
        return $pct <= 0 ? 0 : intdiv((int) floor($amount * $pct / 100), self::pointValue());
    }

    /** Whether a bill earns points at all (program on, customer in it, the rules of CU5). */
    public static function earns(array $o): bool
    {
        if (!$o['customer_id'] || !self::enabled()) {
            return false;
        }
        if (!(int) Db::value('SELECT loyalty FROM customers WHERE id = ?', [$o['customer_id']])) {
            return false;
        }
        if (!in_array($o['channel'], ['table', 'qr'], true) && !Settings::get('loyalty.points_on_delivery', true)) {
            return false;
        }
        if (Settings::get('loyalty.no_points_on_discounted', true)) {
            foreach (self::activeDiscounts($o['id']) as $d) {
                if ((int) $d['amount'] > 0 && $d['reason'] !== 'puan' && !self::isAuto($d)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Called when a bill closes: adds the points once. */
    public static function earn(string $orderId): int
    {
        $o = Db::row('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$o || !self::earns($o) || Db::value("SELECT 1 FROM loyalty_ledger WHERE order_id = ? AND kind = 'earn'", [$orderId])) {
            return 0;
        }
        // points on what was really paid: not on the part paid with points; not on "account" (paid later) when the rule says so
        $amount = (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ?' . (Settings::get('loyalty.no_points_on_account', true) ? " AND method <> 'account'" : ''), [$orderId]);
        if (Settings::get('loyalty.no_points_on_discounted', true)) {
            // dishes sold under a promotion are already discounted
            $amount = max(0, $amount - (int) Db::value("SELECT COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) FROM order_items
                WHERE order_id = ? AND promo_id IS NOT NULL AND status <> 'void' AND deleted = 0", [$orderId]));
        }
        $points = self::pointsFor($o['customer_id'], $amount);
        if ($points > 0) {
            Db::append('loyalty_ledger', ['customer_id' => $o['customer_id'], 'points' => $points, 'kind' => 'earn', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        }
        self::refreshTier($o['customer_id']);
        return $points;
    }

    /** Points of the customer usable on this bill now (0 under the minimum). */
    public static function usable(array $o): int
    {
        if (!$o['customer_id']) {
            return 0;
        }
        $balance = self::balance($o['customer_id']);
        if ($balance < (int) Settings::get('loyalty.min_redeem', 0)) {
            return 0;
        }
        return max(0, min($balance, intdiv(max(0, (int) $o['total'] - (int) $o['paid']), self::pointValue())));
    }

    /** Points already used on this bill. */
    public static function used(string $orderId): int
    {
        return -(int) Db::value("SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE order_id = ? AND kind IN ('redeem', 'refund')", [$orderId]);
    }

    /** Uses points on an open bill (C12): the discount is the points' value, capped by the bill. Returns the discount (kuruş). */
    public static function redeem(string $orderId, int $points): int
    {
        $o = Orders::editable($orderId);
        if (!$o['customer_id']) {
            throw new ValidationError(['points' => I18n::t('loy.err_customer')]);
        }
        $balance = self::balance($o['customer_id']);
        $min = (int) Settings::get('loyalty.min_redeem', 0);
        if ($balance < $min) {
            throw new ValidationError(['points' => I18n::t('loy.err_min', ['n' => $min])]);
        }
        $points = min($points, $balance, intdiv(max(0, (int) $o['total'] - (int) $o['paid']), self::pointValue()));
        if ($points <= 0) {
            throw new ValidationError(['points' => I18n::t('loy.err_none')]);
        }
        $amount = $points * self::pointValue();
        Db::tx(static function () use ($o, $orderId, $points, $amount): void {
            Db::append('order_discounts', ['order_id' => $orderId, 'kind' => 'amount', 'value' => $amount, 'amount' => $amount, 'reason' => 'puan', 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
            Db::append('loyalty_ledger', ['customer_id' => $o['customer_id'], 'points' => -$points, 'kind' => 'redeem', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        });
        Orders::recalc($orderId);
        Audit::log('loyalty.redeem', Orders::where($o) . ' · ' . $points . ' puan · ' . Money::fmt($amount, false, 'tr'), 'order', $orderId);
        return $amount;
    }

    /** Takes back the points used on an open bill (the point discount goes, the points return). */
    public static function unredeem(string $orderId): void
    {
        if (self::used($orderId) <= 0) {
            return;
        }
        Db::tx(static function () use ($orderId): void {
            self::rebuild($orderId, static fn(array $d): bool => $d['reason'] !== 'puan');
            self::refund($orderId);
        });
    }

    /** Puts back the points a bill used (cancelled bill, customer changed, "Kullanma"). */
    public static function refund(string $orderId): void
    {
        foreach (Db::rows("SELECT customer_id, SUM(points) AS p FROM loyalty_ledger WHERE order_id = ? AND kind IN ('redeem', 'refund') GROUP BY customer_id", [$orderId]) as $r) {
            if ((int) $r['p'] < 0) {
                Db::append('loyalty_ledger', ['customer_id' => $r['customer_id'], 'points' => -(int) $r['p'], 'kind' => 'refund', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
            }
        }
    }

    /** Manager correction (+/−). */
    public static function adjust(string $customerId, int $points, string $note): void
    {
        if ($points === 0 || trim($note) === '') {
            throw new ValidationError([$points === 0 ? 'points' : 'note' => I18n::t($points === 0 ? 'loy.err_points' : 'order.err_reason')]);
        }
        $c = Customers::find($customerId);
        if ($points < 0 && self::balance($customerId) + $points < 0) {
            $points = -self::balance($customerId);
        }
        Db::append('loyalty_ledger', ['customer_id' => $customerId, 'points' => $points, 'kind' => 'adjust', 'note' => mb_substr(trim($note), 0, 200), 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        Audit::log('loyalty.adjust', $c['name'] . ' · ' . ($points > 0 ? '+' : '') . $points . ' puan · ' . $note, 'customer', $customerId);
    }

    /**
     * Expires points earned before the expiry window that were not used (first in, first out). Nightly.
     * Returns the number of customers touched.
     */
    public static function expire(): int
    {
        $months = (int) Settings::get('loyalty.expiry_months', 12);
        if ($months <= 0) {
            return 0;
        }
        $cutoff = (int) strtotime("-$months months") * 1000;
        $n = 0;
        foreach (Db::rows("SELECT customer_id,
                SUM(CASE WHEN kind IN ('earn', 'adjust', 'refund', 'import') AND points > 0 AND at < ? THEN points ELSE 0 END) AS old_in,
                SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) AS used,
                SUM(points) AS balance
            FROM loyalty_ledger GROUP BY customer_id", [$cutoff]) as $r) {
            $left = min((int) $r['old_in'] - (int) $r['used'], (int) $r['balance']);
            if ($left > 0) {
                Db::append('loyalty_ledger', ['customer_id' => $r['customer_id'], 'points' => -$left, 'kind' => 'expire', 'at' => Clock::ms()]);
                $n++;
            }
        }
        return $n;
    }

    /** The nightly job: expiry, then the automatic tiers. */
    public static function nightly(): string
    {
        return 'expired ' . self::expire() . ' · tiers changed ' . self::refreshAll();
    }

    public static function history(string $customerId, int $limit = 30): array
    {
        return Db::rows('SELECT l.*, o.no, o.channel, u.name AS user_name FROM loyalty_ledger l LEFT JOIN orders o ON o.id = l.order_id LEFT JOIN users u ON u.id = l.user_id
            WHERE l.customer_id = ? ORDER BY l.at DESC, l.id DESC LIMIT ' . $limit, [$customerId]);
    }

    // ------------------------------------------------------------ program figures (CU5)

    public static function kpis(): array
    {
        $m0 = (int) strtotime(date('Y-m-01')) * 1000;
        $p0 = (int) strtotime(date('Y-m-01') . ' -1 month') * 1000;
        $pts = static fn(string $kinds, int $from, int $to): int => (int) Db::value("SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE kind IN ($kinds) AND at >= ? AND at < ?", [$from, $to]);
        $now = Clock::ms() + 1;
        return [
            'members' => (int) Db::value('SELECT COUNT(*) FROM customers WHERE deleted = 0 AND loyalty = 1 AND phone_norm IS NOT NULL'),
            'new' => (int) Db::value('SELECT COUNT(*) FROM customers WHERE deleted = 0 AND loyalty = 1 AND created_at >= ?', [$m0]),
            'earned' => $pts("'earn'", $m0, $now),
            'earned_prev' => $pts("'earn'", $p0, $m0),
            'used' => -$pts("'redeem', 'refund'", $m0, $now),
            'unused' => (int) Db::value('SELECT COALESCE(SUM(b), 0) FROM (SELECT SUM(points) AS b FROM loyalty_ledger GROUP BY customer_id HAVING b > 0)'),
        ];
    }

    /** Most loyal customers: spending this year. */
    public static function top(int $limit = 6): array
    {
        $from = (int) strtotime(date('Y-01-01')) * 1000;
        return Db::rows("SELECT c.id, c.name, c.phone, c.tier_id, SUM(o.total) AS spend, MAX(o.closed_at) AS last_at,
                (SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger l WHERE l.customer_id = c.id) AS points
            FROM orders o JOIN customers c ON c.id = o.customer_id
            WHERE o.status = 'paid' AND o.deleted = 0 AND o.closed_at >= ? AND c.deleted = 0
            GROUP BY c.id ORDER BY spend DESC LIMIT $limit", [$from]);
    }
}
