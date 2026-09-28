<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Reports;

use Sofrexa\Core\{App, Audit, Db, I18n, Money, Settings};
use Sofrexa\Export\{Pdf, Xlsx};
use Sofrexa\Modules\Finance\Finance;

/**
 * The standard package for the accountant (R4/R5): daily sales with VAT, payments and currencies, purchase invoices,
 * stock value, customer accounts, staff payments and expenses — as Excel (one sheet per part), CSV and PDF, in a zip.
 * Always in Turkish, like the tickets.
 */
final class Accountant
{
    public const PARTS = ['sales', 'payments', 'purchases', 'stock', 'accounts', 'payroll', 'expenses'];
    public const DEFAULT = ['sales', 'payments', 'purchases', 'stock', 'accounts'];

    /** R4 preview card. */
    public static function preview(int $from, int $to): array
    {
        $k = Reports::kpis($from, $to);
        $pay = Reports::payments($from, $to);
        $st = StockReport::totals($from, $to);
        return ['sales' => $k['sales'], 'vat' => Reports::vatByRate($from, $to), 'cash' => $pay['cash_try'] + array_sum(array_column($pay['fx'], 'try')),
            'card' => $pay['card'], 'account' => $pay['account'], 'fx' => $pay['fx'], 'purchases' => $st['purchases'], 'purchases_n' => $st['purchases_n'], 'stock' => $st['value']];
    }

