<?php
declare(strict_types=1);

namespace App\Support;

/** Clock times (00:00–23:59), not MySQL TIME durations. No timezone conversion. */
final class ClockTime {
    public static function isEmpty(?string $value): bool {
        return self::clean((string)$value) === '';
    }

    /**
     * Accept 9, 930, 0930, 9:30 (Persian/Arabic/Latin digits).
     * Return a standard MySQL TIME, preserving explicit/legacy seconds.
     * Keep this grammar in sync with public/assets/js/timepicker.js.
     */
    public static function normalize(?string $value): ?string {
        $value = self::clean((string)$value);
        if (preg_match('/\A\d{1,2}\z/', $value)) {
            [$hour, $minute, $second] = [(int)$value, 0, 0];
        } elseif (preg_match('/\A\d{3,4}\z/', $value)) {
            [$hour, $minute, $second] = [(int)substr($value, 0, -2), (int)substr($value, -2), 0];
        } elseif (preg_match('/\A(\d{1,2}):(\d{1,2})(?::(\d{2}))?\z/', $value, $m)) {
            [$hour, $minute, $second] = [(int)$m[1], (int)$m[2], (int)($m[3] ?? 0)];
        } else {
            return null;
        }
        if ($hour > 23 || $minute > 59 || $second > 59) return null;
        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /** Plain display text; callers must escape it. Preserve invalid POSTs for correction. */
    public static function display(?string $value): string {
        $normalized = self::normalize($value);
        if ($normalized === null) return self::isEmpty($value) ? '' : (string)$value;
        return Jalali::fa(substr($normalized, -2) === '00' ? substr($normalized, 0, 5) : $normalized);
    }

    private static function clean(string $value): string {
        $value = Jalali::en($value);
        $value = strtr($value, ['：'=>':', '∶'=>':', '.'=>':', '٫'=>':']);
        $value = preg_replace('/[\x{200e}\x{200f}\x{061c}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\s\x{feff}]*:[\s\x{feff}]*/u', ':', $value) ?? $value;
        return preg_replace('/\A[\s\x{feff}]+|[\s\x{feff}]+\z/u', '', $value) ?? $value;
    }
}
