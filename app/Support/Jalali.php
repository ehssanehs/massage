<?php
declare(strict_types=1);

namespace App\Support;

final class Jalali {
    // Keep these bounds and the conversion algorithm in sync with JalaliPicker.
    public const MIN_YEAR = 1;
    public const MAX_YEAR = 1700;

    /** Format a Gregorian DATE/DATETIME from storage; never let PHP roll invalid dates over. */
    public static function toJalali(?string $date): string {
        $parts = self::gregorianParts($date);
        if ($parts === null) return '';
        [$jy, $jm, $jd] = self::gregorianToJalali($parts[0], $parts[1], $parts[2]);
        if ($jy < self::MIN_YEAR) return '';
        return self::fa(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    /** Preserve the local wall-clock time of a stored DATETIME (APP_TIMEZONE). */
    public static function dateTime(?string $date, bool $seconds = false): string {
        $formatted = self::toJalali($date);
        $parts = self::gregorianParts($date);
        if ($formatted === '' || $parts === null || $parts[3] === null) return $formatted;
        $time = sprintf('%02d:%02d', $parts[3], $parts[4]);
        if ($seconds) $time .= sprintf(':%02d', $parts[5]);
        return $formatted . ' ' . self::fa($time);
    }

    /**
     * Parse Jalali user input, accepting Persian, Arabic and Latin digits.
     * ISO Gregorian dates are opt-in ONLY for old report/export URLs, not forms.
     */
    public static function toGregorian(?string $jalali, bool $allowGregorian = false): ?string {
        $value = trim(self::en((string)$jalali));
        if ($value === '') return null;
        if ($allowGregorian && preg_match('/\A(\d{4})-\d{2}-\d{2}\z/', $value, $iso)
            && (int)$iso[1] > self::MAX_YEAR) {
            return self::gregorianParts($value) === null ? null : $value;
        }
        if (!preg_match('~\A(\d{4})([/.-])(\d{1,2})\2(\d{1,2})\z~', $value, $m)) return null;
        [$jy, $jm, $jd] = [(int)$m[1], (int)$m[3], (int)$m[4]];
        if (!self::isValid($jy, $jm, $jd)) return null;
        [$gy, $gm, $gd] = self::jalaliToGregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    public static function isValid(int $jy, int $jm, int $jd): bool {
        if ($jy < self::MIN_YEAR || $jy > self::MAX_YEAR || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return false;
        // Round-tripping checks month lengths AND Esfand in leap/non-leap years.
        return self::gregorianToJalali(...self::jalaliToGregorian($jy, $jm, $jd)) === [$jy, $jm, $jd];
    }

    /** Gregorian first day of the Jalali month containing $date, for SQL ranges. */
    public static function startOfMonth(?string $date = null): string {
        $parts = self::gregorianParts($date ?? date('Y-m-d'));
        if ($parts === null) throw new \InvalidArgumentException('Invalid Gregorian date.');
        [$jy, $jm] = self::gregorianToJalali($parts[0], $parts[1], $parts[2]);
        [$gy, $gm, $gd] = self::jalaliToGregorian($jy, $jm, 1);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Localize standalone date tokens in legacy CRM text without rewriting stored
     * history, amounts, phone numbers, identifiers or URLs. This returns plain text:
     * callers MUST still HTML-escape it. Already-Jalali dates are not converted twice.
     */
    public static function datesInText(?string $text): string {
        $digits = '0-9۰-۹٠-٩';
        $pattern = '~(?<![\p{L}\p{N}_/.\-])([' . $digits . ']{4})([/\-])([' . $digits . ']{1,2})\2([' . $digits . ']{1,2})'
            . '(?:[ T]([' . $digits . ']{2}):([' . $digits . ']{2})(?::([' . $digits . ']{2}))?)?'
            . '(?![\p{L}\p{N}_/\-]|\.[\p{L}\p{N}])~u';
        return preg_replace_callback($pattern, static function (array $m): string {
            [$year, $month, $day] = [(int)self::en($m[1]), (int)self::en($m[3]), (int)self::en($m[4])];
            $gregorian = $year <= self::MAX_YEAR
                ? self::toGregorian(sprintf('%04d/%02d/%02d', $year, $month, $day))
                : sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($gregorian === null) return $m[0];
            if (isset($m[5])) {
                $gregorian .= ' ' . self::en($m[5] . ':' . $m[6] . ':' . ($m[7] ?? '00'));
                $formatted = self::dateTime($gregorian, isset($m[7]));
            } else {
                $formatted = self::toJalali($gregorian);
            }
            return $formatted !== '' ? $formatted : $m[0];
        }, (string)$text) ?? (string)$text;
    }

    /** @return array{int, int, int, ?int, ?int, ?int}|null */
    private static function gregorianParts(?string $date): ?array {
        $date = trim(self::en((string)$date));
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?\z/', $date, $m)) return null;
        [$gy, $gm, $gd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (!checkdate($gm, $gd, $gy)) return null;
        $hour = isset($m[4]) ? (int)$m[4] : null;
        $minute = isset($m[5]) ? (int)$m[5] : null;
        $second = $hour === null ? null : (int)($m[6] ?? 0);
        if ($hour !== null && ($hour > 23 || $minute > 59 || $second > 59)) return null;
        return [$gy, $gm, $gd, $hour, $minute, $second];
    }

    public static function fa(string|int|float|null $s): string {
        return strtr((string)$s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    public static function en(string $s): string {
        return strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }

    public static function gregorianToJalali(int $gy, int $gm, int $gd): array {
        $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + $days % 31; }
        else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + ($days - 186) % 30; }
        return [$jy, $jm, $jd];
    }

    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array {
        $jy += 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4)
            + $jd + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $months = [0,31,$leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) $gd -= $months[$gm];
        return [$gy, $gm, $gd];
    }
}