    /** Tables of each part: [title, header, rows, money columns, widths]. Amounts in lira (float). */
    public static function tables(int $from, int $to, array $parts): array
    {
        $L = static fn(int $k): float => round($k / 100, 2);
        [$fromDay, $toDay] = \Sofrexa\Core\Clock::days($from, $to, (int) Settings::get('day.rollover_hour', 5));
        $out = [];
        if (in_array('sales', $parts, true)) {
            // one set of bills and one day for every column: the business day each bill was settled on, its receipt's VAT
            $byDay = [];
            foreach (Reports::bills($from, $to) as $b) {
                $byDay[$b['day']][] = $b;
            }
            ksort($byDay);
            $sums = array_map([Reports::class, 'sum'], $byDay);
            $rates = [];
            foreach ($sums as $s) {
                $rates = array_merge($rates, array_keys($s['by_rate']));
            }
            $rates = array_values(array_unique(array_map('floatval', $rates)));
            sort($rates);
            $rows = [];
            foreach ($sums as $day => $s) {
                $row = [date('d.m.Y', (int) strtotime((string) $day)), $s['bills'], $L($s['sales']), $L($s['discount'])];
                foreach ($rates as $r) {
                    $row[] = $L($s['by_rate'][(string) $r][1] ?? 0);
                }
                $row[] = $L($s['sales'] - $s['vat']);
                $rows[] = $row;
            }
            $head = ['Gün', 'Hesap', 'Ciro (KDV dahil)', 'İndirim'];
            foreach ($rates as $r) {
                $head[] = 'KDV %' . I18n::num($r, 0, 'tr');
            }
            $head[] = 'Net (KDV hariç)';
            $out['sales'] = ['Satış özeti', $head, $rows, range(2, count($head) - 1), array_merge([12, 8, 16, 12], array_fill(0, count($rates) + 1, 14))];
        }
        if (in_array('payments', $parts, true)) {
            $rows = [];
            // grouped by business day in the PHP time zone (the database's clock may differ on the web copy)
            $g = [];
            $roll = (int) Settings::get('day.rollover_hour', 5);
            foreach (Db::rows('SELECT at, method, currency, amount, amount_fx, rate FROM payments WHERE at >= ? AND at < ? ORDER BY at', [$from, $to]) as $p) {
                $k = \Sofrexa\Core\Clock::day((int) $p['at'], $roll) . '|' . $p['method'] . '|' . $p['currency'];
                $g[$k] ??= ['t' => 0, 'fx' => 0.0, 'rates' => []];
                $g[$k]['t'] += (int) $p['amount'];
                $g[$k]['fx'] += (float) $p['amount_fx'];
                $g[$k]['rates'][] = (float) $p['rate'];
            }
            foreach ($g as $k => $v) {
                [$d, $m, $cur] = explode('|', $k);
                $rows[] = [date('d.m.Y', (int) strtotime($d)), self::method($m), $cur, $cur !== 'TRY' ? round($v['fx'], 2) : '', $cur !== 'TRY' ? round(array_sum($v['rates']) / count($v['rates']), 4) : '', $L($v['t'])];
            }
            $out['payments'] = ['Ödemeler', ['Gün', 'Yöntem', 'Para birimi', 'Döviz tutarı', 'Kur', 'TL karşılığı'], $rows, [5], [12, 16, 10, 12, 10, 14]];
        }
        if (in_array('purchases', $parts, true)) {
            $rows = array_map(static fn(array $d): array => [date('d.m.Y', (int) strtotime($d['day'])), (string) $d['supplier'], (string) $d['doc_no'], $L((int) $d['total'] - (int) $d['vat']), $L((int) $d['vat']), $L((int) $d['total']), self::method((string) $d['pay_method'])],
                Db::rows("SELECT d.*, s.name AS supplier FROM stock_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id WHERE d.kind = 'purchase' AND d.day >= ? AND d.day <= ? ORDER BY d.day, d.at", [$fromDay, $toDay]));
            $out['purchases'] = ['Alış faturaları', ['Tarih', 'Tedarikçi', 'Fatura no', 'Net', 'KDV', 'Toplam', 'Ödeme'], $rows, [3, 4, 5], [12, 26, 14, 12, 12, 12, 12]];
        }
        if (in_array('stock', $parts, true)) {
            $rows = array_map(static fn(array $r): array => [$r['name'], $r['unit'], $r['start'], $r['in'], $r['sale'] + $r['waste'], $r['count'], $r['end'], round($r['cost'] / 100, 2), $L($r['value'])], StockReport::moves($from, $to));
            $out['stock'] = ['Stok değeri', ['Kalem', 'Birim', 'Dönem başı', 'Giriş', 'Tüketim + fire', 'Sayım farkı', 'Dönem sonu', 'Ort. maliyet', 'Değer'], $rows, [7, 8], [28, 8, 11, 10, 12, 10, 11, 11, 13]];
        }
        if (in_array('accounts', $parts, true)) {
            $rows = [];
            foreach (Db::rows("SELECT c.name, c.phone, c.tax_no,
                    COALESCE(SUM(CASE WHEN a.at < ? THEN a.amount END), 0) AS open,
                    COALESCE(SUM(CASE WHEN a.at >= ? AND a.at < ? AND a.amount > 0 THEN a.amount END), 0) AS debit,
                    COALESCE(SUM(CASE WHEN a.at >= ? AND a.at < ? AND a.amount < 0 THEN -a.amount END), 0) AS credit,
                    COALESCE(SUM(CASE WHEN a.at < ? THEN a.amount END), 0) AS close
                FROM account_ledger a JOIN customers c ON c.id = a.customer_id GROUP BY c.id HAVING open <> 0 OR debit <> 0 OR credit <> 0 OR close <> 0 ORDER BY c.name", [$from, $from, $to, $from, $to, $to]) as $c) {
                $rows[] = [$c['name'], (string) $c['phone'], (string) $c['tax_no'], $L((int) $c['open']), $L((int) $c['debit']), $L((int) $c['credit']), $L((int) $c['close'])];
            }
            $out['accounts'] = ['Cari hesaplar', ['Müşteri', 'Telefon', 'Vergi no', 'Devir', 'Borç', 'Ödeme', 'Bakiye'], $rows, [3, 4, 5, 6], [26, 16, 14, 12, 12, 12, 12]];
        }
        if (in_array('payroll', $parts, true)) {
            $rows = array_map(static fn(array $p): array => [date('d.m.Y', intdiv((int) $p['at'], 1000)), (string) $p['name'], $p['period'], $p['kind'] === 'advance' ? 'Avans' : 'Maaş / prim', self::method((string) $p['method']), $L((int) $p['total'])],
                Db::rows("SELECT p.*, u.name FROM payroll p LEFT JOIN users u ON u.id = p.user_id WHERE p.at >= ? AND p.at < ? AND p.kind NOT IN ('accrual', 'charge') ORDER BY p.at", [$from, $to]));
            $out['payroll'] = ['Personel ödemeleri', ['Tarih', 'Personel', 'Dönem', 'Tür', 'Ödeme', 'Tutar'], $rows, [5], [12, 24, 10, 14, 12, 14]];
        }
        if (in_array('expenses', $parts, true)) {
            $rows = [];
            foreach (Finance::entries($from, $to) as $e) {
                if (in_array($e['source'], ['stock', 'payroll'], true)) {
                    continue;
                }
                $rows[] = [date('d.m.Y', (int) strtotime($e['day'])), $e['kind'] === 'income' ? 'Gelir' : 'Gider', I18n::t('fin.cat.' . $e['category'], [], 'tr'), $e['text'], self::method($e['method']),
                    I18n::t('fin.src.' . $e['source'], [], 'tr'), $e['receipt'] ? 'var' : '', $L($e['amount'])];
            }
            $out['expenses'] = ['Giderler', ['Tarih', 'Tür', 'Kategori', 'Açıklama', 'Ödeme', 'Kaynak', 'Fiş', 'Tutar'], $rows, [7], [12, 8, 16, 34, 10, 14, 6, 13]];
        }
        return $out;
    }

    private static function method(string $m): string
    {
        return ['cash' => 'Nakit', 'card' => 'Kart', 'account' => 'Cari', 'bank' => 'Banka', 'transfer' => 'Havale', 'credit' => 'Vadeli'][$m] ?? $m;
    }

    /** Builds the zip in a temporary file and returns its path. */
    public static function zip(array $p, array $parts, array $formats): string
    {
        $tables = self::tables($p['from'], $p['to'], $parts);
        $tmp = App::storage('tmp');
        $base = 'muhasebe-' . $p['first'] . '_' . $p['last'];
        $zipFile = $tmp . '/' . $base . '-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('zip');
        }
        $clean = [];
        if (in_array('xlsx', $formats, true)) {
            $x = new Xlsx();
            foreach ($tables as [$title, $head, $rows, $money, $widths]) {
                $x->sheet($title, $head, $rows, $money, $widths);
            }
            $f = $tmp . '/' . bin2hex(random_bytes(6)) . '.xlsx';
            $x->save($f);
            $zip->addFile($f, $base . '.xlsx');
            $clean[] = $f;
        }
        if (in_array('csv', $formats, true)) {
            foreach ($tables as $key => [$title, $head, $rows]) {
                $h = fopen('php://temp', 'w+');
                fwrite($h, "\xEF\xBB\xBF");
                fputcsv($h, $head, ';', '"', '');
                foreach ($rows as $r) {
                    fputcsv($h, array_map(static fn($v): string => is_float($v) ? number_format($v, 2, ',', '') : (string) $v, $r), ';', '"', '');
                }
                rewind($h);
                $zip->addFromString($base . '-' . $key . '.csv', (string) stream_get_contents($h));
            }
        }
        if (in_array('pdf', $formats, true)) {
            $zip->addFromString($base . '.pdf', self::pdf($p, $tables));
        }
        $zip->close();
        foreach ($clean as $f) {
            @unlink($f);
        }
        Audit::log('report.accountant', $p['first'] . ' – ' . $p['last'] . ' · ' . implode(', ', $parts) . ' · ' . implode(', ', $formats));
        return $zipFile;
    }

