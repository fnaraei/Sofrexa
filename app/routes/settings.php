<?php
/** Settings (SE1–SE9) and backups. @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Modules\Backup\BackupController;
use Sofrexa\Modules\Settings\SettingsController;

$router->get('/settings', [SettingsController::class, 'index'], ['perm' => 'settings.manage']);
$router->post('/settings/profile/logo', [SettingsController::class, 'logo'], ['perm' => 'settings.manage']);
$router->post('/settings/profile/logo/remove', [SettingsController::class, 'logoRemove'], ['perm' => 'settings.manage']);
$router->post('/settings/printers/{printer}/test', [SettingsController::class, 'printerTest'], ['perm' => 'settings.manage']);

$router->post('/settings/backup/create', [BackupController::class, 'create'], ['perm' => 'backup.manage']);
$router->get('/settings/backup/download/{file}', [BackupController::class, 'download'], ['perm' => 'backup.manage']);
$router->post('/settings/backup/restore', [BackupController::class, 'restore'], ['perm' => 'backup.manage']);
$router->post('/settings/backup/upload', [BackupController::class, 'upload'], ['perm' => 'backup.manage']);
$router->post('/settings/emergency', [BackupController::class, 'emergency'], ['perm' => 'settings.manage']);

$router->get('/settings/{section}', [SettingsController::class, 'section'], ['perm' => 'settings.manage']);
$router->post('/settings/{section}', [SettingsController::class, 'save'], ['perm' => 'settings.manage']);
