<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Menu;

use Sofrexa\Core\{Audit, Db, I18n, Settings, ValidationError};
use Sofrexa\Setup\Seed;

/**
 * Seating areas and tables (M5/M6). Every table has a permanent QR code: renaming an area or
 * renumbering a table never breaks a printed card (same rule and link format as the website: /menu?masa=code).
 */
final class Floor
{
    public static function areas(): array
    {
        $areas = Db::rows('SELECT * FROM areas WHERE deleted = 0 ORDER BY sort, id');
        $tables = [];
        foreach (Db::rows('SELECT * FROM tables WHERE deleted = 0 ORDER BY sort, CAST(number AS INTEGER), number') as $t) {
            $tables[$t['area_id']][] = $t;
        }
        foreach ($areas as &$a) {
            $a['tables'] = $tables[$a['id']] ?? [];
        }
        return $areas;
    }

    public static function counts(): array
    {
        return [
            'areas' => (int) Db::value('SELECT COUNT(*) FROM areas WHERE deleted = 0'),
            'tables' => (int) Db::value('SELECT COUNT(*) FROM tables t JOIN areas a ON a.id = t.area_id WHERE t.deleted = 0 AND a.deleted = 0'),
        ];
    }

    public static function table(string $id): array
    {
        $t = Db::row('SELECT t.*, a.name AS area_name, a.names AS area_names FROM tables t JOIN areas a ON a.id = t.area_id WHERE t.id = ? AND t.deleted = 0', [$id]);
        if (!$t) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        return $t;
    }

    /** Link printed on the table card. */
    public static function qrUrl(array $table): string
    {
        $base = rtrim((string) (Settings::get('qr.base_url') ?: Settings::get('profile.website') ?: \Sofrexa\Core\App::config('base_url')), '/');
        return $base . '/menu?masa=' . rawurlencode((string) $table['code']);
    }

    public static function saveArea(array $in): string
    {
        $names = Menu::langsIn($in['names'] ?? []);
        if (($names['tr'] ?? '') === '') {
            throw new ValidationError(['names[tr]' => I18n::t('floor.err_area')]);
        }
        $id = (string) ($in['id'] ?? '');
        $row = ['name' => $names['tr'], 'names' => $names, 'smoking' => !empty($in['smoking']) ? 1 : 0];
        if ($id === '') {
            $row['sort'] = (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM areas');
        } else {
            $row['id'] = $id;
        }
        $id = Db::save('areas', $row);
        Audit::log('menu.floor', $names['tr'], 'area', $id);
        return $id;
    }

    public static function deleteArea(string $id): void
    {
        $a = Db::row('SELECT name FROM areas WHERE id = ? AND deleted = 0', [$id]);
        if (!$a) {
            return;
        }
        if (Db::value("SELECT 1 FROM orders o JOIN tables t ON t.id = o.table_id WHERE t.area_id = ? AND o.status IN ('pending', 'open', 'billed') LIMIT 1", [$id])) {
            throw new \InvalidArgumentException(I18n::t('floor.err_open_orders'));
        }
        Db::tx(static function () use ($id): void {
            foreach (Db::rows('SELECT id FROM tables WHERE area_id = ? AND deleted = 0', [$id]) as $t) {
                Db::softDelete('tables', $t['id']);
            }
            Db::softDelete('areas', $id);
        });
        Audit::log('menu.floor', $a['name'] . ' · silindi', 'area', $id);
    }

    /** Adds the next free number in the area (the "+" tile), or a given one. */
    public static function addTable(string $areaId, ?string $number = null): string
    {
        if (!Db::value('SELECT 1 FROM areas WHERE id = ? AND deleted = 0', [$areaId])) {
            throw new \Sofrexa\Core\HttpError(404);
        }
        $existing = array_column(Db::rows('SELECT number FROM tables WHERE area_id = ? AND deleted = 0', [$areaId]), 'number');
        if ($number === null || trim($number) === '') {
            $n = 1;
            while (in_array((string) $n, $existing, true)) {
                $n++;
            }
            $number = (string) $n;
        }
        $number = mb_substr(trim(preg_replace('/\s+/u', ' ', $number) ?? ''), 0, 12);
        if (in_array($number, $existing, true)) {
            throw new ValidationError(['number' => I18n::t('floor.err_taken', ['n' => $number])]);
        }
        $id = Db::save('tables', ['area_id' => $areaId, 'number' => $number, 'code' => Seed::tableCode(), 'sort' => ctype_digit($number) ? (int) $number * 10 : 9999]);
        Audit::log('menu.floor', 'Masa ' . $number . ' eklendi', 'table', $id);
        return $id;
    }

    public static function saveTable(string $id, array $in): void
    {
        $t = self::table($id);
        $number = mb_substr(trim((string) ($in['number'] ?? $t['number'])), 0, 12);
        $areaId = (string) ($in['area_id'] ?? $t['area_id']);
        if ($number === '') {
            throw new ValidationError(['number' => I18n::t('ui.required')]);
        }
        if (Db::value('SELECT 1 FROM tables WHERE area_id = ? AND number = ? AND id <> ? AND deleted = 0', [$areaId, $number, $id])) {
            throw new ValidationError(['number' => I18n::t('floor.err_taken', ['n' => $number])]);
        }
        Db::save('tables', ['id' => $id, 'number' => $number, 'area_id' => $areaId, 'seats' => max(1, min(40, (int) ($in['seats'] ?? $t['seats']))), 'sort' => ctype_digit($number) ? (int) $number * 10 : 9999]);
        Audit::log('menu.floor', 'Masa ' . $t['number'] . ($t['number'] !== $number ? ' → ' . $number : ''), 'table', $id);
    }

    public static function deleteTable(string $id): void
    {
        $t = self::table($id);
        if (Db::value("SELECT 1 FROM orders WHERE table_id = ? AND status IN ('pending', 'open', 'billed') LIMIT 1", [$id])) {
            throw new \InvalidArgumentException(I18n::t('floor.err_open_orders'));
        }
        Db::softDelete('tables', $id);
        Audit::log('menu.floor', 'Masa ' . $t['number'] . ' silindi', 'table', $id);
    }
}
