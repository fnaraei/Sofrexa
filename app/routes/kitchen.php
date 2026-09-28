<?php
/** Kitchen and bar (K1–K3). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Kitchen\KitchenController as K;

$v = ['perm' => 'kitchen.view'];
$router->get('/kitchen', [K::class, 'chef'], $v);
$router->get('/kitchen/tv', [K::class, 'tv'], $v);
$router->get('/kitchen/poll', [K::class, 'poll'], $v);
$router->post('/kitchen/act/{act}', [K::class, 'act'], $v);
$router->post('/kitchen/tv/renew', [K::class, 'renew'], ['perm' => 'kitchen.ready']);

// The kitchen TV opens a link with the display token and needs no sign-in.
$t = ['auth' => false];
$router->get('/kds/{token}', [K::class, 'display'], $t);
$router->get('/kds/{token}/poll', [K::class, 'poll'], $t);
$router->post('/kds/{token}/act/{act}', [K::class, 'act'], $t);
