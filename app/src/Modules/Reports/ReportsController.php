<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Reports;

use Sofrexa\Core\{HttpError, I18n, Request, Response, View};
use Sofrexa\Export\Xlsx;
use Sofrexa\Modules\Orders\Tickets;

/** Reports: the list, R3 end of day (Z), R6 voids, R7/R8 staff, sales by item, stock, R4/R5 accountant export. */
final class ReportsController
{
    public function hub(Request $req): void
    {
        View::page('reports/hub', ['title' => I18n::t('nav.reports'), 'nav' => 'reports', 'tab' => 'reports']);
    }

    // ------------------------------------------------------------ R3
    public function z(Request $req): void
    {
        $shifts = Reports::shifts(40);
        if (!$shifts) {
            View::page('reports/z', ['title' => I18n::t('rep.eod'), 'nav' => 'reports', 'back' => '/reports', 'z' => null, 'shifts' => []]);
        }
        $id = $req->str('id');
        if ($id === '' || !in_array($id, array_column($shifts, 'id'), true)) {
            $closed = array_values(array_filter($shifts, static fn(array $s): bool => (bool) $s['closed_at']));
            $id = ($closed[0] ?? $shifts[0])['id'];
        }
        View::page('reports/z', ['title' => I18n::t('rep.eod'), 'nav' => 'reports', 'back' => '/reports', 'z' => Reports::z($id), 'shifts' => $shifts, 'scripts' => ['js/reports.js']]);
    }

    public function zPrint(Request $req): void
    {
        $z = Reports::z($req->param('id'));
        Tickets::zReport($z['s']['id'], !$z['closed']);
        Response::json(['ok' => true, 'message' => I18n::t('rep.printed')]);
    }

    /** R3 as a PDF (A4, Turkish). */
    public function zPdf(Request $req): void
    {
        $z = Reports::z($req->param('id'));
        $s = $z['s'];
        $no = str_pad((string) $s['z_no'], 4, '0', STR_PAD_LEFT);
        $day = date('d.m.Y', intdiv((int) $s['opened_at'], 1000));
        $tl = static fn(int $k): string => \Sofrexa\Core\Money::fmt($k, false, 'tr');
        $pdf = new \Sofrexa\Export\Pdf('Gün sonu · Z #' . $no . ' · ' . $day, (string) \Sofrexa\Core\Settings::get('profile.name', '') . ' · Sofrexa');
        $pdf->heading('Gün sonu · ' . $day, 18, ($z['closed'] ? 'Z raporu #' . $no : 'Ara rapor (vardiya açık)') . ' · ' . date('H:i', intdiv((int) $s['opened_at'], 1000)) . '–' . date('H:i', intdiv((int) ($s['closed_at'] ?: \Sofrexa\Core\Clock::ms()), 1000)) . ' · kapatan: ' . first_name((string) $s['name']));
        $pdf->kv([['Ciro (KDV dahil)', $tl($z['sales']), true], ['Hesap', (string) $z['bills']], ['KDV', $tl(array_sum($z['vat']))], ['İptal', $tl((int) $z['sum']['voids']['amount']) . ' · ' . $z['sum']['voids']['n'] . ' ürün'],
            ['İndirim', $tl($z['discount']) . ' · ' . $z['discount_n'] . ' hesap'], ['Misafir', (string) $z['guests']]], 2);
        $pay = [['Nakit TL', $tl($z['cash_try'])]];
        foreach ($z['fx'] as $f) {
            $pay[] = ['Nakit ' . \Sofrexa\Core\Money::symbol($f['currency']) . ' (' . I18n::num((float) $f['fx'], 0, 'tr') . ' × ' . number_format((float) $f['rate'], 2, ',', '.') . ')', $tl((int) $f['try'])];
        }
        $pay[] = ['Kart (POS)', $tl($z['methods']['card'] ?? 0)];
        $pay[] = ['Cari hesaba', $tl($z['methods']['account'] ?? 0)];
        $pay[] = ['Toplam', $tl(array_sum($z['methods'])), true];
        $pdf->section('Ödemeler')->kv($pay);
        $ch = $z['channels'];
        $pdf->section('Kanallar')->kv([['Masa', $tl(($ch['table'] ?? 0) + ($ch['qr'] ?? 0))], ['  içinde QR', $tl($ch['qr'] ?? 0)], ['Gel-al', $tl($ch['takeaway'] ?? 0)], ['Teslimat', $tl($ch['delivery'] ?? 0)], ['Online', $tl($ch['online'] ?? 0)],
            ['Ort. hesap', $tl($z['bills'] ? (int) round($z['sales'] / $z['bills']) : 0)]]);
        $exp = (int) ($z['expected']['TRY'] ?? 0);
        $cnt = (int) ($z['counted']['TRY'] ?? 0);
        $pdf->section('Kasa sayımı')->kv([['Beklenen TL', $tl($exp)], ['Sayılan TL', $z['closed'] ? $tl($cnt) : '—'], ['Fark', $z['closed'] ? ($cnt - $exp < 0 ? '−' : '+') . $tl(abs($cnt - $exp)) : '—', true],
            ['Kurye nakdi', $tl($z['courier'])], ['Açık masa', $z['open']['n'] ? $z['open']['n'] . ' · ' . $tl($z['open']['amount']) : 'yok'], ['Açıklama', $z['note'] !== '' ? $z['note'] : '—']]);
        $out = $pdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="gun-sonu-z' . $no . '.pdf"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }

