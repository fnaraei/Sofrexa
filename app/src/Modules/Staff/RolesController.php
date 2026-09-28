<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{Audit, Db, I18n, Perms, Request, Response, ValidationError, View};

/** ST5 — permission matrix: rows = permissions (grouped), columns = roles. The manager column is always all-on. */
final class RolesController
{
    public function index(Request $req): void
    {
        View::page('staff/roles', [
            'title' => I18n::t('roles.title'),
            'sub' => I18n::t('roles.sub'),
            'nav' => 'staff',
            'back' => '/staff',
            'roles' => self::roles(),
            'groups' => Perms::GROUPS,
        ]);
    }

    /** Roles shown as matrix columns (the courier role has no permissions of its own and is left out). */
    public static function roles(): array
    {
        $rows = Db::rows("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.active = 1 AND u.deleted = 0) AS people
            FROM roles r WHERE r.deleted = 0 AND r.code <> 'courier' ORDER BY r.sort, r.name");
        foreach ($rows as &$r) {
            $r['perms'] = json_arr($r['perms']);
            $r['label'] = Users::roleLabel($r['code'], $r['name']);
            $r['all'] = in_array('*', $r['perms'], true);
        }
        return $rows;
    }

    /** Saves the whole matrix: perms[role_id][] = permission codes. */
    public function save(Request $req): void
    {
        $name = trim($req->str('new_role'));
        if ($req->bool('create')) {
            if ($name === '') {
                throw new ValidationError(['new_role' => I18n::t('roles.err_name')]);
            }
            $id = Db::save('roles', ['code' => 'r' . substr(bin2hex(random_bytes(4)), 0, 7), 'name' => mb_substr($name, 0, 40), 'perms' => [], 'sort' => (int) Db::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM roles')]);
            Audit::log('role.save', $name . ' · ' . I18n::t('ui.new', [], 'tr'), 'role', $id);
            Response::json(['ok' => true, 'message' => I18n::t('roles.created'), 'redirect' => '/staff/roles']);
        }
        $matrix = $req->arr('perms');
        $changed = [];
        Db::tx(static function () use ($matrix, &$changed): void {
            foreach (self::roles() as $role) {
                if ($role['all']) {
                    continue;
                }
                $new = Perms::clean(array_map('strval', (array) ($matrix[$role['id']] ?? [])));
                $old = Perms::clean($role['perms']);
                sort($new);
                sort($old);
                if ($new !== $old) {
                    Db::save('roles', ['id' => $role['id'], 'perms' => $new]);
                    $label = static fn(array $ps): string => implode(', ', array_map(static fn(string $p): string => I18n::t('perm.' . $p, [], 'tr'), $ps));
                    $added = array_diff($new, $old);
                    $removed = array_diff($old, $new);
                    $changed[] = $role['name'] . ': ' . trim(($added ? '+ ' . $label($added) : '') . ($added && $removed ? ' · ' : '') . ($removed ? '− ' . $label($removed) : ''));
                }
            }
        });
        if ($changed) {
            Audit::log('role.save', implode(' · ', $changed), 'role', null, ['changes' => $changed]);
        }
        Response::json(['ok' => true, 'message' => I18n::t('roles.saved')]);
    }
}
