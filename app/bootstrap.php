<?php
/**
 * Sofrexa bootstrap: paths, configuration, autoloading and error handling.
 * Loaded by the web front controller (public/index.php) and the CLI (bin/sofrexa).
 */
declare(strict_types=1);

define('SOFREXA_VERSION', '0.1.0');
define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Sofrexa\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_DIR . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_DIR . '/src/helpers.php';
require APP_DIR . '/src/Core/Http.php'; // Request, Response, Router, Flash, HttpError

$config = require APP_DIR . '/config.defaults.php';
$local = getenv('SOFREXA_CONFIG') ?: APP_DIR . '/config.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
Sofrexa\Core\App::boot($config);