    // ------------------------------------------------------------ R6
    public function voids(Request $req): void
    {
        [$p, $f] = $this->voidFilter($req);
        $rows = Reports::voids($p['from'], $p['to'], $f);
        if ($req->str('x') === 'xlsx') {
            (new Xlsx())->sheet('İptaller', ['Zaman', 'Hesap', 'Ürün', 'Adet', 'Tutar', 'Aşama', 'Kim', 'Onay', 'Sebep'], array_map(static fn(array $r): array => [
                date('d.m.Y H:i', intdiv((int) $r['void_at'], 1000)), self::where($r, 'tr'), $r['name'], (float) $r['qty'], $r['amount'] / 100,
                I18n::t('rep.stage.' . $r['stage'], [], 'tr'), (string) $r['by_name'], (string) $r['approver'], (string) $r['void_reason'],
            ], $rows), [4], [16, 18, 28, 6, 12, 18, 14, 14, 30])->download('iptaller-' . $p['first'] . '.xlsx');
        }
        View::page('reports/voids', ['title' => I18n::t('rep.voids_t'), 'nav' => 'reports', 'back' => '/reports', 'rows' => $rows, 'p' => $p, 'f' => $f,
            'week' => Reports::voids(Reports::period('7d')['from'], Reports::period('7d')['to']), 'staff' => $this->voiders()]);
    }

    /** Chips: this week (default), today, one person, after the kitchen. */
    private function voidFilter(Request $req): array
    {
        $p = Reports::period($req->str('p') === 'today' ? 'today' : '7d');
        return [$p, ['who' => $req->str('who'), 'after_kitchen' => $req->bool('after'), 'p' => $p['key']]];
    }

