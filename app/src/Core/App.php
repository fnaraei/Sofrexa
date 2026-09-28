<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** Application-wide configuration and lifecycle. */
final class App
{
    private static array $config = [];

    public static function boot(array $config): void
    {
        self::$config = $config;
        date_default_timezone_set($config['timezone'] ?? 'UTC');
        mb_internal_encoding('UTF-8');
        foreach (['db', 'backups', 'logs', 'uploads', 'prints', 'mail'] as $dir) {
            $path = self::storage($dir);
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    /** Dot-path config lookup: App::config('sync.key'). */
    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function setConfig(string $key, mixed $value): void
    {
        $ref = &self::$config;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function storage(string $sub = ''): string
    {
        $base = rtrim((string) self::config('storage'), '/\\');
        return $sub === '' ? $base : $base . '/' . $sub;
    }

    public static function isPc(): bool
    {
        return self::config('role') === 'pc';
    }

    public static function isWeb(): bool
    {
        return self::config('role') === 'web';
    }

    public static function log(string $channel, string $message, array $context = []): void
    {
        $line = date('Y-m-d H:i:s') . " [$channel] $message" . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '') . "\n";
        @file_put_contents(self::storage('logs') . '/' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