    /** The PDF of the package: the summary and every table. */
    public static function pdf(array $p, array $tables): string
    {
        $name = (string) Settings::get('profile.name', '');
        $pdf = new Pdf('Muhasebe paketi · ' . date('d.m.Y', (int) strtotime($p['first'])) . ' – ' . date('d.m.Y', (int) strtotime($p['last'])), $name . ' · Sofrexa');
        $pr = self::preview($p['from'], $p['to']);
        $pdf->heading('Muhasebe paketi', 18, $name . ' · ' . date('d.m.Y', (int) strtotime($p['first'])) . ' – ' . date('d.m.Y', (int) strtotime($p['last'])) . ' · fiyatlar KDV dahil');
        $kv = [['Toplam satış (KDV dahil)', Money::fmt($pr['sales'], false, 'tr'), true]];
        foreach ($pr['vat'] as $rate => [$gross, $vat]) {
            $kv[] = ['KDV %' . I18n::num((float) $rate, 0, 'tr'), Money::fmt($vat, false, 'tr')];
        }
        $kv[] = ['Nakit / kart / cari', Money::fmt($pr['cash'], false, 'tr') . ' / ' . Money::fmt($pr['card'], false, 'tr') . ' / ' . Money::fmt($pr['account'], false, 'tr')];
        $kv[] = ['Alış faturaları', Money::fmt($pr['purchases'], false, 'tr') . ' · ' . $pr['purchases_n'] . ' adet'];
        $kv[] = ['Stok değeri (' . date('d.m', (int) strtotime($p['last'])) . ')', Money::fmt($pr['stock'], false, 'tr')];
        $pdf->kv($kv, 2);
        foreach ($tables as [$title, $head, $rows, $money, $widths]) {
            $pdf->section($title);
            $cols = [];
            foreach ($head as $i => $h) {
                $cols[] = [$h, $widths[$i] ?? 10, in_array($i, $money, true) || (isset($rows[0][$i]) && (is_int($rows[0][$i]) || is_float($rows[0][$i]))) ? 'R' : 'L'];
            }
            $pdf->table($cols, array_map(static fn(array $r): array => array_map(static fn($v, int $i): string => is_float($v) && in_array($i, $money, true) ? number_format($v, 2, ',', '.') : (is_float($v) ? str_replace('.', ',', (string) $v) : (string) $v), $r, array_keys($r)), $rows), count($head) > 7 ? 7.5 : 8.5);
            if (!$rows) {
                $pdf->paragraph('Bu dönemde kayıt yok.');
            }
        }
        return $pdf->output();
    }

    /** Sends the package by e-mail (to the accountant's address in the restaurant settings, or the one typed). */
    public static function mail(array $p, array $parts, array $formats, string $to): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \Sofrexa\Core\ValidationError(['email' => I18n::t('cust.err_email')]);
        }
        $file = self::zip($p, $parts, $formats);
        $name = (string) Settings::get('profile.name', '');
        $ok = \Sofrexa\Core\Mailer::send($to, 'Muhasebe paketi · ' . $name . ' · ' . $p['first'] . ' – ' . $p['last'],
            "Merhaba,\n\n" . $name . ' için ' . date('d.m.Y', (int) strtotime($p['first'])) . ' – ' . date('d.m.Y', (int) strtotime($p['last'])) . " dönemi muhasebe paketi ektedir.\n\nSofrexa",
            null, ['muhasebe-' . $p['first'] . '_' . $p['last'] . '.zip' => $file]);
        @unlink($file);
        if ($ok) {
            Settings::set('report.accountant_email', $to);
        }
        return $ok;
    }
}
