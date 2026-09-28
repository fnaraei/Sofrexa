<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Menu;

use Sofrexa\Core\{Audit, Db, I18n, Request, Response, ValidationError, View};
use Sofrexa\Print\{EscPos, Printer, Spooler};
use Sofrexa\Support\Qr;

/** M1–M8: menu list, item editor, categories, option groups, areas and tables with QR, quick price and stock. */
final class MenuController
{
    // ------------------------------------------------------------ M1 / M3
    public function index(Request $req): void
    {
        $cat = $req->str('c');
        $state = in_array($req->str('s'), ['on', 'soldout', 'hidden'], true) ? $req->str('s') : '';
        $all = Menu::items();
        $items = Menu::items(['category' => $cat, 'q' => $req->str('q'), 'state' => $state]);
        View::page('menu/index', [
            'title' => I18n::t('menu.title'),
            'nav' => 'menu',
            'tab' => 'menu',
            'categories' => Menu::categories(),
            'items' => $items,
            'total' => count($all),
            'soldout' => count(array_filter($all, static fn(array $i): bool => $i['soldout'])),
            'cat' => $cat,
            'state' => $state,
            'q' => $req->str('q'),
            'scripts' => ['js/menu.js'],
        ]);
    }

    // ------------------------------------------------------------ M2 / M4
    public function edit(Request $req): void
    {
        $id = $req->param('id');
        $item = $id === 'new' ? null : Menu::get($id);
        View::page('menu/edit', [
            'title' => $item ? tn($item['names']) : I18n::t('menu.new_item'),
            'nav' => 'menu',
            'back' => '/menu',
            'item' => $item,
            'categories' => Menu::categories(),
            'groups' => Menu::groups(),
            'preselect' => $req->str('c'),
            'scripts' => ['js/menu.js'],
        ]);
    }

    public function save(Request $req): void
    {
        $id = Menu::saveItem($req->all());
        if (!empty($_FILES['photo']['name'])) {
            Menu::photo($id, $_FILES['photo']);
        }
        Response::json(['ok' => true, 'id' => $id, 'message' => I18n::t('menu.saved'), 'redirect' => $req->str('id') === '' ? '/menu/items/' . $id : null]);
    }

    public function photo(Request $req): void
    {
        Menu::photo($req->param('id'), $_FILES['photo'] ?? []);
        Response::json(['ok' => true, 'url' => Menu::photoUrl(Db::value('SELECT image FROM items WHERE id = ?', [$req->param('id')]), 800)]);
    }

    public function toggle(Request $req): void
    {
        Menu::toggle($req->param('id'), $req->str('field'), $req->bool('on'));
        Response::json(['ok' => true]);
    }

