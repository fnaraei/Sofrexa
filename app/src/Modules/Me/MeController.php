<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Me;

use Sofrexa\Core\{Audit, Auth, Db, I18n, Request, Response, ValidationError, View};

/** The signed-in person's own page (waiter "Profil" tab): language, own PIN, the device's name for the activity log. */
final class MeController
{
    public function index(Request $req): void
    {
        View::page('me/index', ['title' => I18n::t('me.title'), 'nav' => '', 'tab' => 'profile']);
    }

    public function lang(Request $req): void
    {
        $lang = $req->str('lang');
        if (!isset(I18n::LANGS[$lang])) {
            throw new ValidationError(['lang' => I18n::t('err.method')]);
        }
        Db::save('users', ['id' => Auth::user()['id'], 'lang' => $lang]);
        setcookie('sofrexa_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        I18n::set($lang);
        Response::json(['ok' => true, 'message' => I18n::t('me.lang_saved'), 'redirect' => $req->str('back', '/my')]);
    }

    public function pin(Request $req): void
    {
        $u = Db::row('SELECT id, name, pin_hash FROM users WHERE id = ?', [Auth::user()['id']]);
        $new = $req->str('new');
        if (!$u['pin_hash'] || !password_verify($req->str('current'), $u['pin_hash'])) {
            throw new ValidationError(['current' => I18n::t('me.pin_wrong')]);
        }
        if (!preg_match('/^\d{4}$/', $new)) {
            throw new ValidationError(['new' => I18n::t('me.pin_format')]);
        }
        if ($new !== $req->str('repeat')) {
            throw new ValidationError(['repeat' => I18n::t('me.pin_mismatch')]);
        }
        if (in_array($new, ['0000', '1111', '1234', '4321', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1212', '0123'], true)) {
            throw new ValidationError(['new' => I18n::t('me.pin_weak')]);
        }
        Db::save('users', ['id' => $u['id'], 'pin_hash' => Auth::hashPin($new)]);
        Audit::log('user.reset_pin', $u['name'] . ' · kendisi', 'user', $u['id']);
        Response::json(['ok' => true, 'message' => I18n::t('me.pin_saved')]);
    }

    /** Names this device in the activity log (cookie, per device). */
    public function device(Request $req): void
    {
        $name = mb_substr(trim($req->str('device')), 0, 40);
        setcookie('sofrexa_device', $name, ['expires' => time() + 5 * 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        Response::json(['ok' => true, 'message' => I18n::t('me.device_saved')]);
    }
}
