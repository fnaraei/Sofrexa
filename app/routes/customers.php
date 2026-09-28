<?php
/** Customers, accounts and loyalty (CU1–CU7). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Customers\CustomersController as C;

$v = ['perm' => 'customers.view'];
$m = ['perm' => 'customers.manage'];
$router->get('/customers', [C::class, 'index'], $v);
$router->get('/customers/export', [C::class, 'export'], $v);
$router->get('/customers/search', [C::class, 'search'], $v);
$router->post('/customers/save', [C::class, 'save'], $v);
// loyalty program (CU5) before /customers/{id}
$router->get('/customers/loyalty', [C::class, 'loyalty'], $m);
$router->post('/customers/loyalty', [C::class, 'saveLoyalty'], $m);
$router->get('/customers/loyalty/tier/{id}', [C::class, 'tierSheet'], $m);
$router->post('/customers/loyalty/tier/{id}', [C::class, 'saveTierName'], $m);
$router->post('/customers/loyalty/tier/{id}/delete', [C::class, 'deleteTier'], $m);
$router->get('/customers/{id}', [C::class, 'show'], $v);
$router->get('/customers/{id}/statement', [C::class, 'statement'], $v);
$router->get('/customers/{id}/sheet/{kind}', [C::class, 'sheet'], $v);
$router->post('/customers/{id}/delete', [C::class, 'delete'], $m);
$router->post('/customers/{id}/tier', [C::class, 'tier'], $m);
$router->post('/customers/{id}/collect', [C::class, 'collect'], ['perm' => 'cash.pay']);
$router->post('/customers/{id}/points', [C::class, 'points'], $m);
