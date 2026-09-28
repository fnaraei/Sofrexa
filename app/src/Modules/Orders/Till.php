<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{App, Clock, Db, I18n, Money, Settings, ValidationError};

/** Till figures and cards: C1/C8 (open bills), C6/C9 (shift close), C10 (cash moves). */
final class Till
{
    /** Today's (business day) takings: total, cash, card, foreign cash, and yesterday's total for the delta. */
    public static function today(): array
    {
        $rollover = (int) Settings::get('day.rollover_hour', 5);
        [$from, $to] = Clock::dayRange(Orders::businessDay(), $rollover);
        $sum = static fn(int $a, int $b, string $extra = ''): int => (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE at >= ? AND at < ? AND method <> 'account' $extra", [$a, $b]);
        $fx = Db::pairs("SELECT currency, SUM(amount_fx) FROM payments WHERE at >= ? AND at < ? AND method = 'cash' AND currency <> 'TRY' GROUP BY currency", [$from, $to]);
        return [
            'total' => $sum($from, $to),
            'cash' => $sum($from, $to, "AND method = 'cash'"),
            'card' => $sum($from, $to, "AND method = 'card'"),
            'fx' => array_map('floatval', $fx),
            'yesterday' => $sum($from - 86_400_000, $to - 86_400_000),
        ];
    }

    /** "£120 · $80 · €50" */
    public static function fxText(array $fx): string
    {
        $out = [];
        foreach ($fx as $cur => $v) {
            if (abs((float) $v) > 0.001) {
                $out[] = Money::symbol((string) $cur) . digits(I18n::num((float) $v, fmod((float) $v, 1.0) > 0.001 ? 2 : 0));
            }
        }
        return $out ? implode(' · ', $out) : '—';
    }

    /** Short customer name as on the cards: "Ahmet Y." */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        if (count($parts) < 2) {
            return (string) ($parts[0] ?? '');
        }
        return $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.';
    }

    /**
     * A C1/C8 card for an open order: icon, title, sub (desktop, phone), badge [label, tone], button [label, style, href|action], border tone.
     */
    public static function card(array $o): array
    {
        $o['delivery'] = is_array($o['delivery']) ? $o['delivery'] : json_arr($o['delivery']);
        $stage = in_array($o['channel'], Delivery::CHANNELS, true) ? Delivery::stage($o) : null;
        $due = max(0, (int) $o['total'] - (int) $o['paid']);
        $pay = ['label' => t('cash.btn_pay'), 'style' => 'secondary', 'href' => '/cashier/pay/' . $o['id']];
        $c = ['id' => $o['id'], 'channel' => $o['channel'] === 'qr' ? 'table' : $o['channel'], 'title' => Board::title($o), 'amount' => $due];
        $when = static fn(int $ms): string => digits(date('H:i', intdiv($ms, 1000)));
        $courier = !empty($o['delivery']['courier_id']) ? first_name((string) Db::value('SELECT name FROM users WHERE id = ?', [$o['delivery']['courier_id']])) : '';
        $customer = self::shortName($o['customer_name'] ?? $o['label'] ?? '');
        switch ($o['channel']) {
            case 'table':
            case 'qr':
                $c['icon'] = 'grid';
                $waiter = first_name($o['waiter_name'] ?? '');
                $c['sub'] = t('cash.sub_table', ['g' => digits(max(1, (int) $o['guests'])), 'waiter' => $waiter, 't' => dur((int) $o['opened_at'])]);
                $c['sub_m'] = t('cash.sub_table_m', ['waiter' => $waiter, 't' => dur((int) $o['opened_at'])]);
                if ($o['status'] === 'pending') {
                    $c += ['badge' => [t('cash.b_pending'), 'attention'], 'border' => 'attention'];
                    $pay = ['label' => t('cash.btn_approve'), 'style' => 'primary', 'sheet' => '/qr/orders/' . $o['id']];
                } elseif ($o['bill_at'] || $o['status'] === 'billed') {
                    $c += ['badge' => [t('cash.b_bill'), 'warning'], 'border' => 'warning'];
                    $pay['style'] = 'accent';
                } else {
                    $c['badge'] = [t('cash.b_open'), 'accent'];
                }
                break;
            case 'takeaway':
                $c['icon'] = 'bag';
                $who = $customer !== '' ? $customer : first_name($o['waiter_name'] ?? '');
                $c['sub'] = !empty($o['delivery']['pickup_at'])
                    ? t('cash.sub_pickup', ['who' => $who, 'time' => digits((string) $o['delivery']['pickup_at'])])
                    : t('cash.sub_takeaway', ['who' => $who, 'time' => $when((int) $o['opened_at'])]);
                $c['sub_m'] = $c['sub'];
                $c['badge'] = $stage === 'ready' ? [t('cash.b_ready'), 'success'] : [t('cash.b_kitchen'), 'info'];
                break;
            case 'online':
                $c['icon'] = 'globe';
                if (Delivery::isDelivery($o)) {
                    $district = trim(explode(',', (string) ($o['delivery']['address'] ?? ''))[0]);
                    $c['sub'] = trim($customer . ' · ' . $district, ' ·') . ' · ' . $when((int) $o['opened_at']);
                } else {
                    $c['sub'] = t('cash.sub_pickup', ['who' => $customer, 'time' => digits((string) ($o['delivery']['pickup_at'] ?? $when((int) $o['opened_at'])))]);
                }
                $c['sub_m'] = $c['sub'];
                if ($stage === 'pending') {
                    $c += ['badge' => [t('cash.b_pending'), 'attention'], 'border' => 'attention'];
                    $pay = ['label' => t('cash.btn_approve'), 'style' => 'primary', 'sheet' => '/delivery/' . $o['id'] . '/sheet'];
                } else {
                    $c['badge'] = $stage === 'ready' ? [t('cash.b_ready'), 'success'] : [t('cash.b_kitchen'), 'info'];
                }
                break;
            default: // delivery
                $addr = trim(explode(',', (string) ($o['delivery']['address'] ?? ''))[0]);
                if (in_array($stage, ['way', 'done'], true)) {
                    $c['icon'] = 'bike';
                    $c['sub'] = implode(' · ', array_filter([$addr, $customer, $courier !== '' ? t('cash.courier', ['name' => $courier]) : '']));
                    $c['sub_m'] = implode(' · ', array_filter([$courier, t('cash.b_way', ['t' => dur((int) ($o['delivery']['out_at'] ?? $o['opened_at']))])]));
                    $c['badge'] = [t('cash.b_way', ['t' => dur((int) ($o['delivery']['out_at'] ?? $o['opened_at']))]), 'info'];
                    $c['badge_m'] = [t('cash.b_way_s'), 'info'];
                    $pay = ['label' => t('cash.btn_back'), 'style' => 'secondary', 'href' => '/delivery'];
                } else {
                    $c['icon'] = 'phone';
                    $c['sub'] = implode(' · ', array_filter([$addr, $customer, $stage === 'ready' ? t('cash.b_ready') : mb_strtolower(t('cash.b_kitchen'), 'UTF-8')]));
                    $c['sub_m'] = $c['sub'];
                    $c['badge'] = $stage === 'ready' ? [t('cash.b_ready'), 'success'] : [t('cash.b_kitchen'), 'info'];
                    $pay = $courier === ''
                        ? ['label' => t('cash.btn_courier'), 'style' => 'secondary', 'sheet' => '/delivery/' . $o['id'] . '/sheet']
                        : ['label' => t('cash.btn_back'), 'style' => 'secondary', 'href' => '/delivery'];
                }
        }
        $c['btn'] = $pay;
        $c['border'] ??= null;
        $c['badge_m'] ??= $c['badge'];
        $c['search'] = mb_strtolower($c['title'] . ' ' . $o['no'] . ' ' . ($o['customer_name'] ?? '') . ' ' . ($o['label'] ?? ''), 'UTF-8');
        return $c;
    }

