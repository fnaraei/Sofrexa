<?php
declare(strict_types=1);

namespace Sofrexa\View;

use Sofrexa\Modules\Orders\Board;

/** Markup for the order components: TableTile (9:142), OrderLine (10:94), MenuItemTile (9:168), totals rows. */
final class OrderUi
{
    private const TILE_ICON = ['free' => 'users', 'occupied' => 'users', 'bill' => 'receipt', 'qr' => 'qr', 'ready' => 'bell', 'late' => 'flame'];

    /** TableTile. $t from Board::areas(); $short uses the desktop info text; $attrs e.g. href, data-*. */
    public static function tile(array $t, bool $short = false, bool $selected = false, array $attrs = []): string
    {
        $tag = isset($attrs['href']) ? 'a' : 'button';
        if ($tag === 'button') {
            $attrs += ['type' => 'button'];
        }
        $cls = 'ttile ttile--' . $t['state'] . ($selected ? ' is-selected' : '');
        return '<' . $tag . Ui::attrs($attrs + ['class' => $cls, 'data-table' => $t['id'], 'data-state' => $t['state']]) . '>'
            . '<span class="ttile__top"><span class="ttile__no num">' . e(digits($t['number'])) . '</span>' . icon(self::TILE_ICON[$t['state']], 18, 'ttile__ic') . '</span>'
            . '<span class="ttile__info">' . e($short ? $t['info_s'] : $t['info']) . '</span>'
            . '<span class="ttile__amount">' . ($t['amount'] !== '' ? e($t['amount']) : '&nbsp;') . '</span>'
            . '</' . $tag . '>';
    }

    /** OrderLine. $mods: show the options/note line; $attrs: e.g. data-line for a tappable row. */
    public static function line(array $l, bool $mods = true, array $attrs = []): string
    {
        [$st, $icon, $label] = Board::lineStatus($l);
        $modText = $mods ? Board::lineMods($l) : '';
        $status = '<span class="oline__status oline__status--' . $st . '">' . ($icon ? icon($icon, 14) : '<i class="oline__dot"></i>') . '<span>' . e($label) . '</span></span>';
        $tag = isset($attrs['data-line']) ? 'button' : 'div';
        if ($tag === 'button') {
            $attrs += ['type' => 'button'];
        }
        return '<' . $tag . Ui::attrs($attrs + ['class' => 'oline oline--' . $st]) . '>'
            . '<span class="oline__qty num">' . e(Board::qty((float) $l['qty'])) . '</span>'
            . '<span class="oline__mid"><span class="oline__name">' . e($l['name']) . '</span>'
            . ($modText !== '' ? '<span class="oline__mods">' . e($modText) . '</span>' : '') . $status . '</span>'
            . '<span class="oline__price num">' . e(money(Board::lineTotal($l))) . '</span>'
            . '</' . $tag . '>';
    }

    /** Lines in the Figma order: new first, then ready, sent, served, void. */
    public static function sorted(array $lines): array
    {
        $rank = ['new' => 0, 'ready' => 1, 'sent' => 2, 'served' => 3, 'void' => 4];
        usort($lines, static fn(array $a, array $b): int => [$rank[$a['status']] ?? 5, (int) $a['created_at']] <=> [$rank[$b['status']] ?? 5, (int) $b['created_at']]);
        return $lines;
    }

    /** MenuItemTile for the order screens (no photo). */
    public static function menuTile(array $i, float $inCart, array $attrs = []): string
    {
        $soldout = !$i['orderable'];
        $cls = 'mtile' . ($soldout ? ' is-soldout' : ($inCart > 0 ? ' is-incart' : ''));
        $qty = $inCart > 0 ? '<span class="mtile__qty num">' . e(digits(\Sofrexa\Modules\Orders\Orders::qtyText($inCart))) . '</span>' : '<span class="mtile__qty">' . icon('plus', 16) . '</span>';
        return '<button type="button"' . Ui::attrs($attrs + ['class' => $cls, 'data-item' => $i['id']]) . ($soldout ? ' disabled aria-disabled="true"' : '') . '>'
            . '<span class="mtile__name">' . e(tn($i['names'])) . '</span>'
            . '<span class="mtile__bottom"><span class="mtile__price num">' . ($soldout ? '<span class="c-danger">' . e(t('order.soldout')) . '</span>' : e(money((int) $i['price']))) . '</span>' . $qty . '</span>'
            . '</button>';
    }

    /** Totals rows: [label, value, class] — Body/M, the last one bigger. */
    public static function totals(array $o, string $totalLabelKey = 'order.total_vat', string $bigClass = 't-heading-l'): string
    {
        $h = '<div class="kv"><span>' . e(t('order.subtotal')) . '</span><span class="num">' . e(money((int) $o['subtotal'])) . '</span></div>';
        $h .= '<div class="kv"><span>' . e(t('order.discount')) . '</span><span class="num' . ((int) $o['discount'] > 0 ? ' c-success' : '') . '">' . ((int) $o['discount'] > 0 ? e(money(-(int) $o['discount'])) : '—') . '</span></div>';
        $h .= '<div class="kv kv--big ' . $bigClass . '"><span>' . e(t($totalLabelKey)) . '</span><span class="num">' . e(money((int) $o['total'])) . '</span></div>';
        return $h;
    }
}
