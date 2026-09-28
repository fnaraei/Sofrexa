<?php
/** The till (C1–C11). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Orders\CashierController as C;
use Sofrexa\Modules\Orders\VoidsController as V;

$p = ['perm' => 'cash.pay'];
$router->get('/cashier', [C::class, 'index'], $p);
$router->get('/cashier/pay/{id}', [C::class, 'pay'], $p);
$router->post('/cashier/pay/{id}', [C::class, 'payPost'], $p);
$router->get('/cashier/pay/{id}/sheet/{kind}', [C::class, 'paySheet'], $p);
$router->post('/cashier/pay/{id}/discount', [C::class, 'discount'], ['perm' => 'orders.discount']);
$router->post('/cashier/pay/{id}/note', [C::class, 'note'], $p);
$router->post('/cashier/pay/{id}/customer', [C::class, 'customer'], $p);
$router->post('/cashier/pay/{id}/points', [C::class, 'points'], $p);
$router->get('/cashier/customers', [C::class, 'customers'], $p);

// cancelled dishes of the kitchen (IP1–IP4): the till's answer, waste, another bill, a staff member
$router->get('/cashier/voids', [V::class, 'index'], $p);
$router->get('/cashier/voids/{id}/sheet/{kind}', [V::class, 'sheet'], $p);
$router->post('/cashier/voids/{id}/settle', [V::class, 'settle'], $p);
$router->post('/cashier/voids/{id}/reuse', [V::class, 'reuse'], $p);
$router->post('/cashier/voids/{id}/staff', [V::class, 'staff'], $p);

$router->get('/cashier/rates', [C::class, 'rates'], ['perm' => 'cash.rates']);
$router->post('/cashier/rates', [C::class, 'saveRates'], ['perm' => 'cash.rates']);

$s = ['perm' => 'cash.shift'];
$router->get('/cashier/shift/open', [C::class, 'openSheet'], $s);
$router->post('/cashier/shift/open', [C::class, 'open'], $s);
$router->get('/cashier/shift', [C::class, 'shift'], $s);
$router->post('/cashier/shift/x', [C::class, 'xReport'], $s);
$router->post('/cashier/shift/close', [C::class, 'close'], $s);

$m = ['perm' => 'cash.moves'];
$router->get('/cashier/moves', [C::class, 'moves'], $m);
$router->get('/cashier/moves/sheet', [C::class, 'moveSheet'], $m);
$router->post('/cashier/moves', [C::class, 'saveMove'], $m);
$router->get('/cashier/moves/{id}/photo', [C::class, 'photo'], $m);
$router->post('/cashier/moves/{id}/reverse', [C::class, 'reverse'], $m);
$router->post('/cashier/nosale', [C::class, 'noSale'], ['perm' => 'cash.nosale']);
