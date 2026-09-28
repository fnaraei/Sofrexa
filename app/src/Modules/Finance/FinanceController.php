<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Finance;

use Sofrexa\Core\{App, HttpError, I18n, Money, Request, Response, Settings, View};
use Sofrexa\Export\{Pdf, Xlsx};
use Sofrexa\Modules\Orders\Till;
use Sofrexa\Modules\Reports\Reports;

/** Finance: FI1 income and expenses, FI2 add an expense / income, FI3/FI4 profit and loss. */
final class FinanceController
{
    private const PERIODS = ['month', 'last_month', 'year', 'custom'];

    private function period(Request $req, string $default = 'month'): array
    {
        $key = in_array($req->str('p'), self::PERIODS, true) ? $req->str('p') : $default;
        return Reports::period($key, $req->str('from'), $req->str('to'));
    }

    // ------------------------------------------------------------ FI1
    public function index(Request $req): void
    {
        $p = $this->period($req);
        $rows = Finance::entries($p['from'], $p['to']);
        $f = in_array($req->str('f'), ['expense', 'income', 'auto'], true) ? $req->str('f') : '';
        View::page('finance/index', [
            'title' => I18n::t('fin.title'),
            'nav' => 'finance',
            'p' => $p,
            'rows' => array_values(array_filter($rows, static fn(array $r): bool => match ($f) {
                'expense', 'income' => $r['kind'] === $f,
                'auto' => $r['source'] !== 'manual',
                default => true,
            })),
            'sum' => Finance::summary($rows, $p['from'], $p['to']),
            'recurring' => Finance::recurring(),
            'filter' => $f,
            'scripts' => ['js/reports.js'],
        ]);
    }

    public function export(Request $req): void
    {
        $p = $this->period($req);
        $rows = Finance::entries($p['from'], $p['to']);
        (new Xlsx())->sheet('Gelir ve giderler', ['Tarih', 'Tür', 'Kategori', 'Açıklama', 'Ödeme', 'Kaynak', 'Tutar'], array_map(static fn(array $r): array => [
            date('d.m.Y', (int) strtotime($r['day'])), $r['kind'] === 'income' ? 'Gelir' : 'Gider', I18n::t('fin.cat.' . $r['category'], [], 'tr'), $r['text'],
            I18n::t('fin.m.' . $r['method'], [], 'tr'), I18n::t('fin.src.' . $r['source'], [], 'tr'), ($r['kind'] === 'income' ? 1 : -1) * $r['amount'] / 100,
        ], $rows), [6], [12, 8, 18, 40, 10, 16, 14])->download('gelir-gider-' . $p['first'] . '.xlsx');
    }

    // ------------------------------------------------------------ FI2
    public function form(Request $req): void
    {
        $kind = $req->str('kind') === 'income' ? 'income' : 'expense';
        View::page('finance/form', [
            'title' => I18n::t($kind === 'income' ? 'fin.add_income' : 'fin.add_expense'),
            'nav' => 'finance',
            'back' => '/finance',
            'kind' => $kind,
            'cats' => $kind === 'income' ? Finance::INCOME : Finance::categories(),
            'currencies' => Till::currencies(),
            'shift' => \Sofrexa\Modules\Orders\Shifts::currentId(),
            'scripts' => ['js/reports.js'],
        ]);
    }

    public function save(Request $req): void
    {
        $receipt = Till::savePhoto($_FILES['receipt'] ?? []);
        Finance::add($req->all(), $receipt);
        \Sofrexa\Core\Flash::set('success', I18n::t($req->str('kind') === 'income' ? 'fin.saved_income' : 'fin.saved_expense'));
        Response::json(['ok' => true, 'redirect' => '/finance']);
    }

    public function entry(Request $req): void
    {
        Response::json(['ok' => true, 'html' => View::partial('finance/_sheet_entry', ['f' => Finance::entry($req->param('id'))])]);
    }

