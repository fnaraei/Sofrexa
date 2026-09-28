<?php
/** QR ordering at the table: guest pages (Q1–Q4, no sign-in) and the waiter's approval (W5). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\QrOrder\GuestController as G;
use Sofrexa\Modules\QrOrder\QrController as Q;

$g = ['auth' => false];
$router->get('/q/{code}', [G::class, 'menu'], $g);
$router->post('/q/{code}/order', [G::class, 'order'], $g);
$router->get('/q/{code}/status', [G::class, 'status'], $g);
$router->post('/q/{code}/call', [G::class, 'call'], $g);
$router->post('/q/{code}/bill', [G::class, 'bill'], $g);

$p = ['perm' => 'orders.qr_approve'];
$router->get('/qr/orders/{id}', [Q::class, 'sheet'], $p);
$router->post('/qr/orders/{id}/approve', [Q::class, 'approve'], $p);
$router->post('/qr/orders/{id}/reject', [Q::class, 'reject'], $p);
