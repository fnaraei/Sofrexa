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
        (new \Sofrexa\Modules\Reports\DashboardController())->index($req);
    }

    /** Phone "More" tab: every section the user may open, as a list. */
    public function more(Request $req): void
    {
        View::page('home/more', ['title' => I18n::t('ui.more'), 'nav' => '', 'tab' => 'more', 'items' => Shell::navItems()]);
    }
}
