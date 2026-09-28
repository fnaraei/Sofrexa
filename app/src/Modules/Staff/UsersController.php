<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Staff;

use Sofrexa\Core\{I18n, Request, Response, View};

/** ST8 (desktop table) / ST9 (mobile list + edit sheet), and the password link page. */
final class UsersController
{
    public function index(Request $req): void
    {
        $filter = in_array($req->str('f'), ['active', 'passive'], true) ? $req->str('f') : 'all';
        View::page('staff/users', [
            'title' => I18n::t('users.title'),
            'sub' => I18n::t('users.sub'),
            'nav' => 'staff',
            'back' => '/staff',
            'users' => Users::list($filter),
            'counts' => Users::counts(),
            'filter' => $filter,
            'roles' => Users::roles(),
            'scripts' => ['js/users.js'],
        ]);
    }

    public function save(Request $req): void
    {
        $r = Users::save($req->all());
        Response::json(['ok' => true, 'id' => $r['id'], 'pin' => $r['pin'], 'message' => I18n::t('users.saved')]);
    }

    public function resetPin(Request $req): void
    {
        Response::json(['ok' => true, 'pin' => Users::resetPin($req->param('id'))]);
    }

    public function passwordLink(Request $req): void
    {
        $email = Users::sendPasswordLink($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('users.link_sent', ['email' => $email])]);
    }

    public function delete(Request $req): void
    {
        Users::deactivate($req->param('id'));
        Response::json(['ok' => true, 'message' => I18n::t('users.deleted')]);
    }

    // -------------------------------------------------------------- password link (no sign-in needed)

    public function passwordForm(Request $req): void
    {
        $reset = Users::resetByToken($req->str('token'));
        View::page('auth/password', [
            'title' => I18n::t('pw.title'),
            'reset' => $reset,
            'token' => $req->str('token'),
        ], 'layouts/bare');
    }

    public function passwordSave(Request $req): void
    {
        $reset = Users::resetByToken($req->str('token'));
        if (!$reset) {
            Response::fail($req, I18n::t('pw.invalid'), 410);
        }
        $pw = (string) $req->input('password', '');
        if ($pw !== (string) $req->input('password2', '')) {
            Response::fail($req, I18n::t('pw.mismatch'));
        }
        if (!Users::strongEnough($pw)) {
            Response::fail($req, I18n::t('pw.weak'));
        }
        Users::setPassword($reset, $pw);
        Response::json(['ok' => true, 'message' => I18n::t('pw.done'), 'redirect' => '/login?mode=password']);
    }
}
