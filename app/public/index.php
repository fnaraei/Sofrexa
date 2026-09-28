<?php
/** Front controller for the staff app, customer pages, API and sync endpoints. */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Sofrexa\Core\{App, Auth, Db, HttpError, I18n, Migrator, Request, Response, Router, View};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP's built-in server: let it serve real files under public/.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

// Uploaded media (menu photos, logo, receipts) live outside the web root.
if (str_starts_with($path, '/media/')) {
    $rel = substr(rawurldecode($path), 7);
    $base = realpath(App::storage('uploads'));
    $file = $base ? realpath($base . '/' . $rel) : false;
    if (!$file || !str_starts_with($file, $base) || !is_file($file) || str_starts_with($rel, 'private/')) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

try {
    if (Migrator::pending()) {
        Migrator::run();
    }
    Auth::startSession();
    I18n::set(I18n::detect(Auth::user()));
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');

    $router = new Router();
    require APP_DIR . '/routes.php';
    $router->dispatch(new Request());
} catch (\InvalidArgumentException $e) {
    // Validation and business-rule errors: the message is already translated.
    $req = new Request();
    $errors = $e instanceof \Sofrexa\Core\ValidationError ? $e->errors : [];
    Response::fail($req, $e->getMessage(), 422, $errors ? ['errors' => $errors] : []);
} catch (HttpError $e) {
    $req = new Request();
    if ($req->wantsJson()) {
        Response::json(['ok' => false, 'error' => $e->getMessage()], $e->status);
    }
    http_response_code($e->status);
    echo View::render('pages/error', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => (string) $e->status, 'noHead' => true, 'noTabbar' => true], Auth::user() ? 'layouts/staff' : 'layouts/bare');
} catch (\Throwable $e) {
    App::log('error', $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    $debug = (bool) App::config('debug');
    if ((new Request())->wantsJson()) {
        Response::json(['ok' => false, 'error' => $debug ? $e->getMessage() : I18n::t('err.server')], 500);
    }
    http_response_code(500);
    echo View::render('pages/error', ['status' => 500, 'message' => $debug ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : I18n::t('err.server'), 'title' => '500', 'noHead' => true, 'noTabbar' => true], 'layouts/bare');
}
