<?php
/** Reports (R1–R8) and finance (FI1–FI4). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Finance\FinanceController as F;
use Sofrexa\Modules\Reports\ReportsController as R;

$r = ['perm' => 'reports.view'];
$router->get('/reports', [R::class, 'hub'], $r);
$router->get('/reports/z', [R::class, 'z'], $r);
$router->post('/reports/z/{id}/print', [R::class, 'zPrint'], $r);
$router->get('/reports/z/{id}/pdf', [R::class, 'zPdf'], $r);
$router->get('/reports/voids', [R::class, 'voids'], $r);
$router->get('/reports/staff', [R::class, 'staff'], $r);
$router->get('/reports/sales', [R::class, 'sales'], $r);
$router->get('/reports/stock', [R::class, 'stock'], $r);
$router->get('/reports/export', [R::class, 'export'], $r);
$router->post('/reports/export', [R::class, 'exportPost'], $r);

$f = ['perm' => 'finance.manage'];
$router->get('/finance', [F::class, 'index'], $f);
$router->get('/finance/new', [F::class, 'form'], $f);
$router->post('/finance/new', [F::class, 'save'], $f);
$router->get('/finance/pl', [F::class, 'pl'], $f);
$router->get('/finance/pl/pdf', [F::class, 'plPdf'], $f);
$router->get('/finance/export', [F::class, 'export'], $f);
$router->get('/finance/entry/{id}', [F::class, 'entry'], $f);
$router->post('/finance/entry/{id}/reverse', [F::class, 'reverse'], $f);
$router->get('/finance/receipt/{id}', [F::class, 'receipt'], $f);
$router->post('/finance/recurring/{id}/stop', [F::class, 'stopRecurring'], $f);