    public function reverse(Request $req): void
    {
        Finance::reverse($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('fin.reversed'), 'reload' => true]);
    }

    /** The receipt photo of an expense (private files). */
    public function receipt(Request $req): void
    {
        $f = Finance::entry($req->param('id'));
        $path = $f['receipt'] ? App::storage('uploads/private/receipts') . '/' . basename((string) $f['receipt']) : '';
        if ($path === '' || !is_file($path)) {
            throw new HttpError(404);
        }
        header('Content-Type: ' . ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    public function stopRecurring(Request $req): void
    {
        Finance::stopRecurring($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('fin.rec_stopped'), 'reload' => true]);
    }

    // ------------------------------------------------------------ FI3 / FI4
    public function pl(Request $req): void
    {
        $p = $this->period($req);
        $cur = Finance::pl($p['from'], $p['to']);
        $prev = Finance::pl($p['prev_from'], $p['prev_to']);
        if ($req->str('x') === 'xlsx') {
            (new Xlsx())->sheet('Kâr zarar', ['Kalem', 'Bu dönem', 'Önceki dönem'], array_map(static fn(array $r): array => [$r[0], $r[1] / 100, $r[2] / 100], self::lines($cur, $prev)), [1, 2], [40, 16, 16])
                ->download('kar-zarar-' . $p['first'] . '.xlsx');
        }
        View::page('finance/pl', ['title' => I18n::t('fin.pl_t'), 'nav' => 'finance', 'p' => $p, 'cur' => $cur, 'prev' => $prev, 'split' => Finance::split($cur)]);
    }

    public function plPdf(Request $req): void
    {
        $p = $this->period($req);
        $cur = Finance::pl($p['from'], $p['to']);
        $prev = Finance::pl($p['prev_from'], $p['prev_to']);
        $name = (string) Settings::get('profile.name', '');
        $range = date('d.m.Y', (int) strtotime($p['first'])) . ' – ' . date('d.m.Y', (int) strtotime($p['last']));
        $pdf = new Pdf('Kâr / zarar · ' . $range, $name . ' · Sofrexa');
        $pdf->heading('Kâr / zarar', 18, $name . ' · ' . $range . ' · tutarlar KDV hariç · önceki dönemle karşılaştırma');
        $rows = [];
        foreach (self::lines($cur, $prev) as [$label, $a, $b, $type]) {
            $ch = Reports::change($a, $b);
            $rows[] = [$label, Money::fmt($a, false, 'tr'), Money::fmt($b, false, 'tr'), $ch === null ? '' : ($ch < 0 ? '−' : '+') . '%' . I18n::num(abs($ch), 1, 'tr')] + ($type !== 'line' ? ['_bold' => true, '_fill' => true] : []);
        }
        $pdf->table([['Kalem', 5], ['Bu dönem', 2, 'R'], ['Önceki', 2, 'R'], ['Değişim', 1.4, 'R']], $rows, 9);
        $pdf->paragraph('Malzeme alımları (' . Money::fmt($cur['purchases'], false, 'tr') . ') doğrudan gider sayılmaz: satılan ürünlerin reçete maliyeti ve fire olarak yansır.');
        $out = $pdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="kar-zarar-' . $p['first'] . '.pdf"');
        echo $out;
        exit;
    }

    /** Statement rows: [label, this, previous, type (line | sub | total)] in Turkish (exports). */
    private static function lines(array $a, array $b): array
    {
        $o = static fn(array $x, string $k): int => (int) $x['opex'][$k];
        return [
            ['Brüt satış (KDV dahil)', $a['gross_sales'], $b['gross_sales'], 'line'], ['− KDV', $a['vat'], $b['vat'], 'line'],
            ['Net satış', $a['net_sales'], $b['net_sales'], 'sub'], ['+ Diğer gelirler', $a['other'], $b['other'], 'line'],
            ['− Satılan malın maliyeti (reçeteden)', $a['cogs'], $b['cogs'], 'line'], ['− Fire / zayi', $a['waste'], $b['waste'], 'line'],
            ['Brüt kâr', $a['gross'], $b['gross'], 'sub'],
            ['− Personel (maaş + prim)', $o($a, 'staff'), $o($b, 'staff'), 'line'], ['− Kira', $o($a, 'rent'), $o($b, 'rent'), 'line'],
            ['− Enerji', $o($a, 'energy'), $o($b, 'energy'), 'line'], ['− Pazarlama', $o($a, 'marketing'), $o($b, 'marketing'), 'line'],
            ['− Bakım ve onarım', $o($a, 'maintenance'), $o($b, 'maintenance'), 'line'], ['− Muhasebe ve diğer', $o($a, 'other'), $o($b, 'other'), 'line'],
            ['Faaliyet giderleri', $a['opex_total'], $b['opex_total'], 'sub'], ['NET KÂR', $a['net'], $b['net'], 'total'],
        ];
    }
}
