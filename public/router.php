<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in development server:
 *
 *     php8.2 -S 127.0.0.1:8080 -t public public/router.php
 *
 * Apache uses public/.htaccess instead; this file only exists so the prefix
 * URLs (/men, /women) can be exercised over real HTTP during development and
 * in the HTTP test suites.
 */

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$docroot = realpath(__DIR__);
$candidate = realpath(__DIR__ . $path);

// Serve real static assets ourselves; anything else falls through to the
// front controller. realpath() containment blocks ".." traversal that would
// otherwise expose files outside public/ (e.g. the project .env).
if ($path !== '/' && $candidate !== false && $docroot !== false
    && str_starts_with($candidate, $docroot . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    return false;
}

// Keep the mount point while normalising the script name to index.php, so the
// base-path detection still sees a subdirectory deployment.
$baseDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
if ($baseDir === '.' || $baseDir === '/') $baseDir = '';
$_SERVER['SCRIPT_NAME'] = $baseDir . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
require __DIR__ . '/index.php';
