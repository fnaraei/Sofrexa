<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Backup;

use Sofrexa\Core\{Auth, HttpError, I18n, Request, Response};

/** SE7–SE9 actions. Taking and downloading backups needs backup.manage; restoring is for the manager role only. */
final class BackupController
{
    public function create(Request $req): void
    {
        $file = Backup::create('manual');
        Response::json(['ok' => true, 'message' => I18n::t('set.bk.created', ['size' => Backup::size((int) filesize($file))])]);
    }

    public function download(Request $req): void
    {
        $path = Backup::path($req->param('file'));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public function restore(Request $req): void
    {
        self::guard($req);
        $safety = Backup::restore(Backup::path($req->str('file')));
        Response::json(['ok' => true, 'message' => I18n::t('set.bk.restored', ['file' => $safety]), 'redirect' => '/settings/backup']);
    }

    public function upload(Request $req): void
    {
        self::guard($req);
        $f = $_FILES['backup'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            Response::fail($req, I18n::t('set.bk.bad_file'));
        }
        $dest = Backup::dir() . '/upload-' . date('Ymd-His') . '.zip';
        move_uploaded_file($f['tmp_name'], $dest);
        try {
            $safety = Backup::restore($dest);
        } finally {
            @unlink($dest);
        }
        Response::json(['ok' => true, 'message' => I18n::t('set.bk.restored', ['file' => $safety]), 'redirect' => '/settings/backup']);
    }

    private static function guard(Request $req): void
    {
        if ((Auth::user()['role_code'] ?? '') !== 'manager') {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $word = I18n::t('set.bk.confirm_word');
        if (mb_strtoupper(trim($req->str('confirm')), 'UTF-8') !== $word && trim($req->str('confirm')) !== $word) {
            Response::fail($req, I18n::t('set.bk.confirm_wrong'));
        }
    }
}
