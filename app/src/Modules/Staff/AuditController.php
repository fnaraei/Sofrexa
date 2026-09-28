<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{Audit, Clock, Db, I18n, Request, View};
use Sofrexa\Export\Xlsx;

/** Activity log — ST6 (desktop table) / ST7 (mobile list). Read-only: there is no edit or delete endpoint. */
final class AuditController
{
    private const PER_PAGE = 50;
    /** Filter chips in Figma order. */
    private const CHIPS = ['void', 'discount', 'price', 'cash', 'login'];

    public function index(Request $req): void
    {
        [$day, $from, $to] = self::day($req->str('day'));
        $group = in_array($req->str('g'), [...self::CHIPS, 'settings'], true) ? $req->str('g') : '';
        $q = mb_substr($req->str('q'), 0, 60);
        $page = max(1, $req->int('page', 1));

        [$where, $params] = self::where($from, $to, $group, $q);
        $total = (int) Db::value("SELECT COUNT(*) FROM audit_log a WHERE $where", $params);
        $rows = Db::rows("SELECT a.*, r.code AS role_code, r.name AS role_name FROM audit_log a
            LEFT JOIN users u ON u.id = a.user_id LEFT JOIN roles r ON r.id = u.role_id
            WHERE $where ORDER BY a.at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);

        $counts = ['' => (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE at >= ? AND at < ?', [$from, $to])];
        foreach (self::CHIPS as $g) {
            $counts[$g] = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE at >= ? AND at < ? AND action IN (' . Db::in(Audit::GROUPS[$g]) . ')', [$from, $to, ...Audit::GROUPS[$g]]);
        }
        $monday = new \DateTimeImmutable($day);
        $monday = $monday->modify('-' . ((int) $monday->format('N') - 1) . ' days')->format('Y-m-d');
        $weekFrom = Clock::dayRange($monday)[0];
        $week = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE at >= ? AND at < ?', [$weekFrom, $weekFrom + 7 * 86_400_000]);
        $isToday = $day === Clock::day(Clock::ms());

        View::page('staff/audit', [
            'title' => I18n::t('audit.title'),
            'sub' => I18n::t('audit.sub'),
            'nav' => 'staff',
            'back' => '/staff',
            'rows' => $rows,
            'total' => $total,
            'counts' => $counts,
            'week' => $week,
            'group' => $group,
            'q' => $q,
            'day' => $day,
            'dayMs' => $from,
            'isToday' => $isToday,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'chips' => self::CHIPS,
        ]);
    }

    public function export(Request $req): void
    {
        [$day, $from, $to] = self::day($req->str('day'));
        $group = in_array($req->str('g'), [...self::CHIPS, 'settings'], true) ? $req->str('g') : '';
        [$where, $params] = self::where($from, $to, $group, mb_substr($req->str('q'), 0, 60));
        $rows = (static function () use ($where, $params): \Generator {
            foreach (Db::rows("SELECT * FROM audit_log a WHERE $where ORDER BY a.at", $params) as $r) {
                yield [date('d.m.Y', intdiv($r['at'], 1000)), date('H:i:s', intdiv($r['at'], 1000)), $r['user_name'] ?? '', I18n::t(Audit::meta($r['action'])[0], [], 'tr'), $r['action'], $r['summary'] ?? '', $r['device'] ?? ''];
            }
        })();
        (new Xlsx())->sheet('Etkinlik ' . $day, ['Tarih', 'Saat', 'Personel', 'İşlem', 'Kod', 'Ayrıntı', 'Cihaz'], $rows, [], [12, 10, 22, 16, 20, 70, 18])
            ->download('etkinlik-kaydi-' . $day . '.xlsx');
    }

    /** Business day (rollover at the configured hour) → [Y-m-d, from ms, to ms]. */
    private static function day(string $in): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in) ? $in : Clock::day(Clock::ms());
        [$from, $to] = Clock::dayRange($day);
        return [$day, $from, $to];
    }

    private static function where(int $from, int $to, string $group, string $q): array
    {
        $where = 'a.at >= ? AND a.at < ?';
        $params = [$from, $to];
        if ($group !== '') {
            $where .= ' AND a.action IN (' . Db::in(Audit::GROUPS[$group]) . ')';
            $params = [...$params, ...Audit::GROUPS[$group]];
        }
        if ($q !== '') {
            $where .= " AND (a.summary LIKE ? ESCAPE '\\' OR a.user_name LIKE ? ESCAPE '\\' OR a.device LIKE ? ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [...$params, $like, $like, $like];
        }
        return [$where, $params];
    }
}
