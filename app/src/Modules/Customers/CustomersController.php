<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Customers;

use Sofrexa\Core\{Auth, HttpError, I18n, Request, Response, Settings, View};
use Sofrexa\Modules\Orders\{Accounts, Shifts};

/**
 * Customers: CU1/CU3 list, CU2/CU4 account (statement, collection, history), CU6 new / edit sheet,
 * CU7 tier sheet, CU5 loyalty program, printable statement.
 */
final class CustomersController
{
    private const FILTERS = ['debt', 'regular', 'online', 'blacklist'];

    public function index(Request $req): void
    {
        $filter = in_array($req->str('f'), self::FILTERS, true) ? $req->str('f') : '';
        $all = Customers::list();
        $rows = ($filter !== '' || $req->str('q') !== '') ? Customers::list(['q' => $req->str('q'), 'filter' => $filter]) : $all;
        View::page('customers/index', [
            'title' => I18n::t('cust.title'),
            'nav' => 'customers',
            'tab' => 'more',
            'rows' => $rows,
            'sum' => Customers::summary($all),
            'filter' => $filter,
            'q' => $req->str('q'),
            'scripts' => ['js/customers.js'],
        ]);
    }

    public function export(Request $req): void
    {
        $csv = Customers::csv(Customers::list(['q' => $req->str('q'), 'filter' => in_array($req->str('f'), self::FILTERS, true) ? $req->str('f') : '']));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="musteriler-' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
    }

    /** Picker of a bill (payment): any customer by name or phone. */
    public function search(Request $req): void
    {
        $rows = array_map(static function (array $c): array {
            $tier = $c['tier_id'] ? Loyalty::tierOf($c['id']) : null;
            return ['id' => $c['id'], 'name' => $c['name'], 'phone' => (string) $c['phone'],
                'sub' => implode(' · ', array_filter([(string) $c['phone'], $tier['name'] ?? '', (int) $c['points'] > 0 ? t('loy.points_n', ['n' => digits(\Sofrexa\Core\I18n::num((int) $c['points']))]) : ''])),
                'blacklist' => (bool) $c['blacklist']];
        }, Customers::search($req->str('q'), 8));
        Response::json(['ok' => true, 'rows' => $rows]);
    }

    // ------------------------------------------------------------ CU2 / CU4
    public function show(Request $req): void
    {
        $c = Customers::find($req->param('id'));
        $months = Customers::ledgerMonths($c['id']);
        $month = in_array($req->str('m'), $months, true) ? $req->str('m') : $months[0];
        $balance = Customers::balance($c['id']);
        $hasAccount = (bool) $c['credit_enabled'] || $balance !== 0 || count($months) > 1;
        $view = in_array($req->str('v'), ['account', 'orders', 'points'], true) ? $req->str('v') : ($hasAccount ? 'account' : 'orders');
        $address = Customers::addresses($c['id'])[0]['address'] ?? null;
        View::page('customers/show', [
            'title' => $c['name'],
            'nav' => 'customers',
            'c' => $c,
            'balance' => $balance,
            'hasAccount' => $hasAccount,
            'view' => $view,
            'month' => $month,
            'months' => $months,
            'ledger' => Customers::ledger($c['id'], $month),
            'recent' => Customers::recentMoves($c['id'], 5),
            'stats' => Customers::stats($c['id']),
            'spend' => Customers::monthSpend($c['id']),
            'lastPay' => Customers::lastPayment($c['id']),
            'since' => Customers::since($c),
            'address' => $address,
            'tier' => Loyalty::tierOf($c['id']),
            'points' => Loyalty::balance($c['id']),
            'discount' => Loyalty::discountParts($c['id']),
            'orders' => $view === 'orders' ? Customers::orders($c['id']) : [],
            'favs' => $view === 'orders' ? Customers::favourites($c['id']) : [],
            'life' => Customers::lifetime($c['id']),
            'history' => $view === 'points' ? Loyalty::history($c['id'], 60) : [],
            'online' => (bool) \Sofrexa\Core\Db::value('SELECT 1 FROM online_accounts WHERE customer_id = ? AND deleted = 0', [$c['id']]),
            'shift' => Shifts::currentId(),
            'scripts' => ['js/customers.js'],
        ]);
    }

    /** Printable statement of a month ("Ekstre yazdır"). */
    public function statement(Request $req): void
    {
        $c = Customers::find($req->param('id'));
        $month = preg_match('/^\d{4}-\d{2}$/', $req->str('m')) ? $req->str('m') : date('Y-m');
        View::page('customers/statement', [
            'title' => I18n::t('cust.statement_t', ['name' => $c['name']]),
            'bodyClass' => 'page-statement',
            'c' => $c,
            'month' => $month,
            'ledger' => Customers::ledger($c['id'], $month),
            'address' => Customers::addresses($c['id'])[0]['address'] ?? null,
            'profile' => Settings::all(),
        ], 'layouts/bare');
    }

