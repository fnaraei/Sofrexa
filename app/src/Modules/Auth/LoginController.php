<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Auth;

use Sofrexa\Core\{App, Auth, Db, I18n, RateLimit, Request, Response, Turnstile, View};
use Sofrexa\View\Shell;

/** PIN sign-in on restaurant devices (L1–L3) and e-mail + password sign-in for remote managers. */
final class LoginController
{
    public function show(Request $req): void
    {
        $next = self::safeNext($req->str('next', '/'));
        if (Auth::user()) {
            Response::redirect($next !== '/' ? $next : Shell::home());
        }
        $staff = self::staff();
        // PIN sign-in: on the till PC's network, and on the web copy while emergency mode lets the staff work there
        $pinHere = Auth::pinAllowedFrom($req->ip()) && (App::isPc() || \Sofrexa\Sync\Emergency::on());
        $mode = $req->str('mode') === 'password' || !$pinHere || !$staff ? 'password' : 'pin';
        if (!$staff && !Db::value('SELECT 1 FROM users WHERE deleted = 0 LIMIT 1')) {
            $mode = 'pin'; // shows the "run seed" hint
        }
        View::page('auth/login', [
            'title' => I18n::t('login.title'),
            'staff' => $staff,
            'mode' => $mode,
            'next' => $next,
            'hasPin' => $pinHere && $staff,
            'turnstile' => Turnstile::enabled() && !Auth::pinAllowedFrom($req->ip()) ? Turnstile::siteKey() : '',
            'scripts' => ['js/login.js'],
        ], 'layouts/bare');
    }

    public function pin(Request $req): void
    {
        if (!RateLimit::hit('pin:' . $req->ip(), 30, 60_000)) {
            Response::json(['ok' => false, 'error' => I18n::t('err.rate')], 429);
        }
        $r = Auth::loginPin($req->str('user_id'), preg_replace('/\D/', '', $req->str('pin')) ?? '', $req->ip());
        if (!$r['ok']) {
            Response::json(['ok' => false, 'error' => I18n::t($r['error'], ['wait' => $r['wait'] ?? 30]), 'code' => $r['error'], 'wait' => $r['wait'] ?? 0], 422);
        }
        $next = self::safeNext($req->str('next', '/'));
        Response::json(['ok' => true, 'redirect' => $next !== '/' ? $next : Shell::home()]);
    }

    public function password(Request $req): void
    {
        if (!RateLimit::hit('pw:' . $req->ip(), 10, 600_000)) {
            Response::fail($req, I18n::t('err.rate'), 429);
        }
        if (!Auth::pinAllowedFrom($req->ip()) && !Turnstile::verify($req->str('cf-turnstile-response'), $req->ip())) {
            Response::fail($req, I18n::t('login.captcha'));
        }
        $r = Auth::loginPassword($req->str('email'), (string) $req->input('password', ''), $req->ip());
        if (!$r['ok']) {
            Response::fail($req, I18n::t($r['error']));
        }
        $next = self::safeNext($req->str('next', '/'));
        $to = $next !== '/' ? $next : Shell::home();
        $req->wantsJson() ? Response::json(['ok' => true, 'redirect' => $to]) : Response::redirect($to);
    }

    public function logout(Request $req): void
    {
        Auth::logout();
        Response::redirect('/login');
    }

    /** Active users who can sign in with a PIN, for the name picker. */
    public static function staff(): array
    {
        $rows = Db::rows('SELECT u.id, u.name, r.code, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.active = 1 AND u.deleted = 0 AND u.pin_hash IS NOT NULL ORDER BY u.sort, u.name');
        $first = [];
        foreach ($rows as $r) {
            $f = explode(' ', trim($r['name']))[0];
            $first[$f] = ($first[$f] ?? 0) + 1;
        }
        return array_map(static function (array $r) use ($first): array {
            $f = explode(' ', trim($r['name']))[0];
            $role = I18n::has('role.' . $r['code']) ? I18n::t('role.' . $r['code']) : $r['role_name'];
            return ['id' => $r['id'], 'name' => $r['name'], 'label' => $first[$f] > 1 ? $r['name'] : $f, 'role' => $role];
        }, $rows);
    }

    /** Only local paths are accepted as a redirect target. */
    public static function safeNext(string $next): string
    {
        return preg_match('#^/(?!/)[\w\-/?=&%.]*$#', $next) && !str_starts_with($next, '/login') ? $next : '/';
    }
}
