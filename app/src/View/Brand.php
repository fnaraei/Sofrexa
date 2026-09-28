<?php
declare(strict_types=1);

namespace Sofrexa\View;

use Sofrexa\Core\Settings;

/**
 * Tenant and platform branding. The restaurant (tenant) logo comes only from the restaurant
 * profile settings; Sofrexa appears as "POWERED BY SOFREXA" on login and customer pages.
 */
final class Brand
{
    /** Staff shell / login lockup: mark + NAME + tagline (Figma Logo/Basilic, sizes s and l). */
    public static function tenantLogo(string $size = 's'): string
    {
        $name = (string) Settings::get('profile.name');
        $short = (string) Settings::get('profile.short_name', '') ?: $name;
        $tag = (string) Settings::get('profile.tagline', '');
        $mark = (string) Settings::get('profile.mark_svg', '');
        $markHtml = '';
        if ($mark !== '') {
            $w = $size === 'l' ? 42 : 30;
            $markHtml = '<svg class="logo__mark" width="' . $w . '" height="' . round($w * 32 / 48) . '" viewBox="0 0 48 32" fill="none" stroke="var(--icon-accent)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" overflow="visible" aria-hidden="true">' . $mark . '</svg>';
        } elseif ($logo = self::logoUrl()) {
            $markHtml = '<img class="logo__img" src="' . e($logo) . '" alt="">';
        }
        // Upper-cased here, not with CSS: text-transform under lang="tr" would turn "i" into "İ".
        return '<span class="logo' . ($size === 'l' ? ' logo--l' : '') . '">' . $markHtml . '<span class="logo__word"><span class="logo__name">' . e(mb_strtoupper($short)) . '</span>'
            . ($tag !== '' ? '<span class="logo__tag">' . e(mb_strtoupper($tag)) . '</span>' : '') . '</span></span>';
    }

    public static function logoUrl(): ?string
    {
        $logo = (string) Settings::get('profile.logo', '');
        return $logo !== '' ? '/media/' . ltrim($logo, '/') : null;
    }

    public static function poweredBy(bool $dark = false): string
    {
        return '<span class="powered">' . e(t('brand.powered_by')) . ' <img src="' . e(asset($dark ? 'img/sofrexa-mark-on-dark.png' : 'img/sofrexa-mark.png')) . '" alt=""><b>SOFREXA</b></span>';
    }
}
