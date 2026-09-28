<?php
/**
 * Test runner: php app/bin/sofrexa test
 * Every tests/*Test.php returns a list of closures; each runs against a fresh temporary database.
 */
declare(strict_types=1);

use Sofrexa\Core\{App, Auth, Clock, Db, Migrator, Settings};

$failed = 0;
$passed = 0;
$tmp = sys_get_temp_dir() . '/sofrexa-test-' . getmypid();
@mkdir($tmp, 0775, true);

function check(bool $cond, string $what): void
{
    if (!$cond) {
        throw new RuntimeException('failed: ' . $what);
    }
}

function same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($what !== '' ? $what . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $tests = require $file;
    foreach ($tests as $name => $fn) {
        $db = $tmp . '/' . bin2hex(random_bytes(4)) . '.sqlite';
        App::setConfig('db', $db);
        App::setConfig('storage', $tmp);
        App::setConfig('role', 'pc');
        App::setConfig('sync.remote_url', '');
        Db::disconnect();
        Settings::flush();
        Clock::freeze(null);
        Auth::actAs(null);
        try {
            Migrator::run();
            $fn();
            $passed++;
            fwrite(STDOUT, "  ok   " . basename($file, '.php') . " › $name\n");
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDOUT, "  FAIL " . basename($file, '.php') . " › $name — " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
        }
        Db::disconnect();
        foreach (glob($db . '*') ?: [] as $f) {
            @unlink($f);
        }
    }
}
fwrite(STDOUT, "\n$passed passed, $failed failed\n");
return $failed ? 1 : 0;
