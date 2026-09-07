<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/fixtures/SearchFixture.php';

use App\Support\BirthMonth;
use App\Support\Jalali;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

// Empty input: no filter, no error, no pagination params.
foreach ([[], ['birth_month' => ''], ['birth_month' => " \t"]] as $query) {
    $filter = new BirthMonth($query);
    same(true, $filter->isValid(), 'Blank month is valid');
    same(false, $filter->hasInput(), 'No implicit birth filter');
    same(null, $filter->month, 'No month selected');
    same([], $filter->queryParameters(), 'No empty filter parameters in pagination');
    same(['', []], $filter->predicate(), 'No hidden birth predicate');
    same('', $filter->selectedLabel(), 'No label without a month');
}

// Every Jalali month 1..12 is accepted, in Latin and Persian digits.
foreach (range(1, 12) as $m) {
    foreach ([(string)$m, Jalali::fa($m), ' ' . Jalali::fa($m) . ' '] as $raw) {
        $filter = new BirthMonth(['birth_month' => $raw]);
        same(true, $filter->isValid(), "Month $m accepted");
        same($m, $filter->month, "Month $m parsed");
        same(true, $filter->hasInput(), "Month $m counts as a filter");
        same(['birth_month' => (string)$m], $filter->queryParameters(), "Month $m survives pagination");
        same(BirthMonth::MONTHS[$m], $filter->selectedLabel(), "Month $m label");
        [$sql, $params] = $filter->predicate();
        same([], $params, 'Month predicate binds no parameters');
        same(true, str_contains($sql, "CAST(customers.birth_date AS CHAR) > '0000-00-00'"), 'Unknown/zero dates never match');
        same(true, str_contains($sql, 'YEAR(customers.birth_date)'), 'Leap-aware windows');
    }
}

// The month names in order.
same(['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'], array_values(BirthMonth::MONTHS), 'Twelve Jalali month names');

// Invalid input: error, fail-closed predicate, never propagated to pager.
foreach (['0', '13', '99', 'abc', '1.5', '-1', '+7', 'مهر'] as $raw) {
    $filter = new BirthMonth(['birth_month' => $raw]);
    same(false, $filter->isValid(), "Invalid month rejected: $raw");
    same(true, $filter->hasInput(), 'Bad filters can be cleared');
    same(null, $filter->month, "No month stored for: $raw");
    same(['1=0', []], $filter->predicate(), 'Bad months never silently become an unfiltered query');
    same([], $filter->queryParameters(), 'Invalid filters are not propagated to a pager');
}
// Non-string input (e.g. birth_month[]=7) is treated as empty, like other filters.
$arrayInput = new BirthMonth(['birth_month' => ['7']]);
same(true, $arrayInput->isValid(), 'Array input is ignored, not an error');
same(null, $arrayInput->month, 'Array input selects nothing');
same(['', []], $arrayInput->predicate(), 'Array input filters nothing');

// Real-engine recall: every calendar day 1920-2026 is found by its own month.
$engines = [];
$dsn = getenv('BIRTHMONTH_TEST_MYSQL_DSN');
if ($dsn) {
    $mysql = new PDO($dsn, getenv('BIRTHMONTH_TEST_MYSQL_USER') ?: '', getenv('BIRTHMONTH_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $engines['MySQL'] = $mysql;
}
$engines['SQLite'] = SearchFixture::sqlite();
foreach ($engines as $name => $db) {
    $db->exec('DROP TABLE IF EXISTS bm_recall');
    $db->exec('CREATE TABLE bm_recall (id INTEGER PRIMARY KEY, birth_date TEXT)');
    $insert = $db->prepare('INSERT INTO bm_recall VALUES (?, ?)');
    $id = 1;
    $want = [];
    $ts = mktime(12, 0, 0, 1, 1, 1920);
    $end = mktime(12, 0, 0, 12, 31, 2026);
    for (; $ts <= $end; $ts += 86400) {
        $d = date('Y-m-d', $ts);
        $insert->execute([$id, $d]);
        [, $jm] = Jalali::gregorianToJalali((int)substr($d, 0, 4), (int)substr($d, 5, 2), (int)substr($d, 8, 2));
        $want[$jm][] = $id;
        $id++;
    }
    foreach (range(1, 12) as $jm) {
        [$predicate] = (new BirthMonth(['birth_month' => (string)$jm]))->predicate();
        $sql = str_replace('customers.birth_date', 'birth_date', $predicate);
        $got = array_map('intval', $db->query("SELECT id FROM bm_recall WHERE $sql")->fetchAll(PDO::FETCH_COLUMN));
        sort($got);
        $missing = array_diff($want[$jm], $got);
        same([], array_values($missing), "[$name] every true month-$jm birthday is found");
        // Unknown/zero dates are never returned.
        $db->exec("INSERT INTO bm_recall VALUES (900001, NULL), (900002, ''), (900003, '0000-00-00')");
        $got2 = array_map('intval', $db->query("SELECT id FROM bm_recall WHERE $sql")->fetchAll(PDO::FETCH_COLUMN));
        same(false, in_array(900001, $got2, true) || in_array(900002, $got2, true) || in_array(900003, $got2, true), "[$name] unknown birthdays excluded");
        $db->exec('DELETE FROM bm_recall WHERE id >= 900001');
    }
    $db->exec('DROP TABLE bm_recall');
}
echo "OK: {$assertions} birth-month assertions\n";
