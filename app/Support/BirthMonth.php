<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Filter customers by Jalali birth MONTH (1=Farvardin .. 12=Esfand),
 * regardless of birth year. Replaces the old birth_from/birth_to range filter.
 *
 * A Jalali month spans parts of two Gregorian months (e.g. Mehr ≈ Sep 22..Oct 23)
 * and the exact Gregorian boundaries drift between years because the 33-year
 * Jalali leap cycle never aligns with the 4/100/400 Gregorian one. The WINDOWS
 * table below was derived from the project's own Jalali algorithm over birth
 * years 1920-2026 and gives, per Jalali month, the union of Gregorian
 * (month, dayFrom..dayTo) windows ever covered, keyed by whether the BIRTH
 * year's Gregorian year is a leap year. Every true member day is inside its
 * window (no misses); the only cost is up to one boundary day per window that
 * may belong to the adjacent Jalali month in a minority of years — the same
 * trade-off the old single-boundary approach had, but bounded and documented.
 *
 * The SQL predicate uses only portable YEAR()/MONTH()/DAY() functions — no
 * REGEXP, no stored conversion, works on MySQL 5.7+/MariaDB and SQLite.
 */
final class BirthMonth {
    public const PARAM = 'birth_month';

    /** Jalali month number => Persian name. */
    public const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /**
     * Gregorian windows per Jalali month, split by leap status of the birth
     * year's Gregorian year. Each entry is [gregorianMonth, dayFrom, dayTo].
     * 'C' = common Gregorian year, 'L' = leap Gregorian year.
     * Derived from App\Support\Jalali over birth years 1920-2026.
     */
    private const WINDOWS = [
        1 => [
            'C' => [[3, 21, 31], [4, 1, 21]],
            'L' => [[3, 20, 31], [4, 1, 20]],
        ],
        2 => [
            'C' => [[4, 21, 30], [5, 1, 22]],
            'L' => [[4, 20, 30], [5, 1, 21]],
        ],
        3 => [
            'C' => [[5, 22, 31], [6, 1, 22]],
            'L' => [[5, 21, 31], [6, 1, 21]],
        ],
        4 => [
            'C' => [[6, 22, 30], [7, 1, 23]],
            'L' => [[6, 21, 30], [7, 1, 22]],
        ],
        5 => [
            'C' => [[7, 23, 31], [8, 1, 23]],
            'L' => [[7, 22, 31], [8, 1, 22]],
        ],
        6 => [
            'C' => [[8, 23, 31], [9, 1, 23]],
            'L' => [[8, 22, 31], [9, 1, 22]],
        ],
        7 => [
            'C' => [[9, 23, 30], [10, 1, 23]],
            'L' => [[9, 22, 30], [10, 1, 22]],
        ],
        8 => [
            'C' => [[10, 23, 31], [11, 1, 22]],
            'L' => [[10, 22, 31], [11, 1, 21]],
        ],
        9 => [
            'C' => [[11, 22, 30], [12, 1, 22]],
            'L' => [[11, 21, 30], [12, 1, 21]],
        ],
        10 => [
            'C' => [[1, 1, 21], [12, 22, 31]],
            'L' => [[1, 1, 21], [12, 21, 31]],
        ],
        11 => [
            'C' => [[1, 20, 31], [2, 1, 20]],
            'L' => [[1, 21, 31], [2, 1, 20]],
        ],
        12 => [
            'C' => [[2, 19, 28], [3, 1, 21]],
            'L' => [[2, 20, 29], [3, 1, 20]],
        ],
    ];

    public readonly ?int $month;
    public readonly array $errors;

    public function __construct(array $query = []) {
        $raw = $query[self::PARAM] ?? '';
        if (is_array($raw)) $raw = '';
        $value = Jalali::en(trim((string)$raw));
        $errors = [];
        $month = null;
        if ($value !== '') {
            if (!preg_match('/\A\d{1,2}\z/', $value)) {
                $errors[] = 'ماه تولد باید یکی از ماه‌های ۱ تا ۱۲ باشد.';
            } else {
                $month = (int)$value;
                if ($month < 1 || $month > 12) {
                    $errors[] = 'ماه تولد باید یکی از ماه‌های ۱ تا ۱۲ باشد.';
                    $month = null;
                }
            }
        }
        $this->month = $month;
        $this->errors = $errors;
    }

    public function isValid(): bool {
        return $this->errors === [];
    }

    public function hasInput(): bool {
        return $this->month !== null || !$this->isValid();
    }

    /** Only a validated month number is carried to the next page. */
    public function queryParameters(): array {
        if (!$this->isValid() || $this->month === null) return [];
        return [self::PARAM => (string)$this->month];
    }

    public function selectedLabel(): string {
        return $this->month !== null ? (self::MONTHS[$this->month] ?? '') : '';
    }

    /** @return array{string, list<string>} WHERE predicate and (empty) parameters. */
    public function predicate(): array {
        if (!$this->isValid()) return ['1=0', []];
        if ($this->month === null) return ['', []];
        $bd = 'customers.birth_date';
        // NULL, empty and legacy zero dates are unknown birthdays — never a match.
        $prefix = "CAST($bd AS CHAR) > '0000-00-00' AND ";
        $leap = '((YEAR(' . $bd . ') % 4 = 0 AND YEAR(' . $bd . ') % 100 <> 0) OR YEAR(' . $bd . ') % 400 = 0)';
        $common = self::branchSql(self::WINDOWS[$this->month]['C']);
        $leapBranch = self::branchSql(self::WINDOWS[$this->month]['L']);
        return [$prefix . '(' . $common . ' OR (' . $leap . ' AND ' . $leapBranch . '))', []];
    }

    /** @param list<array{int,int,int>} $branches */
    private static function branchSql(array $branches): string {
        $bd = 'customers.birth_date';
        $m = "MONTH($bd)";
        $d = "DAY($bd)";
        $parts = [];
        foreach ($branches as [$gm, $from, $to]) {
            $parts[] = "($m = $gm AND $d BETWEEN $from AND $to)";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }
}