    /** Sheets: edit (also "new"), tier (CU7), collect (desktop "Tahsilat al"), more (phone), points (adjust). */
    public function sheet(Request $req): void
    {
        $kind = $req->param('kind');
        $id = $req->param('id');
        $c = $id === 'new' ? null : Customers::find($id);
        if (!in_array($kind, ['edit', 'tier', 'collect', 'more', 'points'], true) || (!$c && $kind !== 'edit')) {
            throw new HttpError(404);
        }
        if (in_array($kind, ['tier', 'points'], true) && !Auth::can('customers.manage')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        if ($kind === 'collect' && !Auth::can('cash.pay')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $data = ['c' => $c, 'order' => $req->str('order'), 'name' => $req->str('name'), 'phone' => $req->str('phone')];
        if ($c) {
            $data += ['balance' => Customers::balance($c['id']), 'points' => Loyalty::balance($c['id']), 'tier' => Loyalty::tierOf($c['id']),
                'tiers' => Loyalty::tiers(), 'spend' => Loyalty::spend($c['id']), 'address' => Customers::addresses($c['id'])[0]['address'] ?? '',
                'recent' => $kind === 'collect' ? Customers::recentMoves($c['id'], 3) : [], 'shift' => Shifts::currentId()];
        }
        Response::json(['ok' => true, 'html' => View::partial('customers/_sheet_' . $kind, $data)]);
    }

    public function save(Request $req): void
    {
        $id = $req->str('id') ?: null;
        if ($id && !Auth::can('customers.manage') && Customers::find($id)) {
            // the cashier registers customers; editing someone else's details is the manager's
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $cid = Customers::save($req->all(), $id);
        $order = $req->str('order');
        if ($order !== '') {
            Loyalty::attach($order, $cid);
            Response::json(['ok' => true, 'id' => $cid, 'message' => I18n::t('cust.added_to_bill'), 'reload' => true]);
        }
        Response::json(['ok' => true, 'id' => $cid, 'message' => I18n::t($id ? 'cust.saved' : 'cust.created'), 'redirect' => $id ? null : '/customers/' . $cid, 'reload' => (bool) $id]);
    }

    public function delete(Request $req): void
    {
        Customers::delete($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('cust.deleted'), 'redirect' => '/customers']);
    }

    public function tier(Request $req): void
    {
        Loyalty::setTier($req->param('id'), $req->str('tier_id') ?: null, $req->bool('auto'), $req->str('note'));
        Response::json(['ok' => true, 'message' => I18n::t('cust.tier_saved'), 'reload' => true]);
    }

    public function collect(Request $req): void
    {
        $amount = \Sofrexa\Core\Money::parse($req->str('amount'));
        $balance = Accounts::settle($req->param('id'), $amount, $req->str('method'), $req->str('note'));
        Response::json(['ok' => true, 'message' => I18n::t('cust.collected', ['amount' => money($amount), 'balance' => money($balance)]), 'reload' => true]);
    }

    public function points(Request $req): void
    {
        $n = (int) preg_replace('/[^\d-]+/', '', $req->str('points'));
        Loyalty::adjust($req->param('id'), $req->str('dir') === 'minus' ? -abs($n) : abs($n), $req->str('note'));
        Response::json(['ok' => true, 'message' => I18n::t('loy.adjusted'), 'reload' => true]);
    }

    // ------------------------------------------------------------ CU5
    public function loyalty(Request $req): void
    {
        View::page('customers/loyalty', [
            'title' => I18n::t('loy.title'),
            'nav' => 'customers',
            'back' => '/customers',
            'kpi' => Loyalty::kpis(),
            'tiers' => Loyalty::tiers(),
            'top' => Loyalty::top(6),
            'set' => Settings::all(),
            'scripts' => ['js/customers.js'],
        ]);
    }

    public function saveLoyalty(Request $req): void
    {
        Loyalty::saveProgram($req->all());
        Response::json(['ok' => true, 'message' => I18n::t('loy.saved'), 'reload' => true]);
    }

    /** Name, colour and removal of a tier (the badge of a tier row). */
    public function tierSheet(Request $req): void
    {
        Response::json(['ok' => true, 'html' => View::partial('customers/_sheet_tiername', ['t' => Loyalty::tier($req->param('id')), 'count' => count(Loyalty::tiers())])]);
    }

    public function saveTierName(Request $req): void
    {
        $t = Loyalty::tier($req->param('id'));
        // the threshold goes back in lira, as the form would send it
        Loyalty::saveTier(['name' => $req->str('name'), 'tone' => $req->str('tone'), 'threshold' => (string) ((int) $t['threshold'] / 100)] + $t);
        Response::json(['ok' => true, 'message' => I18n::t('loy.saved'), 'reload' => true]);
    }

    public function deleteTier(Request $req): void
    {
        Loyalty::deleteTier($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('loy.tier_deleted'), 'reload' => true]);
    }
}
