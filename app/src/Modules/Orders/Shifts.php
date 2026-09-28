<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Audit, Auth, Clock, Db, I18n, Money, ValidationError};

/**
 * Till shifts (C6/C9) and cash moves (C10/C11). The till has one open shift at a time. Cash moves are
 * append-only: a mistake is fixed with a reverse entry. Expected cash per currency =
 * opening + cash taken − change given + cash in − cash out.
 */
final class Shifts
{
    public static function current(): ?array
    {
        return Db::row('SELECT s.*, u.name AS user_name FROM shifts s LEFT JOIN users u ON u.id = s.user_id WHERE s.closed_at IS NULL AND s.deleted = 0 ORDER BY s.opened_at DESC LIMIT 1');
    }

    public static function currentId(): ?string
    {
        return Db::value('SELECT id FROM shifts WHERE closed_at IS NULL AND deleted = 0 ORDER BY opened_at DESC LIMIT 1');
    }

    /** Opens a shift with the cash in the drawer: ['TRY' => kuruş, 'GBP' => 20.0, ...]. */
    public static function open(array $opening): string
    {
        if (self::currentId()) {
            throw new \InvalidArgumentException(I18n::t('shift.err_open'));
        }
        $u = Auth::user();
        $try = (int) ($opening['TRY'] ?? 0);
        $id = Db::tx(static function () use ($u, $try, $opening): string {
            $id = Db::save('shifts', ['user_id' => $u['id'] ?? null, 'device' => \Sofrexa\Core\Audit::device(), 'opened_at' => Clock::ms(), 'opening_cash' => $try,
                'counted' => null, 'expected' => null, 'note' => json_encode(['opening' => $opening], JSON_UNESCAPED_UNICODE)]);
            foreach ($opening as $cur => $amount) {
                if ((float) $amount > 0) {
                    self::move('open', (string) $cur, (float) $amount, I18n::t('shift.opening', [], 'tr'), null, $id);
                }
            }
            return $id;
        });
        Audit::log('cash.open', Money::fmt($try, false, 'tr') . ' · vardiya açıldı', 'shift', $id);
        return $id;
    }

