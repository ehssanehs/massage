<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/fixtures/SearchFixture.php';

use App\Support\SearchQuery;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

foreach ([
    'ي ك ى'=>'یکی', 'عَـلِي'=>'علی', 'محمد‌رضا'=>'محمدرضا', 'محمد رضا'=>'محمدرضا',
    "سارا\u{00a0}سادات"=>'ساراسادات', "\u{200f}رضايي\u{200e}"=>'رضایی', '۰۹۱۲٣٤٥٦٧٨٩'=>'09123456789',
    'أإآٱ'=>'اااا', 'مؤمنی'=>'مومنی', 'مسئول'=>'مسیول', 'SARA O\'CONNOR'=>"sarao'connor",
] as $input => $expected) same($expected, SearchQuery::normalize($input), 'Comparison normalization');
same(['محمدرضا', 'نیک', 'فر'], SearchQuery::fromInput("  محمد‌رضا\tنيك   فر  ")->terms, 'Unicode/ordinary whitespace and half spaces');
same(['علی'], SearchQuery::fromInput('علی علي عَلِی')->terms, 'Duplicate normalized words are not repeated');
same(['0'], SearchQuery::fromInput('0')->terms, 'Zero is not a false/empty query');
same(['0'], SearchQuery::fromInput('۰')->terms, 'Persian zero');
foreach ([null, '', '  ', "\u{00a0}\t", "\u{200c}\u{feff}"] as $empty) {
    same([], SearchQuery::fromInput($empty)->terms, 'Blank/format-only search');
    same(['', []], SearchQuery::fromInput($empty)->predicate(['name']), 'No search predicate for blank input');
}
foreach ([[], ['علی'], 123, "\xff", "one\x1ftwo", "bad\0value", str_repeat('الف', 101), implode(' ', range(1, 13))] as $invalid) {
    $query = SearchQuery::fromInput($invalid);
    same(true, $query->error !== null, 'Invalid query has an actionable error');
    same(['1=0', []], $query->predicate(['name']), 'Malformed queries fail closed');
}
same(null, SearchQuery::fromInput(str_repeat('ا', SearchQuery::MAX_LENGTH))->error, 'Maximum query length is allowed');
same(null, SearchQuery::fromInput(implode(' ', range(1, 12)))->error, 'Maximum word count is allowed');
[$sql, $params] = SearchQuery::fromInput("A_%! O'Connor\\")->predicate(['name']);
same(['%a!_!%!!%', "%o'connor\\%"], $params, 'Wildcards escaped; quotes/backslashes are bound literally');
same(false, str_contains($sql, "O'Connor"), 'No user input interpolated into SQL');
same(true, str_contains($sql, "ESCAPE '!'"), 'Explicit LIKE escape is independent of SQL backslash mode');
same(['1=0', []], SearchQuery::fromInput('علی')->predicate([]), 'Unconfigured search cannot silently return every row');

// Optional real-engine run: explicit, separate TEST credentials only. No app DB fallback.
$dsn = getenv('SEARCH_TEST_MYSQL_DSN');
if ($dsn) {
    if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('SEARCH_TEST_MYSQL_DSN must be a MySQL test DSN.');
    $db = new PDO($dsn, getenv('SEARCH_TEST_MYSQL_USER') ?: '', getenv('SEARCH_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    SearchFixture::install($db); // TEMPORARY tables, never INSERT/UPDATE existing application tables.
    $engine = 'MySQL/MariaDB temporary fixtures';
} else {
    $db = SearchFixture::sqlite();
    $engine = 'SQLite in-memory SQL with MySQL function adapters (not a MySQL server)';
}
$fields = ["CONCAT_WS(' ', customers.first_name, customers.last_name)", 'customers.mobile', 'customers.customer_code', 'customers.occupation'];
function lookup(PDO $db, mixed $value, array $fields): array {
    [$predicate, $params] = SearchQuery::fromInput($value)->predicate($fields);
    $statement = $db->prepare('SELECT id FROM customers WHERE deleted_at IS NULL' . ($predicate === '' ? '' : ' AND ' . $predicate) . ' ORDER BY id');
    $statement->execute($params);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

// Reproduce the original bug using existing, real rows before asserting the fix.
$old = $db->prepare('SELECT id FROM customers WHERE deleted_at IS NULL AND (first_name LIKE ? OR last_name LIKE ?)');
$old->execute(['%محمد رضا نیک فر%', '%محمد رضا نیک فر%']);
same([], $old->fetchAll(PDO::FETCH_COLUMN), 'Old per-column whole-name search misses this existing customer');
$original = $db->query('SELECT first_name, last_name FROM customers WHERE id=1')->fetch();
foreach ([
    ['علی کاظمی', [1]], ['كاظمي علي', [1]], ['کاظمی علی', [1]], ['علیکاظمی', [1]],
    ['کاظ', [1]], ['محمدرضا نیکفر', [2, 3]], ['محمد رضا نیک فر', [2, 3]], ['نیک‌فر محمد‌رضا', [2, 3]],
    ['علی رضایی', [4]], ['عَلي رَضايي', [4]], ['ساراسادات مهدوی', [5]], ["\u{2067}مهدوی\u{2069} سارا", [5]],
    ["sara o'connor", [6]], ['شریفی', [7]], ['C_08%!', [8]], ['%', [8]], ['_', [8]], ['!', [8]], ['path\\leaf', [8]],
    ['۰۹۱۲۱۲۳۴۵۶۷', [1]], ['٠٩٣٥١٢٣٤٥٦٧', [9]], ['09351234567', [9]], ['c-001', [1]],
    ['اسم ناموجود', []], ["%' OR 1=1 --", []], ['نامEND', []], ['ENDSTART', []],
] as [$query, $expected]) same($expected, lookup($db, $query, $fields), 'SQL search: ' . $query);
$all = lookup($db, '', $fields);
same(37, count($all), 'All undeleted rows across all pages');
same(false, in_array(99, $all, true), 'Soft-deleted customer stays hidden');
$zeros = lookup($db, '0', $fields);
same(true, in_array(0, $zeros, true), 'Zero search is applied');
same(false, in_array(6, $zeros, true), 'Zero does not fall back to an unfiltered list');
same(25, count(lookup($db, 'آزمون صفحه', $fields)), 'Pagination fixture match count');
same($original, $db->query('SELECT first_name, last_name FROM customers WHERE id=1')->fetch(), 'Searching never rewrites legacy spellings');

if ($dsn) {
    $mode = $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $db->prepare('SET SESSION sql_mode=?')->execute([$mode . ',NO_BACKSLASH_ESCAPES']);
    same([8], lookup($db, 'C_08%!', $fields), 'LIKE wildcards with NO_BACKSLASH_ESCAPES');
    same([8], lookup($db, 'path\\leaf', $fields), 'Literal backslash with NO_BACKSLASH_ESCAPES');
}
echo "OK: {$assertions} search assertions; {$engine}\n";
