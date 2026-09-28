<?php
declare(strict_types=1);

namespace Sofrexa\Core;

/** HTTP exception that becomes an error page or a JSON error. */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (string) $status);
    }
}

final class Request
{
    public readonly string $method;
    public readonly string $path;
    private array $json = [];
    public array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->path = '/' . trim(rawurldecode($path), '/');
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
    }

    /** Input from JSON body, POST form, then query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST, $this->json);
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        $v = $this->input($key, false);
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }

    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        return is_array($v) ? $v : [];
    }

    public function param(string $key): string
    {
        return (string) ($this->params[$key] ?? '');
    }

    public function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function ip(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function header(string $name): string
    {
        return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }
}

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $to, int $status = 303): never
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /** Success for fetch() calls, or redirect back for plain forms. */
    public static function ok(Request $req, array $data = [], string $back = ''): never
    {
        if ($req->wantsJson()) {
            self::json(['ok' => true] + $data);
        }
        self::redirect($back !== '' ? $back : ($_SERVER['HTTP_REFERER'] ?? '/'));
    }

    public static function fail(Request $req, string $message, int $status = 422, array $extra = []): never
    {
        if ($req->wantsJson()) {
            self::json(['ok' => false, 'error' => $message] + $extra, $status);
        }
        Flash::set('error', $message);
        self::redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}

/** One-shot messages shown after a redirect. */
final class Flash
{
    public static function set(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'][] = [$type, $message];
        }
    }

    public static function take(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}

final class Router
{
    private array $routes = [];

    /** $opts: auth (staff login required, default true), perm (permission code), csrf (default true for POST). */
    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#u';
        $this->routes[] = [$method, $regex, $handler, $opts + ['auth' => true]];
    }

    public function get(string $p, callable|array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable|array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }

    public function dispatch(Request $req): void
    {
        $allowed = false;
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            $allowed = true;
            if ($method !== $req->method) {
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($req->method === 'POST' && ($opts['csrf'] ?? true)) {
                Csrf::verify($req);
            }
            if ($opts['auth'] === true && !Auth::user()) {
                if ($req->wantsJson()) {
                    Response::json(['ok' => false, 'error' => 'auth'], 401);
                }
                Response::redirect('/login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
            }
            if (!empty($opts['perm']) && !Auth::can($opts['perm'])) {
                throw new HttpError(403, I18n::t('err.forbidden'));
            }
            if (is_array($handler)) {
                [$class, $fn] = $handler;
                (new $class())->$fn($req);
            } else {
                $handler($req);
            }
            return;
        }
        throw new HttpError($allowed ? 405 : 404);
    }
}
