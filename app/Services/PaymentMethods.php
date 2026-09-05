<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Manage the payment-method pick-list used by massage sessions, expenses, etc.
 *
 * IMPORTANT — why this lives in the database (the `settings` table) and not in
 * PHP/config code: every record that carries a payment method (a massage session
 * or an expense) only stores the stable method *code* (e.g. 'cash'). The human
 * label and the ordering/enabled state must therefore come from a single source
 * of truth that is captured together with those records by a database backup.
 * Because the whole list is a row inside the `settings` table, a mysqldump
 * backup and its restore reproduce the exact list in use at backup time, so
 * restoring an old database never leaves codes pointing at labels the running
 * code does not know about.
 *
 * No migration/schema change is introduced. When the settings row is absent
 * (a fresh install, or an older backup taken before this feature existed),
 * the code falls back to the built-in defaults below — which are exactly the
 * codes historically stored by this app — so legacy data keeps working.
 */
final class PaymentMethods {

    /** settings `key` used to persist the ordered list. */
    public const KEY = 'payment_methods';

    /** Default code => label set used only when no persisted row exists. */
    private const DEFAULTS = [
        ['code' => 'cash',     'label' => 'نقدی',          'enabled' => true],
        ['code' => 'card',     'label' => 'کارتخوان',      'enabled' => true],
        ['code' => 'transfer', 'label' => 'انتقال بانکی',  'enabled' => true],
        ['code' => 'online',   'label' => 'آنلاین',        'enabled' => true],
        ['code' => 'other',    'label' => 'سایر',          'enabled' => true],
    ];

    /** Per-request cache so repeated reads don't hit the DB for every cell. */
    private static ?array $cache = null;

    /**
     * Ordered list of methods: array of ['code'=>string,'label'=>string,'enabled'=>bool].
     * Falls back to DEFAULTS whenever the setting is missing or unparsable.
     */
    public static function all(): array {
        if (self::$cache !== null) return self::$cache;
        $raw = DB::value('SELECT value FROM settings WHERE `key`=?', [self::KEY]);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                self::$cache = self::normalize($decoded);
                return self::$cache;
            }
        }
        return self::$cache = self::DEFAULTS;
    }

    /** Only methods currently usable for *new* records (enabled ones). */
    public static function enabled(): array {
        return array_values(array_filter(self::all(), static fn(array $m): bool => !empty($m['enabled'])));
    }

    /** Human label for a stored code, or null when the code is not configured. */
    public static function label(string $code): ?string {
        foreach (self::all() as $m) {
            if ((string)$m['code'] === $code) return (string)$m['label'];
        }
        return null;
    }

    /** Number of stored records currently referencing this code. */
    public static function usageCount(string $code): int {
        $sessions = (int)DB::value('SELECT COUNT(*) FROM massage_sessions WHERE payment_method=?', [$code]);
        $expenses = (int)DB::value('SELECT COUNT(*) FROM expenses WHERE payment_method=?', [$code]);
        return $sessions + $expenses;
    }

    /** Append a brand-new method; generates a unique, stable ascii code. */
    public static function add(array $methods, string $label): array {
        $codes = array_column($methods, 'code');
        $base = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($label))) ?? 'method';
        $base = trim((string)$base, '-');
        if (strlen($base) < 3) $base = 'method';
        $code = $base;
        for ($i = 2; in_array($code, $codes, true); $i++) $code = $base . '-' . $i;
        $methods[] = ['code' => $code, 'label' => $label, 'enabled' => true];
        return $methods;
    }

    /** Rename the label of an existing method (its stable code never changes). */
    public static function rename(array $methods, string $code, string $label): array {
        foreach ($methods as &$m) {
            if ((string)$m['code'] === $code) { $m['label'] = $label; return $methods; }
        }
        throw new \InvalidArgumentException('روش پرداخت یافت نشد.');
    }

    /** Enable/disable an existing method; usage is never blocked (only new picks). */
    public static function toggle(array $methods, string $code): array {
        foreach ($methods as &$m) {
            if ((string)$m['code'] === $code) { $m['enabled'] = !empty($m['enabled']) ? false : true; return $methods; }
        }
        throw new \InvalidArgumentException('روش پرداخت یافت نشد.');
    }

    /** Remove a method entirely. Callers must guard against in-use codes first. */
    public static function remove(array $methods, string $code): array {
        foreach ($methods as $i => $m) {
            if ((string)$m['code'] === $code) { unset($methods[$i]); return array_values($methods); }
        }
        throw new \InvalidArgumentException('روش پرداخت یافت نشد.');
    }

    /** Persist the full ordered list to the settings table. */
    public static function save(array $methods): void {
        $json = json_encode(array_values(self::normalize($methods)), JSON_UNESCAPED_UNICODE);
        DB::exec(
            'INSERT INTO settings (`key`,`value`,type,group_name,updated_at) VALUES (?,?,?,?,NOW()) '
            . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), type=VALUES(type), group_name=VALUES(group_name), updated_at=NOW()',
            [self::KEY, $json, 'json', 'finance']
        );
        self::$cache = null; // invalidate cache after writing
    }

    /** Sanitize arbitrary input into a stable list shape and keep order. */
    private static function normalize(array $items): array {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $code = (string)($item['code'] ?? '');
            if ($code === '' || isset($seen[$code])) continue;
            $label = trim((string)($item['label'] ?? ''));
            if ($label === '') continue;
            $seen[$code] = true;
            $out[] = ['code' => $code, 'label' => $label, 'enabled' => isset($item['enabled']) ? (bool)$item['enabled'] : true];
        }
        return $out;
    }
}
