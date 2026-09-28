<?php
/**
 * All HTTP routes. Options: auth (default true), perm (permission code), csrf (default true on POST).
 *
 * @var Sofrexa\Core\Router $router
 */
declare(strict_types=1);

use Sofrexa\Modules\Auth\LoginController;
use Sofrexa\Modules\Home\HomeController;
use Sofrexa\Modules\System\SystemController;

// ------------------------------------------------------------------ sign-in
$router->get('/login', [LoginController::class, 'show'], ['auth' => false]);
$router->post('/login/pin', [LoginController::class, 'pin'], ['auth' => false]);
$router->post('/login/password', [LoginController::class, 'password'], ['auth' => false]);
$router->post('/logout', [LoginController::class, 'logout'], ['auth' => false]);

// ------------------------------------------------------------------ system
$router->get('/manifest.webmanifest', [SystemController::class, 'manifest'], ['auth' => false]);
$router->get('/api/status', [SystemController::class, 'status']);

// ------------------------------------------------------------------ home
$router->get('/', [HomeController::class, 'index']);
$router->get('/more', [HomeController::class, 'more']);

foreach (glob(APP_DIR . '/routes/*.php') ?: [] as $file) {
    require $file;
}

// ------------------------------------------------------------------ own profile (every signed-in user)
$router->get('/my', [Sofrexa\Modules\Me\MeController::class, 'index']);
$router->post('/my/lang', [Sofrexa\Modules\Me\MeController::class, 'lang']);
$router->post('/my/pin', [Sofrexa\Modules\Me\MeController::class, 'pin']);
$router->post('/my/device', [Sofrexa\Modules\Me\MeController::class, 'device']);
