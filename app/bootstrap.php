<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
});

function env_value(string $key, mixed $default = null): mixed {
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = dirname(__DIR__) . '/.env';
        if (!is_file($file)) $file = dirname(__DIR__) . '/.env.example';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                $env[trim($k)] = $v;
            }
        }
    }
    return $_ENV[$key] ?? getenv($key) ?: ($env[$key] ?? $default);
}

function base_path(string $path = ''): string { return dirname(__DIR__) . ($path ? '/' . ltrim($path, '/') : ''); }
function public_path(string $path = ''): string { return base_path('public' . ($path ? '/' . ltrim($path, '/') : '')); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $route, array $params = []): never { $q = http_build_query(array_merge(['r'=>$route], $params)); header('Location: index.php?' . $q); exit; }
function url(string $route, array $params = []): string { return 'index.php?' . http_build_query(array_merge(['r'=>$route], $params)); }
function asset(string $path): string { return 'assets/' . ltrim($path, '/'); }

$lang = require base_path('config/lang/fa.php');
function t(string $key, ?string $fallback = null): string { global $lang; return $lang[$key] ?? $fallback ?? $key; }

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Tehran'));
$secure = filter_var(env_value('SESSION_SECURE', false), FILTER_VALIDATE_BOOLEAN);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_name('MASSAGE_CRM_SESSION');
    session_start();
}