    private function voiders(): array
    {
        $p = Reports::period('7d');
        return \Sofrexa\Core\Db::rows("SELECT u.id, u.name, COUNT(*) AS n FROM order_items i JOIN users u ON u.id = i.void_by WHERE i.status = 'void' AND i.sent_at IS NOT NULL AND i.void_at >= ? AND i.void_at < ?
            GROUP BY u.id ORDER BY n DESC LIMIT 4", [$p['from'], $p['to']]);
    }

    /** "Masa 8 · Salon", "Paket #0112", "Teslimat #0148". */
    public static function where(array $r, ?string $lang = null): string
    {
        $no = sprintf('%04d', (int) $r['no']);
        return match ($r['channel']) {
            'table', 'qr' => I18n::t('rep.where_table', ['n' => (string) $r['table_no']], $lang) . ($r['area'] ? ' · ' . tn(json_arr($r['area']), $lang) : ''),
            'delivery', 'online' => I18n::t('rep.where_delivery', ['no' => $no], $lang),
            default => I18n::t('cust.doc_pack', ['no' => $no], $lang),
        };
    }

    // ------------------------------------------------------------ R7 / R8
    public function staff(Request $req): void
    {
        $key = in_array($req->str('p'), ['today', '7d', 'month', 'last_month', 'custom'], true) ? $req->str('p') : 'month';
        $p = Reports::period($key, $req->str('from'), $req->str('to'));
        $rows = Reports::staff($p['from'], $p['to']);
        if ($req->str('x') === 'xlsx') {
            (new Xlsx())->sheet('Personel', ['Personel', 'Rol', 'Saat', 'Satış', 'Hesap', 'Ort.', 'İptal adet', 'İptal tutar', 'İndirim', 'Pay %'], array_map(static fn(array $r): array => [
                $r['name'], I18n::t('role.' . $r['role_code'], [], 'tr'), $r['hours'], $r['sales'] / 100, $r['bills'], $r['avg'] / 100, $r['voids']['n'], $r['voids']['amount'] / 100, $r['discount'] / 100, round($r['share'], 1),
            ], $rows), [3, 5, 7, 8], [24, 12, 8, 14, 8, 10, 10, 12, 12, 8])->download('personel-' . $p['first'] . '.xlsx');
        }
        $k = Reports::kpis($p['from'], $p['to']);
        $prev = Reports::kpis($p['prev_from'], $p['prev_to']);
        View::page('reports/staff', ['title' => I18n::t('rep.staff_t'), 'nav' => 'reports', 'back' => '/reports', 'p' => $p, 'rows' => $rows, 'k' => $k, 'prev' => $prev,
            'kitchen' => Reports::kitchen($p['from'], $p['to'])]);
    }

    // ------------------------------------------------------------ sales by item (no separate Figma frame; R6 table style)
    public function sales(Request $req): void
    {
        $key = in_array($req->str('p'), ['today', 'yesterday', '7d', 'month', 'last_month', 'custom'], true) ? $req->str('p') : 'month';
        $p = Reports::period($key, $req->str('from'), $req->str('to'));
        $items = Reports::byItem($p['from'], $p['to']);
        if ($req->str('x') === 'xlsx') {
            (new Xlsx())->sheet('Ürünler', ['Ürün', 'Kategori', 'Adet', 'Tutar'], array_map(static fn(array $r): array => [$r['name'], tn(json_arr($r['cat']), 'tr'), (float) $r['qty'], (int) $r['amount'] / 100], $items), [3], [34, 20, 10, 14])
                ->sheet('Saatler', ['Saat', 'Tutar'], array_map(static fn(int $h, int $v): array => [sprintf('%02d:00', $h), $v / 100], array_keys($hours = Reports::hourly($p['from'], $p['to'])), $hours), [1], [10, 14])
                ->download('satis-' . $p['first'] . '.xlsx');
        }
        View::page('reports/sales', ['title' => I18n::t('rep.sales_t'), 'nav' => 'reports', 'back' => '/reports', 'p' => $p, 'items' => $items, 'k' => Reports::kpis($p['from'], $p['to']),
            'channels' => Reports::channels($p['from'], $p['to']), 'hours' => Reports::hourly($p['from'], $p['to']), 'vat' => Reports::vatByRate($p['from'], $p['to']),
            'pay' => Reports::payments($p['from'], $p['to'])]);
    }

    // ------------------------------------------------------------ stock (no separate Figma frame)
    public function stock(Request $req): void
    {
        $key = in_array($req->str('p'), ['7d', 'month', 'last_month', 'custom'], true) ? $req->str('p') : 'month';
        $p = Reports::period($key, $req->str('from'), $req->str('to'));
        $rows = StockReport::moves($p['from'], $p['to']);
        if ($req->str('x') === 'xlsx') {
            (new Xlsx())->sheet('Stok', ['Kalem', 'Birim', 'Giriş', 'Satış', 'Fire', 'Sayım farkı', 'Eldeki', 'Değer'], array_map(static fn(array $r): array => [
                $r['name'], $r['unit'], $r['in'], $r['sale'], $r['waste'], $r['count'], $r['on_hand'], $r['value'] / 100,
            ], $rows), [7], [30, 8, 10, 10, 10, 12, 10, 14])->download('stok-' . $p['first'] . '.xlsx');
        }
        View::page('reports/stock', ['title' => I18n::t('rep.stock_t'), 'nav' => 'reports', 'back' => '/reports', 'p' => $p, 'rows' => $rows, 'tot' => StockReport::totals($p['from'], $p['to'])]);
    }

    // ------------------------------------------------------------ R4 / R5
    public function export(Request $req): void
    {
        $key = in_array($req->str('p'), ['month', 'last_month', 'quarter', 'custom'], true) ? $req->str('p') : 'last_month';
        $p = Reports::period($key, $req->str('from'), $req->str('to'));
        View::page('reports/export', ['title' => I18n::t('rep.exp_t'), 'nav' => 'reports', 'back' => '/reports', 'p' => $p, 'preview' => Accountant::preview($p['from'], $p['to']),
            'scripts' => ['js/reports.js']]);
    }

    public function exportPost(Request $req): void
    {
        $key = in_array($req->str('p'), ['month', 'last_month', 'quarter', 'custom'], true) ? $req->str('p') : 'last_month';
        $p = Reports::period($key, $req->str('from'), $req->str('to'));
        $parts = array_values(array_intersect(Accountant::PARTS, array_keys(array_filter($req->arr('parts')))));
        $formats = array_values(array_intersect(['xlsx', 'pdf', 'csv'], array_keys(array_filter($req->arr('formats')))));
        if (!$parts || !$formats) {
            throw new \InvalidArgumentException(I18n::t('rep.exp_pick'));
        }
        if ($req->str('action') === 'mail') {
            $ok = Accountant::mail($p, $parts, $formats, trim($req->str('email')));
            Response::json(['ok' => $ok, 'message' => I18n::t($ok ? 'rep.exp_mailed' : 'rep.exp_mail_fail', ['to' => trim($req->str('email'))])], $ok ? 200 : 422);
        }
        $file = Accountant::zip($p, $parts, $formats);
        if (!is_file($file)) {
            throw new HttpError(500);
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="muhasebe-' . $p['first'] . '_' . $p['last'] . '.zip"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file);
        exit;
    }
}
