<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Support\BirthDateRange;
use App\Support\DateRange;
use App\Support\Jalali;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

foreach ([[], ['birth_from'=>'', 'birth_to'=>''], ['birth_from'=>" \t\u{00a0}", 'birth_to'=>"\u{feff}"]] as $query) {
    $range = new BirthDateRange($query);
    same(true, $range->isValid(), 'Blank bounds are valid');
    same(false, $range->hasInput(), 'No implicit current-month or today filter');
    same(null, $range->from, 'No lower default');
    same(null, $range->to, 'No upper default');
    same(['birth_from'=>'', 'birth_to'=>''], $range->values, 'Empty date inputs');
    same([], $range->queryParameters(), 'No empty filter parameters in pagination');
    same(['', []], $range->predicate(), 'No hidden birth predicate');
}
foreach (['1369/01/01', '۱۳۶۹-۱-۱', '١٣٦٩.١.١', "\u{00a0}۱۳۶۹/۰۱/۰۱\u{00a0}"] as $from) {
    $range = new BirthDateRange(['birth_from'=>$from, 'birth_to'=>'۱۳۶۹/۰۱/۰۳']);
    same(true, $range->isValid(), 'Valid Jalali range');
    same('1990-03-21', $range->from, 'Gregorian lower SQL bound');
    same('1990-03-23', $range->to, 'Gregorian upper SQL bound');
    same(['birth_from'=>'۱۳۶۹/۰۱/۰۱', 'birth_to'=>'۱۳۶۹/۰۱/۰۳'], $range->queryParameters(), 'Canonical Jalali links');
    [$sql, $params] = $range->predicate();
    same(true, str_contains($sql, 'customers.birth_date >= ?'), 'Inclusive start');
    same(true, str_contains($sql, 'customers.birth_date <= ?'), 'Inclusive end');
    same(['1990-03-21', '1990-03-23'], $params, 'Bound, not interpolated dates');
}
$fromOnly = new BirthDateRange(['birth_from'=>'1369/01/01']);
same(null, $fromOnly->to, 'Open upper endpoint');
same(['birth_from'=>'۱۳۶۹/۰۱/۰۱'], $fromOnly->queryParameters(), 'Lower-only pagination');
same(['1990-03-21'], $fromOnly->predicate()[1], 'One lower parameter');
same(false, str_contains($fromOnly->predicate()[0], '<='), 'No invented upper condition');
$toOnly = new BirthDateRange(['birth_to'=>'1368/12/29']);
same(null, $toOnly->from, 'Open lower endpoint');
same(['birth_to'=>'۱۳۶۸/۱۲/۲۹'], $toOnly->queryParameters(), 'Upper-only pagination');
same(['1990-03-20'], $toOnly->predicate()[1], 'One upper parameter');
same(true, str_contains($toOnly->predicate()[0], "CAST(customers.birth_date AS CHAR) > '0000-00-00'"), 'Unknown/zero dates excluded even with only an upper bound');
$leap = new BirthDateRange(['birth_from'=>'۱۴۰۳/۱۲/۳۰', 'birth_to'=>'۱۴۰۴/۰۱/۰۱']);
same(true, $leap->isValid(), 'Leap Esfand crosses Nowruz');
same(['2025-03-20', '2025-03-21'], $leap->predicate()[1], 'Leap/year-boundary storage dates');
$exact = new BirthDateRange(['birth_from'=>'1369/01/01', 'birth_to'=>'1369/01/01']);
same(true, $exact->isValid(), 'Equal bounds search one birth date');
same(['1990-03-21', '1990-03-21'], $exact->predicate()[1], 'Exact-day bounds stay equal');

foreach ([
    ['birth_from'=>'1404/12/30'], ['birth_to'=>'1369/07/31'], ['birth_from'=>'0'], ['birth_from'=>['1369/01/01']],
    ['birth_to'=>null], ['birth_to'=>false], ['birth_from'=>'1990-03-21'], ['birth_to'=>'<script>alert(1)</script>'],
    ['birth_from'=>'1369/01/03', 'birth_to'=>'1369/01/01'],
] as $query) {
    $range = new BirthDateRange($query);
    same(false, $range->isValid(), 'Invalid/reversed input is rejected');
    same(true, $range->hasInput(), 'Bad filters can be cleared');
    same(['1=0', []], $range->predicate(), 'Bad bounds never silently become an unfiltered query');
    same([], $range->queryParameters(), 'Invalid filters are not propagated to a pager');
    foreach ($query as $key => $raw) {
        $expected = is_string($raw) ? (Jalali::toGregorian($raw) !== null ? Jalali::toJalali(Jalali::toGregorian($raw)) : $raw) : '';
        same($expected, $range->values[$key], 'Retain correctable date text');
    }
}
$report = new DateRange([], '2026-09-05');
same('2026-08-23', $report->from, 'Reports still default to the Jalali month, unlike birth filters');
same('2026-09-05', $report->to, 'Report default end is unchanged');
echo "OK: {$assertions} birth-date range assertions\n";
