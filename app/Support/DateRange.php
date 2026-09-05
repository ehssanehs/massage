<?php
declare(strict_types=1);

namespace App\Support;

/** Shared report/salary/export boundaries: Jalali UI, Gregorian SQL parameters. */
final class DateRange {
    public readonly ?string $from;
    public readonly ?string $to;
    public readonly array $values;
    public readonly array $errors;

    public function __construct(array $query = [], ?string $today = null) {
        $today ??= date('Y-m-d');
        $defaults = ['from' => Jalali::startOfMonth($today), 'to' => $today];
        $dates = $values = $errors = [];
        foreach (['from' => 'از تاریخ', 'to' => 'تا تاریخ'] as $key => $label) {
            $raw = array_key_exists($key, $query) ? $query[$key] : Jalali::toJalali($defaults[$key]);
            $date = is_string($raw) ? Jalali::toGregorian($raw, true) : null;
            $values[$key] = $date !== null ? Jalali::toJalali($date) : (is_string($raw) ? $raw : '');
            $dates[$key] = $date;
            if ($date === null) $errors[] = $label . ' باید یک تاریخ شمسی معتبر به صورت سال/ماه/روز باشد.';
        }
        if (!$errors && $dates['from'] > $dates['to']) $errors[] = 'تاریخ شروع نباید بعد از تاریخ پایان باشد.';
        $this->from = $dates['from'];
        $this->to = $dates['to'];
        $this->values = $values;
        $this->errors = $errors;
    }

    public function isValid(): bool {
        return $this->errors === [];
    }
}
