<?php
/** Menu, option groups, quick price and stock, areas and tables (M1–M8). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Menu\MenuController as M;
use Sofrexa\Modules\Menu\PromotionsController as PC;

$p = ['perm' => 'menu.manage'];
$router->get('/menu', [M::class, 'index'], $p);
$router->get('/menu/quick', [M::class, 'quick'], $p);
$router->post('/menu/quick', [M::class, 'quickSave'], $p);
$router->get('/menu/items/{id}', [M::class, 'edit'], $p);
$router->post('/menu/items/save', [M::class, 'save'], $p);
$router->post('/menu/items/{id}/photo', [M::class, 'photo'], $p);
$router->post('/menu/items/{id}/toggle', [M::class, 'toggle'], $p);
$router->post('/menu/items/{id}/delete', [M::class, 'delete'], $p);
$router->post('/menu/categories/save', [M::class, 'saveCategory'], $p);
$router->post('/menu/categories/sort', [M::class, 'sortCategories'], $p);
$router->post('/menu/categories/{id}/delete', [M::class, 'deleteCategory'], $p);
$router->post('/menu/groups/save', [M::class, 'saveGroup'], $p);
$router->post('/menu/groups/{id}/delete', [M::class, 'deleteGroup'], $p);

// time-based promotions (PR1–PR4)
$router->get('/menu/promotions', [PC::class, 'index'], $p);
$router->post('/menu/promotions/save', [PC::class, 'save'], $p);
$router->post('/menu/promotions/preview', [PC::class, 'preview'], $p);
$router->get('/menu/promotions/{id}/sheet', [PC::class, 'sheet'], $p);
$router->get('/menu/promotions/{id}', [PC::class, 'edit'], $p);
$router->post('/menu/promotions/{id}/toggle', [PC::class, 'toggle'], $p);
$router->post('/menu/promotions/{id}/delete', [PC::class, 'delete'], $p);

$t = ['perm' => 'tables.manage'];
$router->get('/floor', [M::class, 'floor'], $t);
$router->post('/floor/areas/save', [M::class, 'saveArea'], $t);
$router->post('/floor/areas/{id}/delete', [M::class, 'deleteArea'], $t);
$router->post('/floor/areas/{id}/tables', [M::class, 'addTable'], $t);
$router->post('/floor/tables/{id}/save', [M::class, 'saveTable'], $t);
$router->post('/floor/tables/{id}/delete', [M::class, 'deleteTable'], $t);
$router->get('/floor/tables/{id}/qr.png', [M::class, 'qrPng'], $t);
$router->post('/floor/tables/{id}/print', [M::class, 'qrPrint'], $t);
