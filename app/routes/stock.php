<?php
/** Stock (S1–S7). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Stock\StockController as S;

$p = ['perm' => 'stock.manage'];
$router->get('/stock', [S::class, 'index'], $p);
$router->get('/stock/search', [S::class, 'search'], $p);
$router->get('/stock/items/{id}/sheet', [S::class, 'itemSheet'], $p);
$router->post('/stock/items/save', [S::class, 'saveItem'], $p);
$router->post('/stock/items/{id}/delete', [S::class, 'deleteItem'], $p);
$router->post('/stock/suppliers/save', [S::class, 'saveSupplier'], $p);
$router->get('/stock/purchase', [S::class, 'purchase'], $p);
$router->post('/stock/purchase', [S::class, 'savePurchase'], $p);
$router->get('/stock/count', [S::class, 'count'], $p);
$router->post('/stock/count', [S::class, 'saveCount'], $p);
$router->get('/stock/waste', [S::class, 'waste'], $p);
$router->post('/stock/waste', [S::class, 'saveWaste'], $p);
$router->get('/stock/shopping', [S::class, 'shopping'], $p);
// recipes of menu items belong to the menu (menu.manage); semi-finished recipes to the stock
$router->get('/stock/recipe/{kind}/{id}', [S::class, 'recipe'], $p);
$router->post('/stock/recipe/{kind}/{id}', [S::class, 'saveRecipe'], $p);
