<?php
declare(strict_types=1);
namespace App\Support;

use PDO;
use PDOException;

/**
 * Public-site content store.
 *
 * The marketing pages need copy and a massage catalog, but an anonymous visitor
 * must never touch a section's CRM tables. So the site owns its own connection
 * to SITE_DATABASE (falling back to DB_DATABASE, then the first configured
 * section) and only ever queries `site_blocks` and `massage_types`.
 *
 * Both staff sections share one public site, so one database holds the content
 * even when the CRMs are split in two.
 */
final class SiteContent {
    private static ?PDO $pdo = null;
    private static ?array $blocks = null;
    private static ?array $types = null;

    /**
     * Test seam: when set, reads are answered from these arrays instead of
     * MySQL, so the route harness renders the public pages without a database.
     * Shape: ['blocks' => [key => row], 'types' => [row, ...]].
     */
    public static ?array $fixtures = null;

    /** Forget cached content (after a panel edit, or between tests). */
    public static function reset(): void {
        self::$pdo = null;
        self::$blocks = null;
        self::$types = null;
    }

    public static function database(): string {
        $explicit = env_value('SITE_DATABASE', '');
        if (is_string($explicit) && trim($explicit) !== '') return trim($explicit);
        $legacy = env_value('DB_DATABASE', '');
        if (is_string($legacy) && trim($legacy) !== '') return trim($legacy);
        foreach (['MEN', 'WOMEN'] as $suffix) {
            $name = env_value('DB_DATABASE_' . $suffix, '');
            if (is_string($name) && trim($name) !== '') return trim($name);
        }
        throw new \RuntimeException('پایگاه‌دادهٔ محتوای سایت تعیین نشده است؛ SITE_DATABASE را در .env مقداردهی کنید.');
    }

    public static function pdo(): PDO {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $host = env_value('DB_HOST', '127.0.0.1');
        $port = env_value('DB_PORT', '3306');
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . self::database() . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, env_value('DB_USERNAME', 'root'), env_value('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    /** True when the content tables exist (a site that has not been migrated yet). */
    public static function ready(): bool {
        try {
            self::pdo()->query('SELECT 1 FROM site_blocks LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Every editable block, keyed by name. Missing keys fall back to the default. */
    public static function blocks(): array {
        if (self::$fixtures !== null) return self::$fixtures['blocks'] ?? [];
        if (self::$blocks !== null) return self::$blocks;
        $out = [];
        try {
            foreach (self::pdo()->query('SELECT `key`, `value`, `group_name`, `type` FROM site_blocks') as $row) {
                $out[$row['key']] = $row;
            }
        } catch (PDOException $e) {
            $out = [];
        }
        return self::$blocks = $out;
    }

    /**
     * A block's text, or $default when the block is absent or empty. Empty is a
     * real state: the template hides the element rather than showing a placeholder.
     */
    public static function block(string $key, string $default = ''): string {
        $row = self::blocks()[$key] ?? null;
        $value = is_array($row) ? trim((string)($row['value'] ?? '')) : '';
        return $value !== '' ? $value : $default;
    }

    /** Blocks grouped for the panel editor, in a stable order. */
    public static function grouped(): array {
        $groups = [];
        foreach (self::blocks() as $key => $row) {
            $groups[(string)$row['group_name']][$key] = $row;
        }
        return $groups;
    }

    public static function setBlock(string $key, string $value): void {
        if (self::$fixtures !== null) {
            // Fixture mode records the write instead of reaching MySQL.
            self::$fixtures['writes'][] = ['key' => $key, 'value' => $value];
            return;
        }
        $st = self::pdo()->prepare(
            'INSERT INTO site_blocks (`key`, `value`) VALUES (:k, :v) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
        );
        $st->execute([':k' => $key, ':v' => $value]);
        self::$blocks = null;
    }

    /** Active catalog entries in display order. */
    public static function types(): array {
        if (self::$fixtures !== null) return self::$fixtures['types'] ?? [];
        if (self::$types !== null) return self::$types;
        try {
            $st = self::pdo()->query(
                "SELECT id, slug, title, summary, body, illustration, duration_minutes, price_from, sort_order "
                . "FROM massage_types WHERE status = 'active' AND deleted_at IS NULL ORDER BY sort_order, id"
            );
            self::$types = $st->fetchAll();
        } catch (PDOException $e) {
            self::$types = [];
        }
        return self::$types;
    }

    public static function type(string $slug): ?array {
        foreach (self::types() as $type) {
            if ($type['slug'] === $slug) return $type;
        }
        return null;
    }
}
