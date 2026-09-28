<?php
/** Staff administration: users, roles, activity log. @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Staff\{AuditController, RolesController, UsersController};

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

// The staff list (ST1) is built with payroll in stage 8; until then the section opens on users.
$router->get('/staff', static fn() => Sofrexa\Core\Response::redirect('/staff/users'), ['perm' => 'staff.manage']);