    /** Shift summary for C6/C9: KPIs, per channel, account and courier cash, open bills and unsettled couriers. */
    public static function shiftClose(array $shift): array
    {
        $id = $shift['id'];
        $sum = Shifts::summary($id);
        $byChannel = Db::pairs("SELECT CASE WHEN o.channel = 'qr' THEN 'table' ELSE o.channel END AS ch, SUM(p.amount) FROM payments p JOIN orders o ON o.id = p.order_id
            WHERE p.shift_id = ? AND p.method <> 'account' GROUP BY ch", [$id]);
        $cashTry = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND method = 'cash'", [$id]);
        $couriersOpen = count(array_filter(Delivery::couriers(), static fn(array $c): bool => (bool) $c['orders']));
        $open = (int) Db::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'open', 'billed') AND deleted = 0");
        return [
            'sales' => (int) $sum['orders']['total'],
            'cash' => $cashTry,
            'card' => (int) ($sum['methods']['card'] ?? 0),
            'voids' => (int) $sum['voids']['amount'],
            'discount' => (int) $sum['orders']['discount'],
            'rows' => [
                'shift.s_tables' => (int) ($byChannel['table'] ?? 0),
                'shift.s_takeaway' => (int) ($byChannel['takeaway'] ?? 0),
                'shift.s_delivery' => (int) ($byChannel['delivery'] ?? 0),
                'shift.s_online' => (int) ($byChannel['online'] ?? 0),
                'shift.s_account' => (int) ($sum['methods']['account'] ?? 0),
                'shift.s_settle' => (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND order_id IS NULL', [$id]),
                'shift.s_courier' => (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND courier_id IS NOT NULL AND method = 'cash'", [$id]),
            ],
            'expected' => $sum['cash'],
            'open' => $open,
            'couriers_open' => $couriersOpen,
        ];
    }

    /** Currencies shown in the cash count: lira plus the accepted foreign cash. */
    public static function currencies(): array
    {
        return array_values(array_unique(array_merge(['TRY'], array_intersect(Rates::CURRENCIES, (array) Settings::get('currency.accepted', [])))));
    }

    /** Saves a receipt photo for a cash move (private: served only to staff through /cashier/moves/{id}/photo). */
    public static function savePhoto(array $file): ?string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
        if ($ext === null || (int) $file['size'] > 8 * 1024 * 1024) {
            throw new ValidationError(['photo' => I18n::t('err.upload')]);
        }
        $dir = App::storage('uploads/private/receipts');
        $name = 'receipts/' . date('Y-m') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . basename($name))) {
            throw new ValidationError(['photo' => I18n::t('err.upload')]);
        }
        return $name;
    }
}
