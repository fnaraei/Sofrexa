<?php
declare(strict_types=1);

namespace Sofrexa\Integrations;

use Sofrexa\Core\{App, Db, Settings};
use Sofrexa\Modules\Menu\Menu;

/**
 * Keeps the restaurant website's own menu tables (categories, dishes) and photos in step with Sofrexa,
 * so the menu is edited in one place. Runs on the web copy, which sits on the same host as the website.
 *
 *   'website_menu' => [
 *       'dsn' => 'mysql:host=...;dbname=...;charset=utf8mb4', 'user' => '...', 'pass' => '...',
 *       'uploads' => '/home/.../public_html/uploads',   // the website's uploads folder
 *   ],
 *
 * Rows are matched through legacy ids ("web:cat:12", "web:dish:34") set by the first import, then by slug;
 * new Sofrexa items are inserted and get their website id back. Nothing is deleted on the website:
 * hidden or deleted items are switched off (is_active = 0).
 */
final class WebsiteMenu
{
    public static function configured(): bool
    {
        return (string) App::config('website_menu.dsn', '') !== '';
    }

    /** Marks the website menu as out of date; the next push handled by the web copy updates it. */
    public static function touch(): void
    {
        Db::exec("INSERT OR REPLACE INTO sync_state (key, value) VALUES ('website_menu_dirty', '1')");
    }

    public static function syncIfDirty(): ?array
    {
        if (!self::configured() || Db::value("SELECT value FROM sync_state WHERE key = 'website_menu_dirty'") !== '1') {
            return null;
        }
        $r = self::sync();
        Db::exec("DELETE FROM sync_state WHERE key = 'website_menu_dirty'");
        return $r;
    }

