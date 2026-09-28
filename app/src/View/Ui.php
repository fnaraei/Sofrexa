<?php
declare(strict_types=1);

namespace Sofrexa\View;

/**
 * HTML for the Figma components (Components page 4:4). Class names map 1:1 to app.css.
 * Every method returns a string; attributes in $a are escaped.
 */
final class Ui
{
    public static function attrs(array $a): string
    {
        $out = '';
        foreach ($a as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            $out .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
        }
        return $out;
    }

    /**
     * Button. $o: style primary|accent|secondary|ghost|danger, size l|m|s, icon, href, type, block, attrs.
     */
    public static function btn(string $label, array $o = []): string
    {
        $style = $o['style'] ?? 'primary';
        $size = $o['size'] ?? 'm';
        $cls = 'btn btn--' . $style . ($size !== 'm' ? ' btn--' . $size : '') . (!empty($o['block']) ? ' btn--block' : '') . (isset($o['class']) ? ' ' . $o['class'] : '');
        $iconSize = ['l' => 20, 'm' => 18, 's' => 16][$size];
        $inner = (isset($o['icon']) ? icon($o['icon'], $iconSize) : '') . '<span>' . e($label) . '</span>';
        $attrs = ($o['attrs'] ?? []) + ['class' => $cls];
        if (isset($o['href'])) {
            return '<a' . self::attrs(['href' => $o['href']] + $attrs) . '>' . $inner . '</a>';
        }
        return '<button' . self::attrs(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $inner . '</button>';
    }

    public static function ibtn(string $icon, string $label, array $o = []): string
    {
        $style = $o['style'] ?? 'ghost';
        $size = $o['size'] ?? 'm';
        $cls = 'ibtn ibtn--' . $style . ($size !== 'm' ? ' ibtn--' . $size : '') . (isset($o['class']) ? ' ' . $o['class'] : '');
        $attrs = ($o['attrs'] ?? []) + ['class' => $cls, 'aria-label' => $label, 'title' => $label];
        $ic = icon($icon, ['l' => 24, 'm' => 20, 's' => 18][$size]);
        if (isset($o['href'])) {
            return '<a' . self::attrs(['href' => $o['href']] + $attrs) . '>' . $ic . '</a>';
        }
        return '<button' . self::attrs(['type' => $o['type'] ?? 'button'] + $attrs) . '>' . $ic . '</button>';
    }

    /** Badge tones: neutral accent success warning danger attention info solid. */
    public static function badge(string $label, string $tone = 'neutral', bool $dot = false): string
    {
        $tone = strtolower($tone);
        return '<span class="badge' . ($tone !== 'neutral' ? ' badge--' . e($tone) : '') . '">' . ($dot ? '<i class="badge__dot"></i>' : '') . e($label) . '</span>';
    }

    public static function chip(string $label, bool $selected = false, ?string $count = null, array $attrs = []): string
    {
        $tag = isset($attrs['href']) ? 'a' : 'button';
        if ($tag === 'button') {
            $attrs += ['type' => 'button'];
        }
        return '<' . $tag . self::attrs($attrs + ['class' => 'chip' . ($selected ? ' is-selected' : '')]) . '>' . e($label)
            . ($count !== null ? '<span class="chip__count">' . e($count) . '</span>' : '') . '</' . $tag . '>';
    }

    /** Radio chip for forms. */
    public static function chipRadio(string $name, string $value, string $label, bool $checked = false): string
    {
        return '<label class="chip"><input type="radio" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '>' . e($label) . '</label>';
    }

    /** Segmented control. $items: value => label. Radio when $name is set, links when values are URLs. */
    public static function segs(array $items, string $active, ?string $name = null, bool $hug = false): string
    {
        $h = '<div class="segs' . ($hug ? ' segs--hug' : '') . '" role="tablist">';
        foreach ($items as $value => $label) {
            $value = (string) $value;
            if ($name !== null) {
                $h .= '<label class="seg"><input type="radio" name="' . e($name) . '" value="' . e($value) . '"' . ($value === $active ? ' checked' : '') . '>' . e($label) . '</label>';
            } else {
                $h .= '<a class="seg' . ($value === $active ? ' is-active' : '') . '" href="' . e($value) . '">' . e($label) . '</a>';
            }
        }
        return $h . '</div>';
    }

    /** Text field. $o: label, labelM (phone label when it differs), icon, type, value, placeholder, help, error, attrs, textarea, suffix, class. */
    public static function field(string $name, array $o = []): string
    {
        $id = $o['id'] ?? 'f-' . preg_replace('/\W+/', '-', $name);
        $attrs = ($o['attrs'] ?? []) + [
            'id' => $id, 'name' => $name, 'type' => $o['type'] ?? 'text', 'value' => $o['value'] ?? '',
            'placeholder' => $o['placeholder'] ?? null, 'autocomplete' => $o['autocomplete'] ?? 'off',
        ];
        $control = !empty($o['textarea'])
            ? '<textarea' . self::attrs(array_diff_key($attrs, ['type' => 1, 'value' => 1])) . '>' . e($o['value'] ?? '') . '</textarea>'
            : '<input' . self::attrs($attrs) . '>';
        $cls = 'field' . (isset($o['error']) ? ' is-error' : '') . (isset($o['class']) ? ' ' . $o['class'] : '') . (($o['type'] ?? '') === 'search' ? ' field--search' : '');
        return '<div class="' . $cls . '">'
            . (isset($o['label']) && $o['label'] !== '' ? '<label class="field__label" for="' . e($id) . '">' . (isset($o['labelM']) ? '<span class="only-desktop">' . e($o['label']) . '</span><span class="only-mobile">' . e($o['labelM']) . '</span>' : e($o['label'])) . '</label>' : '')
            . '<div class="field__box">' . (isset($o['icon']) ? icon($o['icon'], 20) : '') . $control
            . (isset($o['suffix']) ? '<span class="field__suffix">' . e($o['suffix']) . '</span>' : '') . '</div>'
            . (isset($o['error']) ? '<div class="field__help">' . e($o['error']) . '</div>' : (isset($o['help']) ? '<div class="field__help">' . e($o['help']) . '</div>' : ''))
            . '</div>';
    }

    public static function select(string $name, array $options, string $selected, array $o = []): string
    {
        $id = $o['id'] ?? 'f-' . preg_replace('/\W+/', '-', $name);
        $h = '<div class="field">' . (isset($o['label']) ? '<label class="field__label" for="' . e($id) . '">' . e($o['label']) . '</label>' : '')
            . '<div class="field__box">' . (isset($o['icon']) ? icon($o['icon'], 20) : '') . '<select' . self::attrs(($o['attrs'] ?? []) + ['id' => $id, 'name' => $name]) . '>';
        foreach ($options as $v => $l) {
            $h .= '<option value="' . e($v) . '"' . ((string) $v === $selected ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        return $h . '</select>' . icon('chevron-down', 18) . '</div></div>';
    }

    public static function toggle(string $name, bool $on, array $attrs = []): string
    {
        return '<span class="toggle"><input type="checkbox" role="switch"' . self::attrs($attrs + ['name' => $name, 'value' => '1']) . ($on ? ' checked' : '') . '><span class="toggle__track"></span></span>';
    }

    public static function toggleRow(string $name, string $title, ?string $sub, bool $on, array $attrs = []): string
    {
        return '<label class="trow"><span class="trow__text"><span class="trow__title">' . e($title) . '</span>'
            . ($sub ? '<span class="trow__sub">' . e($sub) . '</span>' : '') . '</span>' . self::toggle($name, $on, $attrs) . '</label>';
    }

    public static function checkbox(string $name, bool $on, array $attrs = []): string
    {
        return '<span class="check"><input type="checkbox"' . self::attrs($attrs + ['name' => $name, 'value' => '1']) . ($on ? ' checked' : '') . '><span class="check__box">' . icon('check', 16) . '</span></span>';
    }

    public static function avatar(string $name, string $size = 'm'): string
    {
        return '<span class="avatar' . ($size !== 'm' ? ' avatar--' . $size : '') . '">' . e(initials($name)) . '</span>';
    }

    public static function who(string $name, ?string $sub = null, string $size = 's'): string
    {
        return '<span class="who' . ($size === 'm' ? ' who--m' : '') . '">' . self::avatar($name, $size) . '<span class="col gap-2" style="gap:0;min-width:0"><span class="who__name ellipsis">' . e($name) . '</span>'
            . ($sub !== null && $sub !== '' ? '<span class="who__sub ellipsis">' . e($sub) . '</span>' : '') . '</span></span>';
    }

    /** Stat card. $o: delta, down (bool), brand (bool), id. */
    public static function stat(string $label, string $value, array $o = []): string
    {
        $delta = isset($o['delta']) && $o['delta'] !== ''
            ? '<div class="stat__delta' . (!empty($o['down']) ? ' is-down' : '') . (!empty($o['icon']) ? ' is-plain' : '') . '">' . icon($o['icon'] ?? (!empty($o['down']) ? 'arrow-down' : 'trend-up'), 14) . '<span>' . e($o['delta']) . '</span></div>' : '';
        return '<div class="stat' . (!empty($o['brand']) ? ' stat--brand' : '') . '"' . (isset($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '><div class="stat__label">' . e($label) . '</div><div class="stat__value num">' . e($value) . '</div>' . $delta . '</div>';
    }

    public static function banner(string $title, string $text, string $tone = 'info', string $icon = 'info'): string
    {
        return '<div class="banner' . ($tone !== 'info' ? ' banner--' . e($tone) : '') . '" role="status">' . icon($icon, 20) . '<div class="col" style="gap:2px"><div class="banner__title">' . e($title) . '</div><div class="banner__text">' . e($text) . '</div></div></div>';
    }

    /** SyncStatus badge; without a state it shows the live one (Sync\Status). */
    public static function sync(string $state = ''): string
    {
        if ($state === '') {
            $state = \Sofrexa\Sync\Status::get()['state'];
        }
        [$icon, $key] = match ($state) {
            'syncing' => ['refresh', 'sync.syncing'],
            'offline' => ['wifi-off', 'sync.offline'],
            default => ['cloud-check', 'sync.online'],
        };
        return '<span class="sync sync--' . e($state) . '" data-sync>' . icon($icon, 16) . '<span>' . e(t($key)) . '</span></span>';
    }

    /** Option tile (payment method, courier, choices). Radio when $name is given. */
    public static function opt(string $label, ?string $sub, string $icon, bool $selected = false, ?string $name = null, ?string $value = null, array $attrs = []): string
    {
        $input = $name !== null ? '<input type="radio" name="' . e($name) . '" value="' . e($value ?? $label) . '"' . ($selected ? ' checked' : '') . '>' : '';
        $tag = $name !== null ? 'label' : 'button';
        if ($tag === 'button') {
            $attrs += ['type' => 'button'];
        }
        return '<' . $tag . self::attrs($attrs + ['class' => 'opt' . ($selected && $name === null ? ' is-selected' : '')]) . '>' . $input . icon($icon, 24)
            . '<span class="opt__label">' . e($label) . '</span>' . ($sub !== null && $sub !== '' ? '<span class="opt__sub">' . e($sub) . '</span>' : '') . '</' . $tag . '>';
    }

    /** List row. $o: icon, sub, trail, href, chevron, attrs. */
    public static function lrow(string $title, array $o = []): string
    {
        $tag = isset($o['href']) ? 'a' : (isset($o['attrs']['data-action']) ? 'button' : 'div');
        $attrs = ($o['attrs'] ?? []) + ['class' => 'lrow'] + (isset($o['href']) ? ['href' => $o['href']] : []) + ($tag === 'button' ? ['type' => 'button'] : []);
        return '<' . $tag . self::attrs($attrs) . '>'
            . (isset($o['icon']) ? '<span class="lrow__lead">' . icon($o['icon'], 20) . '</span>' : ($o['lead'] ?? ''))
            . '<span class="lrow__mid"><span class="lrow__title ellipsis">' . e($title) . '</span>' . (isset($o['sub']) && $o['sub'] !== '' ? '<span class="lrow__sub">' . e($o['sub']) . '</span>' : '') . '</span>'
            . (isset($o['trail']) ? '<span class="lrow__trail' . (isset($o['trailClass']) ? ' ' . e($o['trailClass']) : '') . '">' . e($o['trail']) . '</span>' : ($o['trailHtml'] ?? ''))
            . (!empty($o['chevron']) || isset($o['href']) ? '<span class="lrow__chev">' . icon('chevron-right', 20) . '</span>' : '')
            . '</' . $tag . '>';
    }

    public static function sheetHead(string $title): string
    {
        return '<div class="sheet__handle"></div><div class="sheet__head"><h2 class="sheet__title">' . e($title) . '</h2><button type="button" class="sheet__close" data-close aria-label="' . e(t('ui.close')) . '">' . icon('close', 20) . '</button></div>';
    }

    /** Header row of a desktop page (title, subtitle, actions). */
    public static function pageHead(string $title, ?string $sub = null, string $actions = ''): string
    {
        return '<div class="page-head"><div class="page-head__titles"><h1 class="t-heading-xl">' . e($title) . '</h1>'
            . ($sub ? '<p class="t-body-m c-muted">' . e($sub) . '</p>' : '') . '</div>' . $actions . '</div>';
    }

    public static function hiddens(array $values): string
    {
        $h = '';
        foreach ($values as $k => $v) {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        return $h;
    }
}
