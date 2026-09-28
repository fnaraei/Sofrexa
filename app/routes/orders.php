<?php
/** Tables, order taking and notifications (W1–W11). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Orders\NotifyController as N;
use Sofrexa\Modules\Orders\OrderController as O;
use Sofrexa\Modules\Orders\TablesController as T;

$p = ['perm' => 'orders.take'];
$router->get('/tables', [T::class, 'index'], $p);
$router->get('/tables/{id}/panel', [T::class, 'panel'], $p);
$router->get('/tables/{id}/actions', [T::class, 'actions'], $p);
$router->get('/tables/{id}/order', [T::class, 'order'], $p);

$router->get('/orders/new/takeaway', [O::class, 'newTakeaway'], $p);
$router->post('/orders/add', [O::class, 'add'], $p);
$router->get('/orders/item/{id}/options', [O::class, 'options'], $p);
$router->get('/orders/lines/{id}', [O::class, 'lineSheet'], $p);
$router->post('/orders/lines/{id}', [O::class, 'updateLine'], $p);
$router->post('/orders/lines/{id}/void', [O::class, 'voidLine'], $p);
$router->get('/orders/{id}', [O::class, 'show'], $p);
$router->get('/orders/{id}/summary', [O::class, 'summary'], $p);
$router->post('/orders/{id}/send', [O::class, 'send'], $p);
// the switches a manager can take away from one person (Staff::SWITCHES) are checked on their own routes
$router->post('/orders/{id}/prebill', [O::class, 'preBill'], ['perm' => 'bill.print']);

$router->get('/orders/{id}/sheet/{kind}', [T::class, 'sheet'], $p);
$router->post('/orders/{id}/move', [T::class, 'move'], ['perm' => 'orders.transfer']);
$router->post('/orders/{id}/merge', [T::class, 'merge'], ['perm' => 'orders.transfer']);
$router->post('/orders/{id}/split', [T::class, 'split'], $p);
$router->post('/orders/{id}/guests', [T::class, 'guests'], $p);
$router->post('/orders/{id}/waiter', [T::class, 'waiter'], ['perm' => 'orders.transfer']);
$router->post('/orders/{id}/bill', [T::class, 'requestBill'], $p);
$router->post('/orders/{id}/close', [T::class, 'close'], $p);

// Notifications (W6): every signed-in staff member sees their own.
$router->get('/my/notifications', [N::class, 'index']);
$router->post('/my/notifications/read', [N::class, 'readAll']);
$router->post('/my/notifications/{id}/{act}', [N::class, 'act']);
