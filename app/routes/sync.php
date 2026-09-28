<?php
/** PC ↔ web copy replication (signed requests, no session, no CSRF). @var Sofrexa\Core\Router $router */
declare(strict_types=1);

use Sofrexa\Sync\Server;

foreach (['pull', 'push', 'media', 'snapshot', 'backup'] as $action) {
    $router->post('/sync/' . $action, [Server::class, $action], ['auth' => false, 'csrf' => false]);
}
