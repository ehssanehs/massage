<?php
declare(strict_types=1);

namespace App\Support;

/** Optional, inclusive date-of-birth bounds; full Jalali dates, not annual birthdays. */
final class BirthDateRange {
    public readonly ?string $from;
    public readonly ?string $to;
    public readonly array $values;
    public readonly array $errors;

    public function __construct(array $query = []) {
        $dates = $values = $errors = [];
        foreach (['birth_from'=>'تاریخ تولد از', 'birth_to'=>'تاریخ تولد تا'] as $key => $label) {
            $raw = array_key_exists($key, $query) ? $query[$key] : '';
            $value = is_string($raw) ? (preg_replace('/\A[\s\x{feff}]+|[\s\x{feff}]+\z/u', '', $raw) ?? $raw) : '';
            $date = is_string($raw) && $value !== '' ? Jalali::toGregorian($value) : null;
            $values[$key] = $date !== null ? Jalali::toJalali($date) : $value;
            $dates[$key] = $date;
            if (!is_string($raw) || ($value !== '' && $date === null)) {
                $errors[] = $label . ' باید یک تاریخ شمسی معتبر به صورت سال/ماه/روز باشد.';
            }
        }
        if (!$errors && $dates['birth_from'] !== null && $dates['birth_to'] !== null && $dates['birth_from'] > $dates['birth_to']) {
            $errors[] = 'ابتدای بازهٔ تاریخ تولد نباید بعد از انتهای آن باشد.';
        }
        $this->from = $dates['birth_from'];
        $this->to = $dates['birth_to'];
        $this->values = $values;
        $this->errors = $errors;
    }

    public function isValid(): bool {
        return $this->errors === [];
    }

    public function hasInput(): bool {
        return $this->from !== null || $this->to !== null || !$this->isValid();
    }

    /** Only validated, canonical Jalali bounds are carried to the next page. */
    public function queryParameters(): array {
        return $this->isValid() ? array_filter($this->values, static fn($value) => $value !== '') : [];
    }

    /** @return array{string, list<string>} WHERE predicate and Gregorian parameters. */
    public function predicate(): array {
        if (!$this->isValid()) return ['1=0', []];
        if (!$this->hasInput()) return ['', []];
        // NULL, empty and legacy zero dates are unknown, including in a to-only
        // search. Compare the sentinel as text to avoid strict-mode DATE literals.
        // The actual bounds still compare the original DATE column directly.
        $where = ["CAST(customers.birth_date AS CHAR) > '0000-00-00'"];
        $params = [];
        if ($this->from !== null) { $where[] = 'customers.birth_date >= ?'; $params[] = $this->from; }
        if ($this->to !== null) { $where[] = 'customers.birth_date <= ?'; $params[] = $this->to; }
        return [implode(' AND ', $where), $params];
    }
}
