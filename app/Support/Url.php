<?php
declare(strict_types=1);
namespace App\Support;

/**
 * Builds in-app URLs that stay correct wherever the app is mounted.
 *
 * Every link used to be absolute from the domain root ("/index.php?...",
 * "/assets/..."). That renders fine when the project is a virtual host's
 * document root, but from a subdirectory (http://host/massage/public/) each of
 * those links pointed one level above the app and returned 404 while the page
 * the user typed still loaded.
 *
 * The base is derived from the executing script, so the same build serves the
 * domain root, a subdirectory, and the per-section entry points.
 */
final class Url {
    private static ?string $base = null;

    /**
     * Directory the app is served from, without a trailing slash: '' at the
     * domain root, '/massage/public' inside a subdirectory.
     */
    public static function base(): string {
        if (self::$base !== null) return self::$base;
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '.' || $dir === '/') $dir = '';
        // A section entry point lives at <base>/<section>/index.php. That last
        // segment is the section prefix (added back by Tenant::pathPrefix()),
        // not part of the application base.
        if (preg_match('#^(.*?)/(?:men|women)$#', $dir, $m)) $dir = $m[1];
        return self::$base = rtrim($dir, '/');
    }

    /** Absolute URL for a front-controller route, keeping the current section. */
    public static function to(string $route, array $params = []): string {
        return self::base() . Tenant::pathPrefix() . '/index.php?' . http_build_query(array_merge(['r' => $route], $params));
    }

    /** Absolute URL for a route that belongs to no section (the public site). */
    public static function plain(string $route, array $params = []): string {
        return self::base() . '/index.php?' . http_build_query(array_merge(['r' => $route], $params));
    }

    /** Entry point of one section's staff CRM. */
    public static function sectionLogin(string $tenant): string {
        return self::base() . '/' . $tenant . '/index.php?r=login';
    }

    /** Absolute URL for a file under public/assets. */
    public static function asset(string $path): string {
        return self::base() . '/assets/' . ltrim($path, '/');
    }

    /** Forget the cached base (tests that simulate another mount point). */
    public static function reset(): void { self::$base = null; }
}
