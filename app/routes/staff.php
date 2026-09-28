<?php
/** Staff administration: users, roles, activity log. @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Staff\{AuditController, RolesController, StaffController, UsersController};

$router->get('/staff/users', [UsersController::class, 'index'], ['perm' => 'users.manage']);
$router->post('/staff/users/save', [UsersController::class, 'save'], ['perm' => 'users.manage']);
$router->post('/staff/users/{id}/pin', [UsersController::class, 'resetPin'], ['perm' => 'users.manage']);
$router->post('/staff/users/{id}/password-link', [UsersController::class, 'passwordLink'], ['perm' => 'users.manage']);
$router->post('/staff/users/{id}/delete', [UsersController::class, 'delete'], ['perm' => 'users.manage']);

$router->get('/password/set', [UsersController::class, 'passwordForm'], ['auth' => false]);
$router->post('/password/set', [UsersController::class, 'passwordSave'], ['auth' => false]);

$router->get('/staff/roles', [RolesController::class, 'index'], ['perm' => 'staff.manage']);
$router->post('/staff/roles/save', [RolesController::class, 'save'], ['perm' => 'staff.manage']);

$router->get('/staff/audit', [AuditController::class, 'index'], ['perm' => 'audit.view']);
$router->get('/staff/audit/export', [AuditController::class, 'export'], ['perm' => 'audit.view']);

// ST1 list, ST2 pay and bonus, ST4 edit (static paths before /staff/{id})
$m = ['perm' => 'staff.manage'];
$router->get('/staff', [StaffController::class, 'index'], $m);
$router->get('/staff/pay', [StaffController::class, 'pay'], $m);
$router->get('/staff/pay/sheet', [StaffController::class, 'paySheet'], $m);
$router->post('/staff/pay', [StaffController::class, 'payPost'], $m);
$router->get('/staff/pay/export', [StaffController::class, 'payExport'], $m);
$router->get('/staff/{id}', [StaffController::class, 'edit'], $m);
$router->post('/staff/{id}', [StaffController::class, 'save'], $m);

// ST3: my shift (everyone)
$router->get('/my/shift', [StaffController::class, 'myShift']);
$router->post('/my/shift/{kind}', [StaffController::class, 'clock']);
