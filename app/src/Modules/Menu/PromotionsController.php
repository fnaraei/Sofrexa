<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Menu;

use Sofrexa\Core\{I18n, Request, Response, View};

/** Promotions (Figma PR1–PR4): the list, the editor (desktop dialog, phone page), the live preview, on/off and delete. */
final class PromotionsController
{
    public function index(Request $req): void
    {
        $f = in_array($req->str('f'), ['now', 'planned', 'off'], true) ? $req->str('f') : '';
        $all = array_map(static fn(array $p): array => $p + ['state' => Promotions::state($p)], Promotions::all());
        View::page('menu/promotions', [
            'title' => I18n::t('promo.title'),
            'nav' => 'menu',
            'rows' => array_values(array_filter($all, static fn(array $p): bool => $f === '' || ($f === 'off' ? in_array($p['state'], ['off', 'ended'], true) : $p['state'] === $f))),
            'all' => $all,
            'f' => $f,
            'stats' => Promotions::stats(),
            'scripts' => ['js/promo.js'],
        ]);
    }

    /** Desktop: the PR2 dialog. */
    public function sheet(Request $req): void
    {
        Response::json(['ok' => true, 'html' => View::partial('menu/_sheet_promo', $this->form($req->param('id')))]);
    }

    /** Phones: the PR4 page. */
    public function edit(Request $req): void
    {
        $data = $this->form($req->param('id'));
        View::page('menu/promo_edit', $data + [
            'title' => I18n::t($data['p']['id'] ? 'promo.edit' : 'promo.new'),
            'nav' => 'menu',
            'back' => '/menu/promotions',
            'scripts' => ['js/promo.js'],
        ]);
    }

    public function save(Request $req): void
    {
        $in = $req->all();
        $in['active'] = $req->bool('active');
        foreach (['targets', 'days', 'channels'] as $k) {
            $in[$k] = $req->arr($k);
        }
        Promotions::save($in);
        Response::json(['ok' => true, 'message' => I18n::t('promo.saved'), 'redirect' => '/menu/promotions']);
    }

    public function toggle(Request $req): void
    {
        Promotions::toggle($req->param('id'), $req->bool('on'));
        Response::json(['ok' => true]);
    }

    public function delete(Request $req): void
    {
        Promotions::delete($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('promo.deleted'), 'redirect' => '/menu/promotions']);
    }

    /** Live preview of the editor: "Margarita ₺650 → ₺520" and "Pepperoni ₺750 → ₺600 · 6 ürün · şu an aktif, 19:00'a kadar". */
    public function preview(Request $req): void
    {
        $in = ['scope' => $req->str('scope'), 'targets' => $req->arr('targets'), 'pct' => $req->str('pct')];
        $items = Promotions::affected($in);
        if (!$items || read_num($in['pct'], 'pct') <= 0) {
            Response::json(['ok' => true, 'title' => I18n::t('promo.preview_none'), 'sub' => '', 'n' => count($items)]);
        }
        $line = static fn(array $i): string => $i['name'] . ' ' . money($i['price']) . ' → ' . money($i['new']);
        $sub = array_filter([isset($items[1]) ? $line($items[1]) : '', I18n::t('promo.preview_n', ['n' => digits(count($items))])]);
        Response::json(['ok' => true, 'title' => $line($items[0]), 'sub' => implode(' · ', $sub), 'n' => count($items)]);
    }

    /** Editor data: the promotion (or new-promotion defaults), categories with counts and dishes. */
    private function form(string $id): array
    {
        $p = $id === 'new'
            ? ['id' => '', 'name' => '', 'names' => '{}', 'pct' => 10, 'scope' => 'all', 'targets' => '[]', 'days' => '[1,2,3,4,5,6,7]', 'time_from' => null, 'time_to' => null,
                'date_from' => null, 'date_to' => null, 'channels' => json_encode(Promotions::CHANNELS), 'active' => 1]
            : Promotions::get($id);
        return [
            'p' => $p,
            'categories' => array_values(array_filter(Menu::categories(), static fn(array $c): bool => (int) $c['item_count'] > 0)),
            'items' => array_map(static fn(array $i): array => ['id' => $i['id'], 'name' => tn($i['names']), 'cat' => tn($i['cat_names'])], Menu::items()),
            'state' => $p['id'] ? Promotions::state($p) : null,
        ];
    }
}
