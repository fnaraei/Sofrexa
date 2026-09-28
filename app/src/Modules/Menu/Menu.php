<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Menu;

use Sofrexa\Core\{App, Audit, Auth, Clock, Db, I18n, Money, Settings, ValidationError};

/**
 * Menu: categories, items (four languages, photo, price incl. VAT, station, visibility), option groups,
 * daily stock. The website menu, the QR menu and online ordering all read from here.
 */
final class Menu
{
    public const LANGS = ['tr', 'en', 'ru', 'fa'];

    // ------------------------------------------------------------ reading

    public static function categories(): array
    {
        return Db::rows('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id AND i.deleted = 0) AS item_count
            FROM categories c WHERE c.deleted = 0 ORDER BY c.sort, c.id');
    }

    /** Items with their category and today's stock state. $f: category, q, state (on | soldout | hidden). */
    public static function items(array $f = []): array
    {
        $where = ['i.deleted = 0'];
        $p = [];
        if (!empty($f['category'])) {
            $where[] = 'i.category_id = ?';
            $p[] = $f['category'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(i.names LIKE ? OR i.slug LIKE ?)';
            $p[] = '%' . $f['q'] . '%';
            $p[] = '%' . $f['q'] . '%';
        }
        $rows = Db::rows('SELECT i.*, c.names AS cat_names, c.station AS cat_station, c.vat_rate AS cat_vat, c.sort AS cat_sort
            FROM items i JOIN categories c ON c.id = i.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.sort, i.sort, i.id', $p);
        $sold = self::soldToday();
        $out = [];
        foreach ($rows as $r) {
            $r += self::state($r, $sold[$r['id']] ?? 0.0);
            $state = $f['state'] ?? '';
            if (($state === 'on' && !$r['orderable']) || ($state === 'soldout' && !$r['soldout']) || ($state === 'hidden' && ($r['show_web'] && $r['show_qr']))) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** available (manual switch), soldout (daily stock used up), left (null = unlimited), orderable. */
    public static function state(array $item, float $soldToday): array
    {
        $left = $item['daily_stock'] === null ? null : self::left((float) $item['daily_stock'] - $soldToday);
        $soldout = $left !== null && $left <= 0;
        return [
            'sold_today' => $soldToday,
            'left' => $left,
            'soldout' => $soldout,
            'orderable' => (bool) $item['available'] && (bool) $item['active'] && !$soldout,
            'station_eff' => $item['station'] ?: ($item['cat_station'] ?? 'kitchen'),
            'vat_eff' => $item['vat_rate'] ?? ($item['cat_vat'] ?? 0),
        ];
    }

    /**
     * What is left of a daily stock, to the half portion as it is sold: a whole number stays a whole number ("3 left"),
     * and nothing is rounded away — half a portion sent earlier still counts.
     */
    public static function left(float $left): int|float
    {
        $left = max(0.0, round($left, 3));
        return $left === floor($left) ? (int) $left : $left;
    }

    /**
     * item_id => portions taken from today's stock (the current business day): sent to the kitchen/bar and not cancelled,
     * plus cancelled ones that were (or may have been) cooked — thrown away, eaten by staff, or still waiting for the
     * till's answer. A cancelled dish that went back to stock uncooked gives its portion back; one that went to another
     * bill is counted there, on its new line. Read from the orders themselves, so the web copy (QR and online menus)
     * knows the same stock as the till.
     */
    public static function soldToday(): array
    {
        // by the time the dish went to the kitchen (a bill opened last night may send more after the day turned)
        $roll = (int) Settings::get('day.rollover_hour', 5);
        $day = Clock::day(Clock::ms(), $roll);
        [$from, $to] = Clock::dayRange($day, $roll);
        return array_map('floatval', Db::pairs("SELECT oi.item_id, SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE (oi.sent_at >= ? AND oi.sent_at < ? OR oi.sent_at IS NULL AND o.day = ?) AND oi.item_id IS NOT NULL AND oi.deleted = 0
              AND (oi.status IN ('sent', 'ready', 'served') AND o.status <> 'void'
                OR oi.status = 'void' AND oi.void_stock IN ('pending', 'waste', 'staff'))
            GROUP BY oi.item_id", [$from, $to, $day]));
    }

    /** Portions of a dish waiting unsent in open bills (they take from today's daily stock when they are sent). */
    public static function reserved(string $itemId): float
    {
        return (float) Db::value("SELECT COALESCE(SUM(l.qty), 0) FROM order_items l JOIN orders o ON o.id = l.order_id
            WHERE l.item_id = ? AND l.status = 'new' AND l.deleted = 0 AND o.status IN ('pending', 'open', 'billed') AND o.deleted = 0", [$itemId]);
    }

    public static function get(string $id): array
    {
        $r = Db::row('SELECT i.*, c.names AS cat_names, c.station AS cat_station, c.vat_rate AS cat_vat FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.deleted = 0', [$id]);
        if (!$r) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $r += self::state($r, self::soldToday()[$id] ?? 0.0);
        $r['groups'] = array_column(Db::rows('SELECT group_id FROM item_modifier_groups WHERE item_id = ? AND deleted = 0 ORDER BY sort', [$id]), 'group_id');
        $r['changed'] = Db::row('SELECT p.at, u.name FROM price_history p LEFT JOIN users u ON u.id = p.user_id WHERE p.item_id = ? ORDER BY p.at DESC LIMIT 1', [$id]);
        return $r;
    }

    public static function photoUrl(?string $image, int $size = 400): ?string
    {
        if (!$image) {
            return null;
        }
        $file = $image . '-' . $size . '.webp';
        return is_file(App::storage('uploads') . '/' . $file) ? '/media/' . $file . '?v=' . filemtime(App::storage('uploads') . '/' . $file) : null;
    }

    /** Option groups with their options. */
    public static function groups(): array
    {
        $groups = Db::rows('SELECT * FROM modifier_groups WHERE deleted = 0 ORDER BY sort, id');
        $opts = [];
        foreach (Db::rows('SELECT * FROM modifiers WHERE deleted = 0 ORDER BY sort, id') as $m) {
            $opts[$m['group_id']][] = $m;
        }
        foreach ($groups as &$g) {
            $g['options'] = $opts[$g['id']] ?? [];
        }
        return $groups;
    }

    // ------------------------------------------------------------ writing

    public static function saveItem(array $in): string
    {
        $id = (string) ($in['id'] ?? '');
        $old = $id !== '' ? Db::row('SELECT * FROM items WHERE id = ? AND deleted = 0', [$id]) : null;
        $names = self::langsIn($in['names'] ?? []);
        $descs = self::langsIn($in['descs'] ?? []);
        $errors = [];
        if (($names['tr'] ?? '') === '') {
            $errors['names[tr]'] = I18n::t('menu.err_name');
        }
        $cat = Db::row('SELECT id FROM categories WHERE id = ? AND deleted = 0', [(string) ($in['category_id'] ?? '')]);
        if (!$cat) {
            $errors['category_id'] = I18n::t('menu.err_category');
        }
        $price = Money::parse((string) ($in['price'] ?? ''));
        if ($price <= 0) {
            $errors['price'] = I18n::t('menu.err_price');
        }
        if ($errors) {
            throw new ValidationError($errors);
        }
        $vat = trim((string) ($in['vat_rate'] ?? ''));
        $row = [
            'category_id' => $cat['id'],
            'names' => $names,
            'descs' => $descs,
            'price' => $price,
            'station' => in_array($in['station'] ?? '', ['kitchen', 'bar'], true) ? $in['station'] : null,
            'vat_rate' => $vat === '' ? null : max(0, min(100, (float) str_replace(['%', ','], ['', '.'], $vat))),
            'prep_minutes' => ($in['prep_minutes'] ?? '') === '' ? null : max(0, min(240, (int) $in['prep_minutes'])),
            'available' => !empty($in['available']) ? 1 : 0,
            'show_web' => !empty($in['show_web']) ? 1 : 0,
            'show_qr' => !empty($in['show_qr']) ? 1 : 0,
            'show_online' => !empty($in['show_online']) ? 1 : 0,
        ];
        if (!$old) {
            $row['slug'] = self::slug($names['en'] ?? $names['tr']);
            $row['sort'] = (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE category_id = ?', [$cat['id']]);
        } else {
            $row['id'] = $id;
        }
        return Db::tx(static function () use ($row, $old, $in, $names): string {
            $id = Db::save('items', $row);
            if ($old && (int) $old['price'] !== $row['price']) {
                self::logPrice($id, tn($names, 'tr'), (int) $old['price'], $row['price']);
            }
            if (array_key_exists('groups', $in)) {
                self::setGroups($id, (array) $in['groups']);
            }
            Audit::log('menu.item', tn($names, 'tr') . ($old ? '' : ' · ' . I18n::t('ui.new', [], 'tr')), 'item', $id);
            return $id;
        });
    }

    public static function setGroups(string $itemId, array $groupIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('strval', $groupIds))));
        $current = Db::pairs('SELECT group_id, id FROM item_modifier_groups WHERE item_id = ? AND deleted = 0', [$itemId]);
        foreach ($current as $gid => $linkId) {
            if (!in_array($gid, $groupIds, true)) {
                Db::softDelete('item_modifier_groups', $linkId);
            }
        }
        foreach ($groupIds as $i => $gid) {
            Db::save('item_modifier_groups', (isset($current[$gid]) ? ['id' => $current[$gid]] : []) + ['item_id' => $itemId, 'group_id' => $gid, 'sort' => $i * 10]);
        }
    }

    public static function toggle(string $id, string $field, bool $on): void
    {
        if (!in_array($field, ['available', 'show_web', 'show_qr', 'show_online'], true)) {
            throw new \InvalidArgumentException('field');
        }
        $item = Db::row('SELECT names FROM items WHERE id = ? AND deleted = 0', [$id]);
        if (!$item) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        Db::save('items', ['id' => $id, $field => $on ? 1 : 0]);
        Audit::log('menu.item', tn($item['names'], 'tr') . ' · ' . $field . ' ' . ($on ? 'açık' : 'kapalı'), 'item', $id);
    }

    public static function deleteItem(string $id): void
    {
        $item = Db::row('SELECT names FROM items WHERE id = ? AND deleted = 0', [$id]);
        if ($item) {
            Db::softDelete('items', $id);
            Audit::log('menu.delete', tn($item['names'], 'tr'), 'item', $id);
        }
    }

    /** Photo upload: square-ish images are resized to 400 and 800 px WebP. */
    public static function photo(string $id, array $file): void
    {
        $item = Db::row('SELECT slug, names, image FROM items WHERE id = ? AND deleted = 0', [$id]);
        if (!$item || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > 8_388_608 || !is_uploaded_file($file['tmp_name'])) {
            throw new ValidationError(['photo' => I18n::t('menu.err_photo')]);
        }
        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) {
            throw new ValidationError(['photo' => I18n::t('menu.err_photo')]);
        }
        $base = 'menu/' . ($item['slug'] ?: substr($id, -8)) . '-' . date('YmdHis');
        foreach ([400, 800] as $w) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $nw = min($w, $sw);
            $nh = (int) round($sh * $nw / $sw);
            $img = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
            imagewebp($img, App::storage('uploads') . '/' . $base . '-' . $w . '.webp', 82);
        }
        Db::save('items', ['id' => $id, 'image' => $base]);
        Audit::log('menu.item', tn($item['names'], 'tr') . ' · fotoğraf', 'item', $id);
    }

    /**
     * Quick edit (M7/M8): [item_id => ['price' => '₺960', 'daily_stock' => '30' | ''], ...].
     * Returns the number of changed items. Old prices go to price_history and the activity log.
     */
    public static function quick(array $changes): int
    {
        $n = 0;
        $priceChanges = [];
        Db::tx(static function () use ($changes, &$n, &$priceChanges): void {
            foreach ($changes as $id => $c) {
                $item = Db::row('SELECT id, names, price, daily_stock FROM items WHERE id = ? AND deleted = 0', [(string) $id]);
                if (!$item) {
                    continue;
                }
                $row = [];
                if (isset($c['price']) && $c['price'] !== '') {
                    $price = Money::parse((string) $c['price']);
                    if ($price > 0 && $price !== (int) $item['price']) {
                        $row['price'] = $price;
                        self::logPrice($item['id'], tn($item['names'], 'tr'), (int) $item['price'], $price, false);
                        $priceChanges[] = tn($item['names'], 'tr') . ' ' . Money::fmt((int) $item['price'], false, 'tr') . ' → ' . Money::fmt($price, false, 'tr');
                    }
                }
                if (array_key_exists('daily_stock', $c)) {
                    $s = trim((string) $c['daily_stock']);
                    $stock = $s === '' || $s === '—' ? null : max(0, (int) $s);
                    if ($stock !== ($item['daily_stock'] === null ? null : (int) $item['daily_stock'])) {
                        $row['daily_stock'] = $stock;
                    }
                }
                if ($row) {
                    Db::save('items', ['id' => $item['id']] + $row);
                    $n++;
                }
            }
        });
        if ($priceChanges) {
            Audit::log(count($priceChanges) > 1 ? 'menu.bulk_price' : 'menu.price', implode(' · ', array_slice($priceChanges, 0, 8)) . (count($priceChanges) > 8 ? ' … (+' . (count($priceChanges) - 8) . ')' : ''), 'item', null, ['changes' => $priceChanges]);
        }
        return $n;
    }

    /** New price for a percentage change, rounded to the step (e.g. ₺10). */
    public static function adjusted(int $price, float $pct, int $stepKurus): int
    {
        $v = $price * (1 + $pct / 100);
        return $stepKurus > 0 ? (int) (round($v / $stepKurus) * $stepKurus) : (int) round($v);
    }

    private static function logPrice(string $itemId, string $name, int $old, int $new, bool $audit = true): void
    {
        Db::append('price_history', ['item_id' => $itemId, 'old_price' => $old, 'new_price' => $new, 'at' => Clock::ms(), 'user_id' => Auth::user()['id'] ?? null]);
        if ($audit) {
            Audit::log('menu.price', $name . ' ' . Money::fmt($old, false, 'tr') . ' → ' . Money::fmt($new, false, 'tr'), 'item', $itemId);
        }
    }

    public static function langsIn(mixed $in): array
    {
        $out = [];
        foreach (self::LANGS as $l) {
            $v = trim((string) (((array) $in)[$l] ?? ''));
            if ($v !== '') {
                $out[$l] = mb_substr($v, 0, 1000);
            }
        }
        return $out;
    }

    public static function slug(string $s): string
    {
        $s = strtolower(strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u']));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'item';
        $base = $s;
        for ($i = 2; Db::value('SELECT 1 FROM items WHERE slug = ?', [$s]); $i++) {
            $s = $base . '-' . $i;
        }
        return $s;
    }
}
