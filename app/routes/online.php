<?php
/** Online ordering for delivery and pickup (Figma O1–O9), public pages. @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Online\OnlineController as C;

$g = ['auth' => false];
$router->get('/online', [C::class, 'menu'], $g);
$router->post('/online/sepet', [C::class, 'cart'], $g);
$router->get('/online/giris', [C::class, 'login'], $g);
$router->post('/online/giris', [C::class, 'doLogin'], $g);
$router->get('/online/kayit', [C::class, 'register'], $g);
$router->post('/online/kayit', [C::class, 'doRegister'], $g);
$router->get('/online/dogrula', [C::class, 'verify'], $g);
$router->post('/online/dogrula', [C::class, 'doVerify'], $g);
$router->post('/online/kod', [C::class, 'resend'], $g);
$router->get('/online/sifre', [C::class, 'forgot'], $g);
$router->post('/online/sifre', [C::class, 'doForgot'], $g);
$router->post('/online/sifre/yeni', [C::class, 'doReset'], $g);
$router->post('/online/cikis', [C::class, 'logout'], $g);
$router->get('/online/hesap', [C::class, 'account'], $g);
$router->get('/online/odeme', [C::class, 'checkout'], $g);
$router->post('/online/adres', [C::class, 'address'], $g);
$router->post('/online/siparis', [C::class, 'place'], $g);
$router->get('/online/siparis/{id}', [C::class, 'track'], $g);
