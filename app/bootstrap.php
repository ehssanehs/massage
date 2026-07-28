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
function redirect(string $route, array $params = []): never {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $q = http_build_query(array_merge(['r'=>$route], $params));
    if (!headers_sent()) {
        header('Location: index.php?' . $q);
    } else {
        // Output was already started (e.g. stray BOM/notice): fall back to a
        // client-side redirect so the user is not stuck on a stale page.
        echo '<meta http-equiv="refresh" content="0;url=index.php?' . e($q) . '">'
            . '<script>location.replace(' . json_encode('index.php?' . $q) . ');</script>';
    }
    exit;
}
function url(string $route, array $params = []): string { return 'index.php?' . http_build_query(array_merge(['r'=>$route], $params)); }
function asset(string $path): string { return 'assets/' . ltrim($path, '/'); }

$lang = require base_path('config/lang/fa.php');
function t(string $key, ?string $fallback = null): string { global $lang; return $lang[$key] ?? $fallback ?? $key; }

date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Tehran'));

// --- Session hardening -----------------------------------------------------
// On many shared hosts / panels the default session.save_path is missing or
// not writable by the PHP process. Session files are then silently discarded,
// so the login state never survives the redirect to the dashboard and the
// user just lands back on the login form with no error. Fall back to a
// project-local directory whenever the configured path is unusable.
$sessionPath = base_path('storage/sessions');
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0775, true);
}
if (is_writable($sessionPath)) {
    $currentSavePath = (string)session_save_path();
    // Format can be "N;/path" or "N;MODE;/path" — keep only the path part.
    $pathOnly = ($pos = strrpos($currentSavePath, ';')) !== false ? substr($currentSavePath, $pos + 1) : $currentSavePath;
    if ($pathOnly === '' || !is_dir($pathOnly) || !is_writable($pathOnly)) {
        session_save_path($sessionPath);
    }
}

// A cookie flagged "Secure" is never sent back by the browser over plain
// HTTP, which again looks exactly like "login does nothing". Only honour
// SESSION_SECURE when the request is actually HTTPS (directly or via proxy).
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
$secure = filter_var(env_value('SESSION_SECURE', false), FILTER_VALIDATE_BOOLEAN) && $isHttps;

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_name('MASSAGE_CRM_SESSION');
    session_start();
}
