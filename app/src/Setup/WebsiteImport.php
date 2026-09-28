<?php
declare(strict_types=1);

namespace Sofrexa\Setup;

use Sofrexa\Core\{App, Db, Settings};

/**
 * One-time import of the restaurant website's menu (categories, dishes, four languages, photos)
 * and brand (name, logo, leaf mark). Re-running updates the same rows (matched by legacy_id).
 *
 *   php app/bin/sofrexa import:website <website.sqlite> <website uploads dir> [--profile]
 */
final class WebsiteImport
{
    private const LANGS = ['tr', 'en', 'fa', 'ru'];

    /** Categories prepared at the bar (drinks, desserts); everything else goes to the kitchen. */
    private const BAR_SLUGS = ['desserts'];

    public static function run(string $dbPath, string $uploads, callable $say): void
    {
        if (!is_file($dbPath)) {
            throw new \InvalidArgumentException("Website database not found: $dbPath");
        }
        $src = new \PDO('sqlite:' . $dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $menuDir = App::storage('uploads') . '/menu';
        if (!is_dir($menuDir)) {
            mkdir($menuDir, 0775, true);
        }

        $catIds = [];
        $photos = 0;
        Db::tx(static function () use ($src, $uploads, $menuDir, $say, &$catIds, &$photos): void {
            foreach ($src->query('SELECT * FROM categories ORDER BY sort_order, id') as $c) {
                $legacy = 'web:cat:' . $c['id'];
                $row = [
                    'id' => Db::value('SELECT id FROM categories WHERE legacy_id = ?', [$legacy]) ?: null,
                    'slug' => $c['slug'],
                    'names' => self::langs($c, 'name'),
                    'descs' => self::langs($c, 'description'),
                    'station' => $c['section'] === 'drinks' || in_array($c['slug'], self::BAR_SLUGS, true) ? 'bar' : 'kitchen',
                    'section' => $c['section'],
                    'image' => $c['image'] ? 'menu/' . basename((string) $c['image']) : null,
                    'sort' => (int) $c['sort_order'],
                    'active' => (int) $c['is_active'],
                    'legacy_id' => $legacy,
                ];
                if ($row['id'] === null) {
                    unset($row['id']);
                }
                $catIds[$c['id']] = Db::save('categories', $row);
            }
            $say(count($catIds) . ' categories');

            $n = 0;
            foreach ($src->query('SELECT * FROM dishes ORDER BY category_id, sort_order, id') as $d) {
                if (!isset($catIds[$d['category_id']])) {
                    continue;
                }
                $legacy = 'web:dish:' . $d['id'];
                $existing = Db::row('SELECT id, price FROM items WHERE legacy_id = ?', [$legacy]);
                $image = null;
                if ($d['image']) {
                    $base = basename((string) $d['image']);
                    foreach (['400', '800'] as $size) {
                        $from = rtrim($uploads, '/\\') . '/dishes/' . $base . '-' . $size . '.webp';
                        if (is_file($from)) {
                            copy($from, $menuDir . '/' . $base . '-' . $size . '.webp');
                            $image = 'menu/' . $base;
                            $photos++;
                        }
                    }
                }
                $row = [
                    'category_id' => $catIds[$d['category_id']],
                    'slug' => $d['slug'],
                    'names' => self::langs($d, 'name'),
                    'descs' => self::langs($d, 'description'),
                    'price' => (int) round((float) $d['price'] * 100),
                    'image' => $image,
                    'portion' => $d['portion'] ?: null,
                    'allergens' => $d['allergens'] ?: null,
                    'flags' => array_filter([
                        'vegetarian' => (bool) $d['is_vegetarian'],
                        'spicy' => (bool) $d['is_spicy'],
                        'featured' => (bool) $d['is_featured'],
                        'new' => (bool) $d['is_new'],
                    ]),
                    'available' => (int) $d['is_available'],
                    'active' => (int) $d['is_active'],
                    'sort' => (int) $d['sort_order'],
                    'legacy_id' => $legacy,
                ];
                if ($existing) {
                    $row['id'] = $existing['id'];
                }
                Db::save('items', $row);
                $n++;
            }
            $say("$n items");
        });
        $say("$photos photos copied to storage/uploads/menu");

        // Brand: logo file and the leaf mark used by the staff app and receipts.
        $logo = dirname(rtrim($uploads, '/\\')) . '/assets/img/brand/logo-512.png';
        $brandDir = App::storage('uploads') . '/brand';
        if (!is_dir($brandDir)) {
            mkdir($brandDir, 0775, true);
        }
        $profile = [];
        if (is_file($logo)) {
            copy($logo, $brandDir . '/logo.png');
            $profile['profile.logo'] = 'brand/logo.png';
        }
        if (Settings::get('profile.name') === Settings::DEFAULTS['profile.name']) {
            $profile += [
                'profile.name' => 'Basilic Cafe & Restaurant',
                'profile.short_name' => 'Basilic',
                'profile.tagline' => 'Cafe & Restaurant',
                'profile.website' => 'https://basiliccaferestaurant.com',
                'profile.mark_svg' => '<path d="M3 29C6 17 17 6 45 3c-2 12-12 24-33 26-3.6.4-6.4.5-9 0Z"/><path d="M43 4.5C28 14 16 22 3 29L.8 31.2M9 17l3.5 7 6.5 4M18.3 9.5l3.7 8 8 5.2M30.2 5 32 11l6.2 3.8"/>',
            ];
        }
        if ($profile) {
            Settings::setMany($profile);
            $say('restaurant profile: ' . implode(', ', array_keys($profile)));
        }
    }

    private static function langs(array $row, string $field): array
    {
        $out = [];
        foreach (self::LANGS as $l) {
            $v = trim((string) ($row[$field . '_' . $l] ?? ''));
            if ($v !== '') {
                $out[$l] = $v;
            }
        }
        return $out;
    }
}
