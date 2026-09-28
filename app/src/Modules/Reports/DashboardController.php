<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Reports;

use Sofrexa\Core\{I18n, Request, View};

/** The manager's overview: R1 (phone "Bugün") and R2 (desktop "Özet"), for today, yesterday, 7 days or this month. */
final class DashboardController
{
    public const PERIODS = ['today', 'yesterday', '7d', 'month'];

    public function index(Request $req): void
    {
        $key = in_array($req->str('p'), self::PERIODS, true) ? $req->str('p') : 'today';
        $p = Reports::period($key);
        $k = Reports::kpis($p['from'], $p['to']);
        $prev = Reports::kpis($p['prev_from'], $p['prev_to']);
        View::page('reports/dashboard', [
            'title' => I18n::t('nav.dashboard'),
            'nav' => 'dashboard',
            'tab' => 'dashboard',
            'p' => $p,
            'k' => $k,
            'prev' => $prev,
            'hours' => Reports::hourly($p['from'], $p['to']),
            'channels' => Reports::channels($p['from'], $p['to']),
            'pay' => Reports::payments($p['from'], $p['to']),
            'top' => Reports::topItems($p['from'], $p['to'], 5),
            'attention' => Reports::attention($p['from'], $p['to']),
            'open' => Reports::openTables(),
            'critical' => Reports::critical(),
            'voids' => Reports::voids($p['from'], $p['to']),
            'sync' => \Sofrexa\Sync\Status::get(),
        ]);
    }
}
