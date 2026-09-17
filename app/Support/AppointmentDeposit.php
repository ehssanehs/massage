<?php
declare(strict_types=1);
namespace App\Support;

use App\Core\DB;

final class AppointmentDeposit {
    public const UPGRADE_MESSAGE = 'برای ثبت یا فیلتر بیعانه، ابتدا ارتقای دیتابیس را با php bin/console migrate انجام دهید.';

    /** Exact nonnegative DECIMAL(15,2); never round a received payment. */
    public static function normalize(mixed $raw): ?string {
        if (!is_string($raw) && !is_int($raw)) return null;
        $value = trim(str_replace('٫', '.', Jalali::en((string)$raw)));
        if ($value === '') return '0.00';
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) return null;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > 13) return null;
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }

    public static function display(mixed $raw): string {
        $value = self::normalize($raw);
        if ($value === null) return '—';
        [$whole, $fraction] = explode('.', $value);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        return Jalali::fa($whole . ($fraction === '00' ? '' : '.' . $fraction)) . ' ریال';
    }

    public static function isAvailable(): bool {
        static $available;
        return $available ??= (bool)DB::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='deposit_amount'");
    }
}
