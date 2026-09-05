<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\BackupService;
use App\Support\DateRange;
use App\Support\Jalali;
use App\Support\View;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/dates.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixtures as $date) {
    same(Jalali::fa($date['jalali']), Jalali::toJalali($date['gregorian']), 'Known Gregorian date');
    foreach (['/', '-', '.'] as $separator) {
        $input = str_replace('/', $separator, $date['jalali']);
        same($date['gregorian'], Jalali::toGregorian($input), 'Latin input with ' . $separator);
        same($date['gregorian'], Jalali::toGregorian(Jalali::fa($input)), 'Persian input with ' . $separator);
        $arabic = strtr($input, array_combine(str_split('0123456789'), preg_split('//u', '٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY)));
        same($date['gregorian'], Jalali::toGregorian($arabic), 'Arabic input with ' . $separator);
    }
}
same('2026-03-21', Jalali::toGregorian(' ۱۴۰۵/۱/۱ '), 'Unpadded month/day');
foreach ([null, '', ' ', '1404/12/30', '1405/07/31', '1405/00/01', '1405/13/01', '1405/01/00', '1405/01/32', '0000/01/01', '1701/01/01', '1405/01-01', '1405/1/1junk', '1405abc/1/1', '1405/1/1/1', '2026-03-21', '2026/03/21', 'tomorrow', '<script>'] as $invalid) {
    same(null, Jalali::toGregorian($invalid), 'Invalid or non-Jalali input: ' . $invalid);
}
same('2026-03-21', Jalali::toGregorian('2026-03-21', true), 'Legacy ISO URL opt-in');
same('2026-03-21', Jalali::toGregorian('1405-01-01', true), 'Hyphenated Jalali is NEVER mistaken for Gregorian');
foreach (['2026-02-30', '2026-13-01', '2026-00-01', '2026-01-00', '2026/03/21'] as $invalid) {
    same(null, Jalali::toGregorian($invalid, true), 'Invalid legacy ISO date');
}
foreach ([null, '', '0000-00-00', '2026-02-30', '2026-13-01', '2026-01-01 24:00:00', '2026-01-01 12:60:00', '2026-01-01 12:00:60', 'not a date', 'tomorrow'] as $invalid) {
    same('', Jalali::toJalali($invalid), 'Invalid stored dates must not roll over');
    same('', Jalali::dateTime($invalid), 'Invalid timestamp');
}
date_default_timezone_set('UTC');
same('۱۳۴۸/۱۰/۱۱', Jalali::toJalali('1970-01-01'), 'Unix epoch is not an empty date');
same('۱۴۰۵/۰۱/۰۱ ۰۰:۰۵', Jalali::dateTime('2026-03-21 00:05:09'), 'Timestamp retains time');
same('۱۴۰۵/۰۱/۰۱ ۰۰:۰۵:۰۹', Jalali::dateTime('2026-03-21T00:05:09', true), 'Timestamp retains seconds');
same('۱۴۰۵/۰۱/۰۱', Jalali::dateTime('2026-03-21'), 'DATE has no invented time');
date_default_timezone_set('Asia/Tehran');
same('۱۴۰۵/۰۱/۰۱ ۰۰:۰۵', Jalali::dateTime('2026-03-21 00:05:09'), 'DATETIME wall time is not shifted');

foreach ([
    '2026-03-20' => '2026-02-20',
    '2026-03-21' => '2026-03-21',
    '2026-04-01' => '2026-03-21',
    '2026-09-05' => '2026-08-23',
    '2026-09-23' => '2026-09-23',
    '2025-03-20' => '2025-02-19',
] as $date => $start) {
    same($start, Jalali::startOfMonth($date), 'Start of Jalali month');
    $range = new DateRange([], $date);
    same(true, $range->isValid(), 'Default range is valid');
    same($start, $range->from, 'Report and salary default from');
    same($date, $range->to, 'Report and salary default to');
    same(Jalali::toJalali($start), $range->values['from'], 'Filter value is Jalali');
}
$range = new DateRange(['from'=>'۱۴۰۳/۱۲/۳۰', 'to'=>'1404-01-02'], '2026-09-05');
same([], $range->errors, 'Range across Nowruz');
same('2025-03-20', $range->from, 'Range Gregorian SQL lower bound');
same('2025-03-22', $range->to, 'Range Gregorian SQL upper bound');
same(['from'=>'۱۴۰۳/۱۲/۳۰', 'to'=>'۱۴۰۴/۰۱/۰۲'], $range->values, 'Export link uses canonical Jalali dates');
$legacy = new DateRange(['from'=>'2025-03-20', 'to'=>'2025-03-22']);
same($range->values, $legacy->values, 'Old bookmarked/export URLs still display Jalali');
foreach ([['from'=>''], ['to'=>'1404/12/30'], ['from'=>['1405/01/01']], ['from'=>null], ['from'=>'1405/01/02', 'to'=>'1405/01/01']] as $query) {
    same(false, (new DateRange($query, '2026-09-05'))->isValid(), 'Bad filters cannot trigger calculations/exports');
}
same('1404/12/30', (new DateRange(['from'=>'1404/12/30']))->values['from'], 'Preserve invalid input for correction');

$text = 'تاریخ پیگیری: 2026-03-21؛ تماس در ۲۰۲۶/۰۹/۰۵ ۱۳:۰۵:۰۹ و ٢٠٢٥-٠٣-٢٠.';
same('تاریخ پیگیری: ۱۴۰۵/۰۱/۰۱؛ تماس در ۱۴۰۵/۰۶/۱۴ ۱۳:۰۵:۰۹ و ۱۴۰۳/۱۲/۳۰.', Jalali::datesInText($text), 'Historical CRM text, multiple dates/digit systems');
same('پیگیری: ۱۴۰۵/۰۱/۰۱', Jalali::datesInText('پیگیری: 1405-01-01'), 'Already-Jalali text is not converted twice');
same(Jalali::datesInText($text), Jalali::datesInText(Jalali::datesInText($text)), 'Text localization is idempotent');
foreach (['مبلغ: 2500000؛ تلفن: 09123456789؛ کد: C260727101', 'کد INV-2026-03-21 و 2026-03-21-123', 'https://example.test/2026-03-21/file', 'backup-20260321-120000.sql', 'نام 2026-03-21.sql', '2026-02-30', '1404/12/30', 'متن بدون تاریخ'] as $unchanged) {
    same($unchanged, Jalali::datesInText($unchanged), 'Do not alter non-date tokens/invalid dates');
}
same('', Jalali::datesInText(null), 'Nullable CRM body');
same('&lt;b&gt;۱۴۰۵/۰۱/۰۱&lt;/b&gt;', e(Jalali::datesInText('<b>2026-03-21</b>')), 'Converted CRM text must still be escaped');

$html = View::dateInput('birth_date', '1405-01-01', true);
same(true, str_contains($html, 'value="۱۴۰۵/۰۱/۰۱"'), 'Shared renderer canonicalizes valid Jalali input');
same(true, str_contains($html, 'type="text"') && str_contains($html, 'data-jdp') && str_contains($html, ' required'), 'Shared renderer works without a native Gregorian calendar');
same(true, str_contains(View::dateInput('date', '1404/12/30'), 'value="1404/12/30"'), 'Invalid POST remains editable');
same(true, str_contains(View::dateInput('date', '2026-03-21'), 'value="2026-03-21"'), 'Do not reinterpret invalid Gregorian POST as valid input');
same(false, str_contains(View::dateInput('date', '"><script>alert(1)</script>'), '<script>'), 'Input HTML escaping');
same('پشتیبان — ۱۴۰۵/۰۶/۱۴ ۱۰:۲۰:۳۰', BackupService::displayName('backup-20260905-102030.sql'), 'Backup list date is Jalali without renaming files');
same('manual.sql', BackupService::displayName('manual.sql'), 'Other backup filenames unchanged');

// Catch rollover and conversion regressions through multiple leap-year boundaries.
for ($year = 1398; $year <= 1408; $year++) {
    for ($month = 1; $month <= 12; $month++) {
        for ($day = 1; $day <= 31; $day++) {
            $j = sprintf('%04d/%02d/%02d', $year, $month, $day);
            $g = Jalali::toGregorian($j);
            same(Jalali::isValid($year, $month, $day), $g !== null, 'Validation and conversion agree');
            if ($g !== null) same(Jalali::fa($j), Jalali::toJalali($g), 'Full Jalali date round-trip');
        }
    }
}
echo "OK: $assertions PHP date assertions\n";
