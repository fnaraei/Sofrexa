<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{Auth, Clock, Db, HttpError, I18n, Request, Response, View};
use Sofrexa\Modules\Menu\Floor;

/** W1/W8/W9 (phone) and W11 (desktop) table map, the table panel and the table action sheets (W7). */
final class TablesController
{
    public function index(Request $req): void
    {
        $areas = Board::areas();
        $areaId = $req->str('a');
        if ($areaId !== 'all' && !in_array($areaId, array_column($areas, 'id'), true)) {
            $areaId = $areas[0]['id'] ?? 'all';
        }
        $filter = in_array($req->str('f'), ['mine', 'free', 'busy', 'bill'], true) ? $req->str('f') : '';
        $selected = null;
        if ($req->str('t') !== '') {
            foreach ($areas as $a) {
                foreach ($a['tables'] as $t) {
                    if ($t['id'] === $req->str('t')) {
                        $selected = $t + ['area' => $a];
                    }
                }
            }
        }
        $all = Board::counts($areas);
        View::page('orders/tables', [
            'title' => I18n::t('tables.title'),
            'nav' => 'tables',
            'tab' => 'tables',
            'areas' => $areas,
            'areaId' => $areaId,
            'filter' => $filter,
            'counts' => Board::counts($areas, $areaId === 'all' ? null : $areaId),
            'all' => $all,
            'avg' => Board::averageStay($areas),
            'selected' => $selected,
            'mineOpen' => (int) Db::value("SELECT COUNT(DISTINCT table_id) FROM orders WHERE waiter_id = ? AND status IN ('pending', 'open', 'billed') AND table_id IS NOT NULL AND deleted = 0", [Auth::user()['id']]),
            'scripts' => ['js/tables.js'],
        ]);
    }

    /** Desktop right panel for one table (W11), swapped in without a reload. */
    public function panel(Request $req): void
    {
        $t = $this->tableWithOrder($req->param('id'));
        Response::json(['ok' => true, 'html' => View::partial('orders/_table_panel', ['t' => $t])]);
    }

    /** W7 action sheet for a table (phone), or straight to the menu when the table is free. */
    public function actions(Request $req): void
    {
        $t = $this->tableWithOrder($req->param('id'));
        if (!$t['order']) {
            Response::json(['ok' => true, 'redirect' => '/tables/' . $t['id'] . '/order']);
        }
        Response::json(['ok' => true, 'html' => View::partial('orders/_table_actions', ['t' => $t])]);
    }

    /** Opens the order screen of a table: its open order, or an empty one that is created on the first item. */
    public function order(Request $req): void
    {
        $t = Floor::table($req->param('id'));
        $open = Orders::openForTable($t['id']);
        if ($open) {
            Response::redirect('/orders/' . $open['id']);
        }
        (new OrderController())->page(null, ['channel' => 'table', 'table' => $t, 'guests' => max(0, $req->int('g'))]);
    }

    /** Sub-sheets of W7: move, merge, split, guests, waiter, close. */
    public function sheet(Request $req): void
    {
        $o = Orders::openBill($req->param('id'));
        $kind = $req->param('kind');
        $data = ['o' => $o];
        switch ($kind) {
            case 'move':
                $data['areas'] = array_map(static fn(array $a): array => $a + ['free' => array_values(array_filter($a['tables'], static fn(array $t): bool => !$t['order']))], Board::areas());
                break;
            case 'merge':
                $data['targets'] = array_values(array_filter(Orders::open(['table', 'qr']), static fn(array $x): bool => $x['id'] !== $o['id'] && $x['table_id'] !== $o['table_id']));
                break;
            case 'waiter':
                $data['staff'] = Board::staff();
                break;
            case 'split':
            case 'guests':
            case 'close':
                break;
            default:
                throw new HttpError(404);
        }
        Response::json(['ok' => true, 'html' => View::partial('orders/_sheet_' . $kind, $data)]);
    }

    // ------------------------------------------------------------ actions

    public function move(Request $req): void
    {
        $id = $req->param('id');
        Orders::moveTable($id, $req->str('table_id')); // the split bills of the table go with it
        $n = (string) Db::value('SELECT number FROM tables WHERE id = ?', [$req->str('table_id')]);
        Response::json(['ok' => true, 'message' => I18n::t('move.done', ['n' => $n]), 'redirect' => '/tables?t=' . $req->str('table_id')]);
    }

    public function merge(Request $req): void
    {
        $into = Orders::get($req->str('into'));
        Orders::merge($req->param('id'), $into['id']);
        Response::json(['ok' => true, 'message' => I18n::t('merge.done', ['n' => $into['table_no']]), 'redirect' => '/tables?t=' . $into['table_id']]);
    }

    public function split(Request $req): void
    {
        $new = Orders::split($req->param('id'), $req->arr('lines'));
        $o = Orders::get($new);
        Response::json(['ok' => true, 'message' => I18n::t('split.done'), 'redirect' => Auth::can('cash.pay') ? '/cashier/pay/' . $new : '/tables?t=' . $o['table_id']]);
    }

    public function guests(Request $req): void
    {
        Orders::setGuests($req->param('id'), $req->int('guests'));
        Response::json(['ok' => true, 'reload' => true]);
    }

    public function waiter(Request $req): void
    {
        Orders::setWaiter($req->param('id'), $req->str('user_id'));
        $name = (string) Db::value('SELECT name FROM users WHERE id = ?', [$req->str('user_id')]);
        Response::json(['ok' => true, 'message' => I18n::t('waiter.done', ['name' => $name])]);
    }

    /** "Hesap iste": marks the bill as asked for and tells the till. */
    public function requestBill(Request $req): void
    {
        $o = Orders::requestBill($req->param('id'));
        Notify::push('bill', ['where' => Orders::where($o), 'by' => Auth::user()['name']], null, 'cashier', $o['id']);
        Response::json(['ok' => true, 'message' => I18n::t('bill.requested')]);
    }

    /** "Masayı kapat": cancels the unpaid bill (a reason is needed once something went to the kitchen). */
    public function close(Request $req): void
    {
        Orders::discard($req->param('id'), $req->str('reason'));
        Response::json(['ok' => true, 'message' => I18n::t('close.empty'), 'redirect' => '/tables']);
    }

    private function tableWithOrder(string $id): array
    {
        foreach (Board::areas() as $a) {
            foreach ($a['tables'] as $t) {
                if ($t['id'] === $id) {
                    if ($t['order']) {
                        $t['order'] = Orders::get($t['order']['id']) + ['state_total' => $t['order']['total']];
                    }
                    return $t + ['area' => $a];
                }
            }
        }
        throw new HttpError(404);
    }
}
