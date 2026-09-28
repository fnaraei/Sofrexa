<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Home;

use Sofrexa\Core\{Auth, I18n, Request, Response, View};
use Sofrexa\View\Shell;

/** Landing page after sign-in and the "More" tab on phones. */
final class HomeController
{
    public function index(Request $req): void
    {
        if (!Auth::can('reports.view')) {
            Response::redirect(Shell::home());
        }
        if (class_exists(\Sofrexa\Modules\Reports\DashboardController::class)) {
            (new \Sofrexa\Modules\Reports\DashboardController())->index($req);
            return;
        }
        View::page('home/index', ['title' => I18n::t('nav.dashboard'), 'nav' => 'dashboard']);
    }

    /** Phone "More" tab: every section the user may open, as a list. */
    public function more(Request $req): void
    {
        View::page('home/more', ['title' => I18n::t('ui.more'), 'nav' => '', 'tab' => 'more', 'items' => Shell::navItems()]);
    }
}
