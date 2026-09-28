<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Customers;

use Sofrexa\Core\{Audit, Auth, Clock, Db, I18n, Money, Settings, ValidationError};
use Sofrexa\Modules\Orders\Orders;

/**
 * Loyalty (CU5, CU7, C12). Points live in loyalty_ledger (append-only): earn, redeem, expire, adjust.
 * A tier (Bronz, Gümüş, Altın …) gives a discount and an earn rate; the tier follows the customer's spending
 * over the last months unless the manager set it by hand for that customer. The point value and the
 * tiers' rates are settings the manager edits.
 */
final class Loyalty
{
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

    public static function saveTier(array $in): string
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ValidationError(['name' => I18n::t('stock.err_name')]);
        }
        $row = [
            'name' => mb_substr($name, 0, 40),
            'threshold' => Money::parse($in['threshold'] ?? 0),
            'discount_pct' => max(0, min(100, (float) str_replace(',', '.', (string) ($in['discount_pct'] ?? 0)))),
            'earn_pct' => max(0, min(100, (float) str_replace(',', '.', (string) ($in['earn_pct'] ?? 0)))),
            'tone' => in_array($in['tone'] ?? '', ['Neutral', 'Accent', 'Warning', 'Success', 'Info', 'Attention', 'Solid'], true) ? $in['tone'] : 'Neutral',
        ];
        $id = Db::save('tiers', (($in['id'] ?? '') ? ['id' => $in['id']] : []) + $row);
        Audit::log('loyalty.tier', $row['name'] . ' · %' . I18n::num($row['discount_pct'], 1, 'tr') . ' indirim · %' . I18n::num($row['earn_pct'], 1, 'tr') . ' puan · eşik ' . Money::fmt($row['threshold'], false, 'tr'), 'tier', $id);
        foreach (Db::rows('SELECT id FROM customers WHERE deleted = 0 AND tier_manual = 0') as $c) {
            self::refreshTier($c['id']);
        }
        return $id;
    }

    // ------------------------------------------------------------ balance and tier

    public static function balance(string $customerId): int
    {
        return (int) Db::value('SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE customer_id = ?', [$customerId]);
    }

    /** Spending in the tier window (kuruş, paid bills). */
    public static function spend(string $customerId): int
    {
        $from = Clock::ms() - (int) Settings::get('loyalty.tier_window_months', 12) * 30 * 86_400_000;
        return (int) Db::value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE customer_id = ? AND status = 'paid' AND closed_at >= ? AND deleted = 0", [$customerId, $from]);
    }

    /** The tier the spending earns (the highest threshold reached). */
    public static function earnedTier(string $customerId): ?array
    {
        $spend = self::spend($customerId);
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

    /** Manager sets the tier by hand (CU7), or gives it back to the automatic rule ($tierId null, $manual false). */
    public static function setTier(string $customerId, ?string $tierId, bool $manual, string $note = ''): void
    {
        $c = Customers::get($customerId);
        if (!$c) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        if ($manual) {
            Db::save('customers', ['id' => $customerId, 'tier_id' => $tierId ?: null, 'tier_manual' => 1, 'tier_note' => mb_substr(trim($note), 0, 200) ?: null]);
        } else {
            Db::save('customers', ['id' => $customerId, 'tier_manual' => 0, 'tier_note' => null]);
            self::refreshTier($customerId);
        }
        $t = self::tierOf($customerId);
        Audit::log('customer.tier', $c['name'] . ' → ' . ($t['name'] ?? '—') . ($manual ? ' (elle)' : ' (otomatik)') . ($note !== '' ? ' · ' . $note : ''), 'customer', $customerId);
    }

    /** Discount % of a customer: their own discount, else their tier's. */
    public static function discountPct(string $customerId): float
    {
        $own = (float) Db::value('SELECT discount_pct FROM customers WHERE id = ?', [$customerId]);
        if ($own > 0) {
            return $own;
        }
        return (float) (self::tierOf($customerId)['discount_pct'] ?? 0);
    }

    // ------------------------------------------------------------ earning and redeeming

    /** Points a bill of $amount (kuruş) earns for this customer. */
    public static function pointsFor(string $customerId, int $amount): int
    {
        $pct = (float) (self::tierOf($customerId)['earn_pct'] ?? Settings::get('loyalty.earn_pct', 0));
        return $pct <= 0 ? 0 : intdiv((int) floor($amount * $pct / 100), self::pointValue());
    }

    /** Called when a bill closes: adds the points once. */
    public static function earn(string $orderId): int
    {
        $o = Db::row('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$o || !$o['customer_id'] || !self::enabled() || Db::value("SELECT 1 FROM loyalty_ledger WHERE order_id = ? AND kind = 'earn'", [$orderId])) {
            return 0;
        }
        $c = Db::row('SELECT loyalty FROM customers WHERE id = ?', [$o['customer_id']]);
        if (!$c || !(int) $c['loyalty']) {
            return 0;
        }
        if ($o['channel'] === 'delivery' && !Settings::get('loyalty.points_on_delivery', true)) {
            return 0;
        }
        // points on what was really paid: not on the part paid with points, not on "account" (paid later)
        $amount = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND method <> 'account'", [$orderId]);
        if (!Settings::get('loyalty.no_points_on_account', true)) {
            $amount = (int) $o['total'];
        }
        $hasManualDiscount = (bool) Db::value("SELECT 1 FROM order_discounts WHERE order_id = ? AND kind <> 'reverse' AND COALESCE(reason, '') <> 'puan' AND amount > 0 AND COALESCE(reason, '') NOT LIKE 'seviye:%'", [$orderId]);
        if ($hasManualDiscount && Settings::get('loyalty.no_points_on_discounted', true)) {
            return 0;
        }
        $points = self::pointsFor($o['customer_id'], $amount);
        if ($points > 0) {
            Db::append('loyalty_ledger', ['customer_id' => $o['customer_id'], 'points' => $points, 'kind' => 'earn', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        }
        self::refreshTier($o['customer_id']);
        return $points;
    }

    /** Uses points on an open bill (C12): the discount is the points' value, capped by the bill. Returns the discount (kuruş). */
    public static function redeem(string $orderId, int $points): int
    {
        $o = Orders::editable($orderId);
        if (!$o['customer_id']) {
            throw new ValidationError(['points' => I18n::t('loy.err_customer')]);
        }
        $balance = self::balance($o['customer_id']);
        $points = min($points, $balance, intdiv(max(0, (int) $o['total'] - (int) $o['paid']), self::pointValue()));
        if ($points <= 0) {
            throw new ValidationError(['points' => I18n::t('loy.err_none')]);
        }
        if ($points < (int) Settings::get('loyalty.min_redeem', 0) && $points < $balance) {
            throw new ValidationError(['points' => I18n::t('loy.err_min', ['n' => (int) Settings::get('loyalty.min_redeem', 0)])]);
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

    /** Puts points back when a bill that used them is cancelled. */
    public static function refund(string $orderId): void
    {
        foreach (Db::rows("SELECT customer_id, SUM(points) AS p FROM loyalty_ledger WHERE order_id = ? AND kind = 'redeem' GROUP BY customer_id", [$orderId]) as $r) {
            if ((int) $r['p'] < 0 && !Db::value("SELECT 1 FROM loyalty_ledger WHERE order_id = ? AND kind = 'refund'", [$orderId])) {
                Db::append('loyalty_ledger', ['customer_id' => $r['customer_id'], 'points' => -(int) $r['p'], 'kind' => 'refund', 'order_id' => $orderId, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
            }
        }
    }

    /** Manager correction (+/−). */
    public static function adjust(string $customerId, int $points, string $note): void
    {
        if ($points === 0 || trim($note) === '') {
            throw new ValidationError(['note' => I18n::t('order.err_reason')]);
        }
        Db::append('loyalty_ledger', ['customer_id' => $customerId, 'points' => $points, 'kind' => 'adjust', 'note' => mb_substr(trim($note), 0, 200), 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        Audit::log('loyalty.adjust', (string) Db::value('SELECT name FROM customers WHERE id = ?', [$customerId]) . ' · ' . ($points > 0 ? '+' : '') . $points . ' puan · ' . $note, 'customer', $customerId);
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
        $cutoff = Clock::ms() - $months * 30 * 86_400_000;
        $n = 0;
        foreach (Db::rows("SELECT customer_id,
                SUM(CASE WHEN kind IN ('earn', 'adjust', 'refund') AND points > 0 AND at < ? THEN points ELSE 0 END) AS old_in,
                SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) AS used
            FROM loyalty_ledger GROUP BY customer_id", [$cutoff]) as $r) {
            $left = (int) $r['old_in'] - (int) $r['used'];
            if ($left > 0) {
                Db::append('loyalty_ledger', ['customer_id' => $r['customer_id'], 'points' => -$left, 'kind' => 'expire', 'at' => Clock::ms()]);
                $n++;
            }
        }
        return $n;
    }

    public static function history(string $customerId, int $limit = 30): array
    {
        return Db::rows('SELECT l.*, o.no, o.channel FROM loyalty_ledger l LEFT JOIN orders o ON o.id = l.order_id WHERE l.customer_id = ? ORDER BY l.at DESC LIMIT ' . $limit, [$customerId]);
    }
}
