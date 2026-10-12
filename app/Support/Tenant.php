<?php
declare(strict_types=1);
namespace App\Support;

/**
 * Section ("tenant") selection for the two independent CRMs (men / women).
 *
 * The chosen section lives in the server-side session — a request may only name
 * a known section key, never a database name, so no client can pivot to another
 * database. Installs that define no per-section database keep running as a
 * single-section CRM (see isMulti()), which is also how the DB/HTTP tests use a
 * scratch database through a plain DB_DATABASE.
 */
final class Tenant {
    private const TENANTS = [
        'men' => ['label' => 'بخش آقایان', 'path' => '/men'],
        'women' => ['label' => 'بخش خانم‌ها', 'path' => '/women'],
    ];

    public static function all(): array { return self::TENANTS; }

    /** Sections that actually have their own database configured in .env. */
    public static function configured(): array {
        return array_values(array_filter(array_keys(self::TENANTS), static function (string $tenant): bool {
            $name = env_value('DB_DATABASE_' . strtoupper($tenant));
            return is_string($name) && trim($name) !== '';
        }));
    }

    /**
     * True when the install runs one CRM per section. When false, the app is a
     * single-section install: no section is required at login and DB_DATABASE
     * is used as-is.
     */
    public static function isMulti(): bool { return self::configured() !== []; }

    /** Section forced by a CLI command (see bin/console); null for web requests. */
    private static ?string $cliTenant = null;

    /** Pin the section for the current CLI process. Returns false for unknown names. */
    public static function activateForCli(string $tenant): bool {
        if (!isset(self::TENANTS[$tenant])) return false;
        self::$cliTenant = $tenant;
        return true;
    }

    public static function current(): ?string {
        if (self::$cliTenant !== null) return self::$cliTenant;
        $tenant = $_SESSION['tenant'] ?? null;
        return is_string($tenant) && isset(self::TENANTS[$tenant]) ? $tenant : null;
    }

    public static function select(string $tenant): bool {
        if (!isset(self::TENANTS[$tenant])) return false;
        // A section change is also an authentication change: the previous
        // section's session identity and CSRF/permission state must not follow.
        if (self::current() !== null && self::current() !== $tenant) unset($_SESSION['uid'], $_SESSION['_csrf'], $_SESSION['_rate']);
        $_SESSION['tenant'] = $tenant;
        return true;
    }

    public static function clear(): void { unset($_SESSION['tenant']); }

    /** Apply the section carried by the request path (/men, /women). */
    public static function selectFromRequest(): void {
        if (!self::isMulti()) return;
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        if (preg_match('#^/(men|women)(?:/|$)#', $path, $m)) self::select($m[1]);
    }

    /** Route implied by a section path, or null when the path names no section. */
    public static function routeFromRequest(): ?string {
        if (!self::isMulti()) return null;
        $path = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
        if (!preg_match('#^(?:men|women)(?:/(.*))?$#', $path, $m)) return null;
        $leaf = trim($m[1] ?? '', '/');
        return $leaf === 'login' ? 'login' : ($leaf === 'logout' ? 'logout' : 'dashboard');
    }

    public static function pathPrefix(): string {
        if (!self::isMulti()) return '';
        $tenant = self::current();
        return $tenant ? self::TENANTS[$tenant]['path'] : '';
    }

    public static function label(): string { $tenant = self::current(); return $tenant ? self::TENANTS[$tenant]['label'] : ''; }

    /** Every protected page goes through here, so the section is never optional. */
    public static function requireSelected(): void {
        if (!self::isMulti() || self::current() !== null) return;
        header('Location: /index.php?r=login');
        exit;
    }

    public static function databaseName(): string {
        $tenant = self::current();
        if ($tenant === null && PHP_SAPI === 'cli') {
            $cli = getenv('APP_TENANT');
            if (is_string($cli) && isset(self::TENANTS[$cli])) $tenant = $cli;
        }
        if (self::isMulti()) {
            if ($tenant === null) throw new \RuntimeException('ابتدا بخش آقایان یا خانم‌ها را انتخاب کنید.');
            $name = env_value('DB_DATABASE_' . strtoupper($tenant));
            if (is_string($name) && trim($name) !== '') return trim($name);
            throw new \RuntimeException('پایگاه‌دادهٔ ' . self::TENANTS[$tenant]['label'] . ' در تنظیمات سرور تعیین نشده است.');
        }
        // Single-section install (legacy config, and the scratch database every
        // DB/HTTP test points at through DB_DATABASE).
        $legacy = env_value('DB_DATABASE');
        if (is_string($legacy) && trim($legacy) !== '') return trim($legacy);
        throw new \RuntimeException('هیچ پایگاه‌داده‌ای تعیین نشده است؛ DB_DATABASE یا DB_DATABASE_MEN/DB_DATABASE_WOMEN را در .env مقداردهی کنید.');
    }

    /** Drop the cached connection so the next query reconnects to the current section. */
    public static function reset(): void { \App\Core\DB::reset(); }
}