    public static function sync(): array
    {
        $cfg = (array) App::config('website_menu');
        $pdo = new \PDO((string) $cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $now = date('Y-m-d H:i:s');
        $stats = ['categories' => 0, 'dishes' => 0, 'inserted' => 0, 'photos' => 0];
        $pdo->beginTransaction();
        try {
            $catIds = [];
            foreach (Db::rows('SELECT * FROM categories ORDER BY sort, id') as $c) {
                $webId = self::webId($pdo, 'categories', $c['legacy_id'], 'web:cat:', $c['slug']);
                $row = self::langRow($c, 'name', 'names') + self::langRow($c, 'description', 'descs') + [
                    'section' => $c['section'] ?: ($c['station'] === 'bar' ? 'drinks' : 'food'),
                    'sort_order' => (int) $c['sort'],
                    'is_active' => $c['active'] && !$c['deleted'] ? 1 : 0,
                    'updated_at' => $now,
                ];
                if (!$webId) {
                    if ($c['deleted']) {
                        continue;
                    }
                    $webId = self::insert($pdo, 'categories', $row + ['slug' => self::freeSlug($pdo, 'categories', (string) $c['slug']), 'created_at' => $now]);
                    Db::save('categories', ['id' => $c['id'], 'legacy_id' => 'web:cat:' . $webId]);
                    $stats['inserted']++;
                }
                self::update($pdo, 'categories', $webId, $row);
                $catIds[$c['id']] = $webId;
                $stats['categories']++;
            }
            $sold = Menu::soldToday();
            foreach (Db::rows('SELECT i.*, c.station AS cat_station, c.vat_rate AS cat_vat FROM items i JOIN categories c ON c.id = i.category_id ORDER BY i.sort, i.id') as $i) {
                if (!isset($catIds[$i['category_id']])) {
                    continue;
                }
                $state = Menu::state($i, $sold[$i['id']] ?? 0.0);
                $flags = json_arr($i['flags']);
                $visible = $i['show_web'] && $i['active'] && !$i['deleted'];
                $webId = self::webId($pdo, 'dishes', $i['legacy_id'], 'web:dish:', $i['slug']);
                $row = self::langRow($i, 'name', 'names') + self::langRow($i, 'description', 'descs') + [
                    'category_id' => $catIds[$i['category_id']],
                    'price' => number_format($i['price'] / 100, 2, '.', ''),
                    'portion' => $i['portion'],
                    'allergens' => $i['allergens'],
                    'is_active' => $visible ? 1 : 0,
                    'is_available' => $state['orderable'] ? 1 : 0,
                    'is_featured' => !empty($flags['featured']) ? 1 : 0,
                    'is_new' => !empty($flags['new']) ? 1 : 0,
                    'is_vegetarian' => !empty($flags['vegetarian']) ? 1 : 0,
                    'is_spicy' => !empty($flags['spicy']) ? 1 : 0,
                    'sort_order' => (int) $i['sort'],
                    'updated_at' => $now,
                ];
                if ($i['image']) {
                    $base = basename((string) $i['image']);
                    $row['image'] = 'dishes/' . $base;
                    $stats['photos'] += self::copyPhoto((string) $i['image'], (string) ($cfg['uploads'] ?? ''), $base);
                }
                if (!$webId) {
                    if (!$visible) {
                        continue;
                    }
                    $webId = self::insert($pdo, 'dishes', $row + ['slug' => self::freeSlug($pdo, 'dishes', (string) $i['slug']), 'created_at' => $now]);
                    Db::save('items', ['id' => $i['id'], 'legacy_id' => 'web:dish:' . $webId]);
                    $stats['inserted']++;
                }
                self::update($pdo, 'dishes', $webId, $row);
                $stats['dishes']++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            App::log('website', 'menu sync failed: ' . $e->getMessage());
            throw $e;
        }
        App::log('website', 'menu synced', $stats);
        return $stats;
    }

    private static function webId(\PDO $pdo, string $table, ?string $legacy, string $prefix, ?string $slug): ?int
    {
        if ($legacy && str_starts_with($legacy, $prefix)) {
            $id = (int) substr($legacy, strlen($prefix));
            $st = $pdo->prepare("SELECT id FROM $table WHERE id = ?");
            $st->execute([$id]);
            if ($st->fetchColumn()) {
                return $id;
            }
        }
        if ($slug) {
            $st = $pdo->prepare("SELECT id FROM $table WHERE slug = ?");
            $st->execute([$slug]);
            $id = $st->fetchColumn();
            return $id ? (int) $id : null;
        }
        return null;
    }

    private static function langRow(array $r, string $col, string $json): array
    {
        $v = json_arr($r[$json]);
        $out = [];
        foreach (['tr', 'en', 'ru', 'fa'] as $l) {
            $out[$col . '_' . $l] = (string) ($v[$l] ?? ($col === 'name' ? ($v['tr'] ?? '') : ''));
        }
        return $out;
    }

    private static function insert(\PDO $pdo, string $table, array $row): int
    {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        return (int) $pdo->lastInsertId();
    }

    private static function update(\PDO $pdo, string $table, int $id, array $row): void
    {
        $set = implode(',', array_map(static fn(string $c): string => "$c = ?", array_keys($row)));
        $pdo->prepare("UPDATE $table SET $set WHERE id = ?")->execute([...array_values($row), $id]);
    }

    private static function freeSlug(\PDO $pdo, string $table, string $slug): string
    {
        $slug = $slug !== '' ? $slug : 'item';
        $base = $slug;
        $st = $pdo->prepare("SELECT 1 FROM $table WHERE slug = ?");
        for ($n = 2; $st->execute([$slug]) && $st->fetchColumn(); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /** Copies a Sofrexa photo (400/800) into the website's uploads/dishes (400/800/1400) when missing or older. */
    private static function copyPhoto(string $image, string $uploads, string $base): int
    {
        if ($uploads === '' || !is_dir($uploads)) {
            return 0;
        }
        $dir = rtrim($uploads, '/\\') . '/dishes';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $n = 0;
        foreach ([400 => 400, 800 => 800, 1400 => 800] as $size => $from) {
            $src = App::storage('uploads') . '/' . $image . '-' . $from . '.webp';
            $dst = $dir . '/' . $base . '-' . $size . '.webp';
            if (is_file($src) && (!is_file($dst) || filemtime($dst) < filemtime($src))) {
                copy($src, $dst);
                $n++;
            }
        }
        return $n;
    }
}
