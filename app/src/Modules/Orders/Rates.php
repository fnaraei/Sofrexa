<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Audit, Auth, Clock, Db, Settings};

/** Exchange rates the cashier enters by hand (C7). 1 unit of foreign cash = rate lira. */
final class Rates
{
    public const CURRENCIES = ['GBP', 'USD', 'EUR'];

    /** currency => latest rate, for the accepted currencies. */
    public static function latest(): array
    {
        $out = [];
        foreach ((array) Settings::get('currency.accepted', []) as $c) {
            $r = Db::value('SELECT rate FROM fx_rates WHERE currency = ? ORDER BY at DESC LIMIT 1', [$c]);
            if ($r !== null) {
                $out[$c] = (float) $r;
            }
        }
        return $out;
    }

    /** Latest rate rows with who/when (for the rate screen). */
    public static function detail(): array
    {
        $out = [];
        foreach (self::CURRENCIES as $c) {
            $r = Db::row('SELECT f.rate, f.at, u.name FROM fx_rates f LEFT JOIN users u ON u.id = f.user_id WHERE f.currency = ? ORDER BY f.at DESC LIMIT 1', [$c]);
            $prev = Db::value('SELECT rate FROM fx_rates WHERE currency = ? ORDER BY at DESC LIMIT 1 OFFSET 1', [$c]);
            $out[$c] = ['rate' => $r ? (float) $r['rate'] : null, 'prev' => $prev !== null ? (float) $prev : null, 'at' => $r['at'] ?? null, 'by' => $r['name'] ?? null,
                'accepted' => in_array($c, (array) Settings::get('currency.accepted', []), true)];
        }
        return $out;
    }

    public static function set(string $currency, float $rate): void
    {
        if (!in_array($currency, self::CURRENCIES, true) || $rate <= 0) {
            throw new \InvalidArgumentException('rate');
        }
        Db::append('fx_rates', ['currency' => $currency, 'rate' => round($rate, 4), 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        Audit::log('cash.rate', $currency . ' ' . number_format($rate, 2, ',', '.'), 'fx', $currency);
    }
}
