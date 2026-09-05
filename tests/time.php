<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Support\ClockTime;
use App\Support\Jalali;
use App\Support\View;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

foreach (json_decode(file_get_contents(__DIR__ . '/fixtures/times.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
    same($case['normalized'], ClockTime::normalize($case['input']), 'Shared time fixture: ' . $case['input']);
}
foreach ([null, '', ' ', "\t\n", "\u{00a0}", "\u{feff}", "\u{0085}", "\u{200f} \u{200e}"] as $empty) {
    same(true, ClockTime::isEmpty($empty), 'Optional empty time');
    same('', ClockTime::display($empty), 'Empty display');
}
same(false, ClockTime::isEmpty('0'), 'Midnight is NOT empty');
same(false, ClockTime::isEmpty('bad'), 'Bad times are NOT silently blanked');
same('۲۵:۶۰', ClockTime::display('۲۵:۶۰'), 'Preserve invalid POST for correction');
same('۰۹:۳۰', ClockTime::display('930'), 'Compact input display');
same('۱۰:۰۷:۰۹', ClockTime::display('10:07:09'), 'Legacy seconds are not dropped');

for ($hour = 0; $hour < 24; $hour++) {
    for ($minute = 0; $minute < 60; $minute++) {
        $time = sprintf('%02d:%02d:00', $hour, $minute);
        $input = sprintf('%02d:%02d', $hour, $minute);
        $compact = sprintf('%d%02d', $hour, $minute);
        same($time, ClockTime::normalize($input), 'Every minute');
        same($time, ClockTime::normalize($compact), 'Compact input, including midnight');
        same($time, ClockTime::normalize(Jalali::fa($compact)), 'Persian compact input');
        same($time, ClockTime::normalize(ClockTime::display($time)), 'Stored time edit round-trip');
        $seconds = sprintf('%02d:%02d:%02d', $hour, $minute, ($hour + $minute) % 60);
        same($seconds, ClockTime::normalize(ClockTime::display($seconds)), 'Seconds edit round-trip');
    }
}

foreach (['Asia/Tehran', 'UTC', 'America/Los_Angeles'] as $timezone) {
    date_default_timezone_set($timezone);
    same('23:45:09', ClockTime::normalize(ClockTime::display('23:45:09')), 'Wall-clock times do not shift');
}
$html = View::timeInput('start_time', '10:07:09', true, 'start', 'ساعت شروع');
same(true, str_contains($html, 'type="text"'), 'No native segmented/AM-PM input');
same(true, str_contains($html, 'value="۱۰:۰۷:۰۹"'), 'Form preserves seconds');
same(true, str_contains($html, 'data-time-input dir="ltr" inputmode="numeric"'), 'LTR and mobile numeric keyboard');
same(true, str_contains($html, 'required'), 'Required time preserved');
same(true, str_contains($html, 'data-time-toggle hidden'), 'No dead picker button without JavaScript');
same(1, substr_count($html, 'name="start_time"'), 'Exactly one submitted time field');
same(true, str_contains($html, 'aria-describedby="start_hint start_error"'), 'Accessible hint and error');
same(false, str_contains(View::timeInput('end_time'), ' required'), 'Optional time remains optional');
$html = View::timeInput('start_time', '"><script>alert(1)</script>');
same(false, str_contains($html, '<script>'), 'Invalid posted time is escaped');
same(true, str_contains($html, '&quot;&gt;&lt;script&gt;'), 'Invalid time remains visible for correction');
echo "OK: {$assertions} time assertions\n";
