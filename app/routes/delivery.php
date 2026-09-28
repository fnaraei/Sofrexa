<?php
/** Takeaway and delivery (C4, C5). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Orders\DeliveryController as D;

$p = ['perm' => 'delivery.manage'];
$router->get('/delivery', [D::class, 'index'], $p);
$router->get('/delivery/new', [D::class, 'create'], $p);
$router->get('/delivery/customer', [D::class, 'customer'], $p);
$router->post('/delivery', [D::class, 'store'], $p);
$router->get('/delivery/couriers/{id}/settle', [D::class, 'settleSheet'], $p);
$router->post('/delivery/couriers/{id}/settle', [D::class, 'settle'], $p);
$router->get('/delivery/{id}/sheet', [D::class, 'sheet'], $p);
$router->post('/delivery/{id}/move/{stage}', [D::class, 'move'], $p);
$router->post('/delivery/{id}/courier', [D::class, 'courier'], $p);
$router->post('/delivery/{id}/slip', [D::class, 'slip'], $p);