    public function delete(Request $req): void
    {
        Menu::deleteItem($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('menu.deleted'), 'redirect' => '/menu']);
    }

    // ------------------------------------------------------------ categories and option groups
    public function saveCategory(Request $req): void
    {
        $names = Menu::langsIn($req->arr('names'));
        if (($names['tr'] ?? '') === '') {
            throw new ValidationError(['names[tr]' => I18n::t('menu.err_name')]);
        }
        $id = $req->str('id');
        $vat = trim($req->str('vat_rate'));
        $row = ['names' => $names, 'station' => $req->str('station') === 'bar' ? 'bar' : 'kitchen', 'vat_rate' => $vat === '' ? 0 : max(0, min(100, (float) str_replace(',', '.', $vat))),
            'section' => $req->str('section') === 'drinks' ? 'drinks' : 'food', 'active' => $req->bool('active') ? 1 : 0];
        if ($id === '') {
            $row += ['slug' => Menu::slug($names['en'] ?? $names['tr']), 'sort' => (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories')];
        } else {
            $row['id'] = $id;
        }
        $id = Db::save('categories', $row);
        Audit::log('menu.category', $names['tr'], 'category', $id);
        Response::json(['ok' => true, 'message' => I18n::t('menu.saved')]);
    }

    public function deleteCategory(Request $req): void
    {
        $id = $req->param('id');
        if (Db::value('SELECT 1 FROM items WHERE category_id = ? AND deleted = 0 LIMIT 1', [$id])) {
            throw new \InvalidArgumentException(I18n::t('menu.err_cat_not_empty'));
        }
        Db::softDelete('categories', $id);
        Response::json(['ok' => true, 'message' => I18n::t('menu.deleted')]);
    }

    public function sortCategories(Request $req): void
    {
        foreach (array_values($req->arr('ids')) as $i => $id) {
            Db::save('categories', ['id' => (string) $id, 'sort' => ($i + 1) * 10]);
        }
        Response::json(['ok' => true]);
    }

    public function saveGroup(Request $req): void
    {
        $names = Menu::langsIn($req->arr('names'));
        if (($names['tr'] ?? '') === '') {
            throw new ValidationError(['names[tr]' => I18n::t('menu.err_name')]);
        }
        $kind = $req->str('kind') === 'multi' ? 'multi' : 'single';
        $id = $req->str('id');
        Db::tx(static function () use ($req, $names, $kind, &$id): void {
            $id = Db::save('modifier_groups', ($id !== '' ? ['id' => $id] : ['sort' => (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM modifier_groups')])
                + ['names' => $names, 'kind' => $kind, 'min_sel' => $kind === 'single' && $req->bool('required') ? 1 : 0, 'max_sel' => $kind === 'single' ? 1 : 99]);
            $keep = [];
            foreach (array_values($req->arr('options')) as $i => $o) {
                $oNames = Menu::langsIn($o['names'] ?? []);
                if (($oNames['tr'] ?? '') === '') {
                    continue;
                }
                $oid = (string) ($o['id'] ?? '');
                $keep[] = Db::save('modifiers', ($oid !== '' ? ['id' => $oid] : []) + ['group_id' => $id, 'names' => $oNames, 'price' => max(0, \Sofrexa\Core\Money::parse((string) ($o['price'] ?? '0'))), 'sort' => $i * 10]);
            }
            foreach (Db::rows('SELECT id FROM modifiers WHERE group_id = ? AND deleted = 0', [$id]) as $m) {
                if (!in_array($m['id'], $keep, true)) {
                    Db::softDelete('modifiers', $m['id']);
                }
            }
        });
        Audit::log('menu.options', $names['tr'], 'modifier_group', $id);
        Response::json(['ok' => true, 'id' => $id, 'message' => I18n::t('menu.saved')]);
    }

    public function deleteGroup(Request $req): void
    {
        $id = $req->param('id');
        Db::tx(static function () use ($id): void {
            foreach (Db::rows('SELECT id FROM item_modifier_groups WHERE group_id = ? AND deleted = 0', [$id]) as $l) {
                Db::softDelete('item_modifier_groups', $l['id']);
            }
            Db::softDelete('modifier_groups', $id);
        });
        Response::json(['ok' => true, 'message' => I18n::t('menu.deleted')]);
    }

    // ------------------------------------------------------------ M7 / M8
    public function quick(Request $req): void
    {
        $cat = $req->str('c');
        View::page('menu/quick', [
            'title' => I18n::t('quick.title'),
            'sub' => I18n::t('quick.sub'),
            'nav' => 'menu',
            'back' => '/menu',
            'categories' => Menu::categories(),
            'items' => Menu::items(['category' => $cat, 'q' => $req->str('q')]),
            'cat' => $cat,
            'q' => $req->str('q'),
            'mode' => $req->str('m') === 'stock' ? 'stock' : 'price',
            'scripts' => ['js/quick.js'],
        ]);
    }

    public function quickSave(Request $req): void
    {
        $n = Menu::quick($req->arr('items'));
        Response::json(['ok' => true, 'message' => I18n::t('quick.saved', ['n' => $n])]);
    }

    // ------------------------------------------------------------ M5 / M6
    public function floor(Request $req): void
    {
        $areas = Floor::areas();
        $sel = $req->str('t');
        $selected = null;
        foreach ($areas as $a) {
            foreach ($a['tables'] as $t) {
                if ($t['id'] === $sel || ($sel === '' && $selected === null)) {
                    $selected = $t + ['area' => $a];
                }
            }
        }
        View::page('menu/floor', [
            'title' => I18n::t('floor.title'),
            'nav' => 'tables',
            'tab' => 'more',
            'areas' => $areas,
            'counts' => Floor::counts(),
            'selected' => $selected,
            'scripts' => ['js/floor.js'],
        ]);
    }

    public function saveArea(Request $req): void
    {
        Floor::saveArea($req->all());
        Response::json(['ok' => true, 'message' => I18n::t('menu.saved')]);
    }

    public function deleteArea(Request $req): void
    {
        Floor::deleteArea($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('menu.deleted')]);
    }

    public function addTable(Request $req): void
    {
        $id = Floor::addTable($req->param('id'), $req->str('number') ?: null);
        Response::json(['ok' => true, 'id' => $id, 'redirect' => '/floor?t=' . $id]);
    }

    public function saveTable(Request $req): void
    {
        Floor::saveTable($req->param('id'), $req->all());
        Response::json(['ok' => true, 'message' => I18n::t('menu.saved')]);
    }

    public function deleteTable(Request $req): void
    {
        Floor::deleteTable($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('menu.deleted'), 'redirect' => '/floor']);
    }

    public function qrPng(Request $req): void
    {
        $t = Floor::table($req->param('id'));
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="masa-' . preg_replace('/\W+/', '-', mb_strtolower(tn($t['area_names'] ?: $t['area_name'], 'tr'))) . '-' . preg_replace('/\W+/', '', $t['number']) . '.png"');
        echo Qr::png(Floor::qrUrl($t), 14);
        exit;
    }

    /** Table card on the till printer: logo, area, table number, QR, "scan for the menu". */
    public function qrPrint(Request $req): void
    {
        $t = Floor::table($req->param('id'));
        $cfg = Printer::config('cashier');
        $p = new EscPos((int) $cfg['width']);
        $p->align('c');
        $logo = (string) \Sofrexa\Core\Settings::get('profile.logo', '');
        if ($logo !== '' && \Sofrexa\Core\Settings::get('receipt.logo', true)) {
            $p->image(\Sofrexa\Core\App::storage('uploads') . '/' . $logo, (int) $cfg['width'] === 58 ? 256 : 320);
        }
        $names = json_arr($t['area_names']);
        $p->bold()->text(mb_strtoupper(($names['tr'] ?? $t['area_name']) . (isset($names['en']) ? ' · ' . $names['en'] : ''), 'UTF-8'))
            ->size(2, 2)->text('MASA ' . $t['number'])->size()->bold(false)->feed(1)
            ->qr(Floor::qrUrl($t), 8)->feed(1)
            ->text('Menü için okutun · Scan for menu')->feed(3)->cut();
        Spooler::print('cashier', 'qr', $p->bytes(), $t['id']);
        Response::json(['ok' => true, 'message' => I18n::t('floor.printed')]);
    }
}
