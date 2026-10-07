<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;
use App\Support\Jalali;

/** NULL session snapshot means ordinary commission; 0.00 is a valid Arab rate. */
final class ArabCommission {
    public const UPGRADE_MESSAGE = 'برای ثبت مشتری عرب یا پورسانت آن، ابتدا php bin/console migrate را اجرا کنید.';
    public const SETTING = 'arab_customer_commission_percent';

    public static function hasCustomerColumn(): bool {
        static $available;
        return $available ??= (bool)DB::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='is_arab_customer'");
    }

    public static function hasSessionColumn(): bool {
        static $available;
        return $available ??= (bool)DB::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='massage_sessions' AND COLUMN_NAME='arab_commission_percent'");
    }

    public static function percent(mixed $raw): ?string {
        if (!is_string($raw) && !is_int($raw)) return null;
        $value = trim(str_replace('٫', '.', Jalali::en((string)$raw)));
        if (!preg_match('/^(?:\d{1,2}|100)(?:\.\d{1,2})?$/D', $value)) return null;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ((int)$whole > 100 || ((int)$whole === 100 && (int)$fraction !== 0)) return null;
        return (string)(int)$whole . '.' . str_pad($fraction, 2, '0');
    }

    public static function snapshotForCustomer(int $customerId): ?string {
        if (!self::hasCustomerColumn()) return null;
        $arab = DB::value('SELECT is_arab_customer FROM customers WHERE id=? AND deleted_at IS NULL', [$customerId]);
        if (!$arab) return null;
        if (!self::hasSessionColumn()) throw new \RuntimeException(self::UPGRADE_MESSAGE);
        $raw = DB::value('SELECT `value` FROM settings WHERE `key`=?', [self::SETTING]);
        $rate = $raw === null ? null : self::percent($raw);
        if ($rate === null) throw new \RuntimeException('درصد مشتری عرب در تنظیمات ثبت نشده یا نامعتبر است؛ ابتدا آن را تنظیم کنید.');
        return $rate;
    }
}
