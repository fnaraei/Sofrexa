<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{Auth, HttpError, I18n, Request, Response, View};

/** Staff: ST1 list, ST2 pay and bonus, ST3 my shift (clock in / out), ST4 edit with permissions. */
final class StaffController
{
    // ------------------------------------------------------------ ST1
    public function index(Request $req): void
    {
        $counts = Staff::counts();
        $f = $req->str('f', 'active');
        $f = in_array($f, ['active', 'shift', 'left'], true) || (str_starts_with($f, 'role:') && isset($counts['roles'][substr($f, 5)])) ? $f : 'active';
        View::page('staff/index', [
            'title' => I18n::t('staff.title'),
            'nav' => 'staff',
            'tab' => 'more',
            'rows' => Staff::list($f),
            'counts' => $counts,
            'filter' => $f,
            'inToday' => Staff::inToday(),
            'roles' => Users::roles(),
            'scripts' => ['js/users.js', 'js/staff.js'],
        ]);
    }

    // ------------------------------------------------------------ ST4
    public function edit(Request $req): void
    {
        $u = Staff::user($req->param('id'));
        View::page('staff/edit', [
            'title' => $u['name'],
            'nav' => 'staff',
            'u' => $u,
            'roles' => Users::roles(),
            'switches' => Staff::switches($u),
            'scripts' => ['js/users.js', 'js/staff.js'],
        ]);
    }

    public function save(Request $req): void
    {
        Staff::saveProfile($req->param('id'), $req->all());
        Response::json(['ok' => true, 'message' => I18n::t('staff.saved'), 'reload' => true]);
    }

    // ------------------------------------------------------------ ST2
    public function pay(Request $req): void
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $req->str('m')) ? $req->str('m') : date('Y-m');
        $rows = Staff::payroll($month);
        View::page('staff/pay', [
            'title' => I18n::t('pay2.title'),
            'nav' => 'staff',
            'back' => '/staff',
            'month' => $month,
            'rows' => $rows,
            'tot' => Staff::payrollTotals($rows),
            'scripts' => ['js/staff.js'],
        ]);
    }

    public function paySheet(Request $req): void
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $req->str('m')) ? $req->str('m') : date('Y-m');
        $ids = array_filter(explode(',', $req->str('ids')));
        $rows = array_values(array_filter(Staff::payroll($month), static fn(array $r): bool => in_array($r['id'], $ids, true)));
        if (!$rows) {
            throw new \InvalidArgumentException(I18n::t('pay2.pick'));
        }
        Response::json(['ok' => true, 'html' => View::partial('staff/_sheet_pay', ['rows' => $rows, 'month' => $month, 'shift' => \Sofrexa\Modules\Orders\Shifts::currentId()])]);
    }

    public function payPost(Request $req): void
    {
        $amounts = [];
        foreach ($req->arr('amount') as $uid => $v) {
            $amounts[(string) $uid] = \Sofrexa\Core\Money::parse((string) $v);
        }
        $sum = Staff::pay($req->str('month'), $amounts, $req->str('method'), $req->str('note'));
        Response::json(['ok' => true, 'message' => I18n::t('pay2.done', ['amount' => money($sum), 'n' => digits(count(array_filter($amounts)))]), 'reload' => true]);
    }

    public function payExport(Request $req): void
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $req->str('m')) ? $req->str('m') : date('Y-m');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="maas-prim-' . $month . '.csv"');
        echo Staff::csv($month, Staff::payroll($month));
        exit;
    }

    // ------------------------------------------------------------ ST3
    public function myShift(Request $req): void
    {
        $u = Staff::user(Auth::user()['id'] ?? throw new HttpError(403));
        View::page('staff/shift', [
            'title' => I18n::t('staff.my_shift'),
            'nav' => 'more',
            'u' => $u,
            'open' => Staff::openEntry($u['id']),
            'today' => Staff::today($u),
            'history' => Staff::history($u['id'], 5),
            'scripts' => ['js/staff.js'],
        ]);
    }

    public function clock(Request $req): void
    {
        $uid = Auth::user()['id'] ?? throw new HttpError(403);
        if ($req->param('kind') === 'in') {
            Staff::clockIn($uid);
            Response::json(['ok' => true, 'message' => I18n::t('staff.clocked_in'), 'reload' => true]);
        }
        $ms = Staff::clockOut($uid);
        Response::json(['ok' => true, 'message' => I18n::t('staff.clocked_out', ['d' => Staff::duration($ms)]), 'reload' => true]);
    }
}
