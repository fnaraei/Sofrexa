<?php
declare(strict_types=1);

namespace Sofrexa\Modules\System;

use Sofrexa\Core\{Auth, Csrf, Db, Request, Response, Settings};
use Sofrexa\Sync\Status;

/** Web app manifest and the status poll used by every staff page (sync badge, notification dot). */
final class SystemController
{
    public function manifest(Request $req): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $name = (string) Settings::get('profile.name');
        echo json_encode([
            'name' => $name,
            'short_name' => (string) (Settings::get('profile.short_name') ?: $name),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f6f2e9',
            'theme_color' => '#f6f2e9',
            'icons' => [
                ['src' => asset('img/app-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('img/app-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function status(Request $req): void
    {
        $unread = Auth::user() ? \Sofrexa\Modules\Orders\Notify::unread() : 0;
        Response::json(['ok' => true, 'sync' => Status::get(), 'unread' => $unread, 'csrf' => Csrf::token()]);
    }
}