    /**
     * Records a cash move. $kind: open | in | out | nosale | reverse. $amount is kuruş for TRY, units for foreign cash.
     */
    public static function move(string $kind, string $currency, float $amount, string $reason, ?string $note = null, ?string $shiftId = null, ?string $reverses = null, ?string $photo = null, ?string $ref = null): string
    {
        $shiftId ??= self::currentId();
        if (!$shiftId) {
            throw new \InvalidArgumentException(I18n::t('shift.err_none'));
        }
        $currency = strtoupper($currency);
        if ($currency === 'TRY') {
            $try = (int) round($amount);
            $fx = 0.0;
        } else {
            $rate = Rates::latest()[$currency] ?? null;
            // foreign cash in the drawer at opening is only counted; in/out moves need today's rate for the lira value
            if (!$rate && $kind !== 'open') {
                throw new ValidationError(['currency' => I18n::t('order.err_rate', ['cur' => $currency])]);
            }
            $fx = round($amount, 2);
            $try = $rate ? Money::toTry($fx, $rate) : 0;
        }
        $sign = in_array($kind, ['out'], true) ? -1 : 1;
        $id = Db::append('cash_moves', ['shift_id' => $shiftId, 'kind' => $kind, 'currency' => $currency, 'amount_fx' => $sign * $fx, 'amount' => $sign * $try,
            'reason' => mb_substr($reason, 0, 120), 'note' => $note !== null ? mb_substr($note, 0, 300) : null, 'photo' => $photo, 'reverses' => $reverses, 'ref' => $ref, 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
        if ($kind === 'in' || $kind === 'out') {
            Audit::log('cash.' . $kind, ($currency === 'TRY' ? Money::fmt($try, false, 'tr') : number_format($fx, 2, ',', '.') . ' ' . $currency) . ' · ' . $reason . ($note ? ' · ' . $note : ''), 'cash_move', $id);
        }
        return $id;
    }

    /** Reverse entry for a mistaken cash move. */
    public static function reverse(string $moveId): string
    {
        $m = Db::row('SELECT * FROM cash_moves WHERE id = ?', [$moveId]);
        if (!$m || $m['kind'] === 'reverse' || Db::value('SELECT 1 FROM cash_moves WHERE reverses = ?', [$moveId])) {
            throw new \InvalidArgumentException(I18n::t('shift.err_reverse'));
        }
        $id = Db::append('cash_moves', ['shift_id' => $m['shift_id'], 'kind' => 'reverse', 'currency' => $m['currency'], 'amount_fx' => -(float) $m['amount_fx'], 'amount' => -(int) $m['amount'],
            'reason' => 'düzeltme: ' . $m['reason'], 'reverses' => $moveId, 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
        Audit::log('cash.reverse', Money::fmt(-(int) $m['amount'], false, 'tr') . ' · ' . $m['reason'], 'cash_move', $id);
        return $id;
    }

    public static function noSale(string $reason = ''): void
    {
        if (!Auth::can('cash.nosale')) {
            throw new \Sofrexa\Core\HttpError(403, I18n::t('err.forbidden'));
        }
        $shift = self::currentId();
        Db::append('cash_moves', ['shift_id' => $shift, 'kind' => 'nosale', 'currency' => 'TRY', 'amount_fx' => 0, 'amount' => 0, 'reason' => mb_substr($reason ?: 'satışsız açma', 0, 120), 'user_id' => Auth::user()['id'] ?? null, 'at' => Clock::ms()]);
        Audit::log('cash.nosale', I18n::t('audit.nosale_text', [], 'tr') . ($reason !== '' ? ' · ' . $reason : ''), 'shift', $shift);
        Tickets::drawer();
    }

    /**
     * Expected drawer content per currency for a shift: TRY in kuruş, foreign in units.
     * Also totals per payment method (TRY) for the Z report.
     */
    public static function summary(string $shiftId): array
    {
        $cash = ['TRY' => 0];
        foreach (Db::rows("SELECT currency, SUM(CASE WHEN currency = 'TRY' THEN amount ELSE amount_fx END) AS v FROM cash_moves WHERE shift_id = ? GROUP BY currency", [$shiftId]) as $r) {
            $cash[$r['currency']] = ($cash[$r['currency']] ?? 0) + (float) $r['v'];
        }
        foreach (Db::rows("SELECT currency, SUM(amount_fx) AS fx, SUM(amount) AS try, SUM(change_given) AS ch FROM payments WHERE shift_id = ? AND method = 'cash' GROUP BY currency", [$shiftId]) as $r) {
            if ($r['currency'] === 'TRY') {
                $cash['TRY'] += (int) $r['try'];
            } else {
                $cash[$r['currency']] = ($cash[$r['currency']] ?? 0) + (float) $r['fx'];
                $cash['TRY'] -= (int) $r['ch']; // change for foreign cash is given in lira
            }
        }
        $methods = Db::pairs('SELECT method, SUM(amount) FROM payments WHERE shift_id = ? GROUP BY method', [$shiftId]);
        // bills closed in this shift (an order may have been opened before the shift started)
        $orders = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(discount), 0) AS discount FROM orders
            WHERE status = 'paid' AND id IN (SELECT order_id FROM payments WHERE shift_id = ? AND order_id IS NOT NULL)", [$shiftId]);
        $s = Db::row('SELECT opened_at, closed_at FROM shifts WHERE id = ?', [$shiftId]);
        $voids = Db::row("SELECT COUNT(*) AS n, COALESCE(SUM(ROUND(qty * (unit_price + mods_price))), 0) AS amount FROM order_items
            WHERE status = 'void' AND sent_at IS NOT NULL AND void_at >= ? AND void_at < ?", [(int) ($s['opened_at'] ?? 0), (int) ($s['closed_at'] ?: PHP_INT_MAX)]);
        $moves = Db::pairs("SELECT kind, SUM(amount) FROM cash_moves WHERE shift_id = ? AND currency = 'TRY' GROUP BY kind", [$shiftId]);
        return ['cash' => $cash, 'methods' => array_map('intval', $methods), 'orders' => $orders, 'voids' => $voids, 'moves' => array_map('intval', $moves)];
    }

    /** Closes the shift with the counted cash per currency; prints the Z report. Returns the Z number. */
    public static function close(array $counted, string $note = ''): int
    {
        $s = self::current();
        if (!$s) {
            throw new \InvalidArgumentException(I18n::t('shift.err_none'));
        }
        $open = (int) Db::value("SELECT COUNT(*) FROM orders WHERE shift_id = ? AND status IN ('pending', 'open', 'billed') AND deleted = 0", [$s['id']]);
        if ($open > 0 && !Auth::can('*')) {
            throw new \InvalidArgumentException(I18n::t('shift.err_open_orders', ['n' => $open]));
        }
        $sum = self::summary($s['id']);
        $z = (int) Db::value('SELECT COALESCE(MAX(z_no), 0) + 1 FROM shifts');
        Db::save('shifts', ['id' => $s['id'], 'closed_at' => Clock::ms(), 'counted' => $counted, 'expected' => $sum['cash'], 'z_no' => $z, 'note' => json_encode(json_arr($s['note']) + ['close' => $note], JSON_UNESCAPED_UNICODE)]);
        $diff = (int) ($counted['TRY'] ?? 0) - (int) $sum['cash']['TRY'];
        Audit::log('shift.close', 'Z ' . $z . ' · sayılan ' . Money::fmt((int) ($counted['TRY'] ?? 0), false, 'tr') . ' · fark ' . Money::fmt($diff, true, 'tr'), 'shift', $s['id']);
        Tickets::zReport($s['id']);
        return $z;
    }
}
