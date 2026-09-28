<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Uploaded files served without sign-in (menu photos, logo). Private files (receipts) are never among them. */
final class Media
{
    /**
     * The file behind a "/media/…" address, or null. Decided on the real path, not on what was asked for:
     * "/media/%2e/private/…" or "/media/menu/../private/…" must not reach the private folder.
     */
    public static function publicFile(string $urlPath): ?string
    {
        if (!str_starts_with($urlPath, '/media/')) {
            return null;
        }
        $base = realpath(App::storage('uploads'));
        if (!$base) {
            return null;
        }
        $file = realpath($base . '/' . substr(rawurldecode($urlPath), 7));
        if (!$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        $rel = str_replace('\\', '/', substr($file, strlen($base) + 1));
        return preg_match('#(^|/)private(/|$)#i', $rel) ? null : $file;
    }
}
