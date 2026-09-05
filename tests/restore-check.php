<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\RestoreCheckService;
use App\Support\Jalali;
use App\Support\View;

$assertions = 0;
function expectSame(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

$schema = RestoreCheckService::expectedSchema();
expectSame(18, count($schema), 'All application tables, not only configurable modules, are checked');
expectSame(248, array_sum(array_map('count', $schema)), 'DDL parser includes same-line and backticked columns');
$dateCount = 0;
foreach ($schema as $columns) $dateCount += count(array_filter($columns, fn($type) => in_array($type, ['date', 'datetime'], true)));
expectSame(61, $dateCount, 'All DATE/DATETIME fields from the pre-change schema are covered');
$modules = require base_path('config/modules.php');
foreach ($modules as $module) {
    foreach ($module['fields'] as $column => $definition) {
        expectSame(true, isset($schema[$module['table']][$column]), 'Schema parser includes every CRUD field');
    }
}
expectSame('tinyint', $schema['massage_sessions']['complaint_flag'], 'Non-date TINYINT columns are also checked');
expectSame('varchar', $schema['settings']['key'], 'Backticked settings PK');
expectSame('datetime', $schema['customers']['deleted_at'], 'Third timestamp declared on the same line');

$metadata = $records = [];
foreach ($schema as $table => $columns) {
    $row = ['record_id' => $table === 'settings' ? 'brand_name' : '1'];
    foreach ($columns as $column => $type) {
        // MariaDB exposes JSON as longtext; this must not cause a date compatibility error.
        $metadata[] = ['TABLE_NAME'=>$table, 'COLUMN_NAME'=>$column, 'DATA_TYPE'=>$type === 'json' ? 'longtext' : $type, 'COLUMN_KEY'=>($column === 'id' || ($table === 'settings' && $column === 'key')) ? 'PRI' : ''];
        if ($type === 'date') $row[$column] = '2025-03-20'; // Leap Esfand, stored as Gregorian.
        if ($type === 'datetime') $row[$column] = '2026-09-05 00:05:09';
        if (in_array($column, ['deleted_at', 'updated_at'], true)) $row[$column] = null;
    }
    $records[$table] = [$row];
}
$records['customers'][0]['birth_date'] = '1992-05-10';
$records['therapists'][0]['hire_date'] = '1988-09-02';
$records['customer_timeline'][0]['body'] = 'تاریخ پیگیری: 2025-03-20';

/** Test SELECT boundary only; this is NOT a simulated SQL import or a real MySQL test. */
function selector(array $metadata, array $records, array &$queries): Closure {
    return static function (string $sql, array $params = []) use ($metadata, $records, &$queries): array {
        if (!str_starts_with($sql, 'SELECT ') || preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', $sql)) {
            throw new RuntimeException('The restore checker attempted a non-SELECT statement: ' . $sql);
        }
        $queries[] = ['sql'=>$sql, 'params'=>$params];
        if ($sql === 'SELECT DATABASE() AS database_name') return [['database_name'=>'isolated_restore_test']];
        if (str_contains($sql, 'information_schema.COLUMNS')) return $metadata;
        if (!preg_match('/ FROM `(\w+)`/', $sql, $m)) throw new RuntimeException('Unknown SELECT: ' . $sql);
        if (!str_contains($sql, 'ORDER BY') || !str_contains($sql, 'LIMIT 500')) throw new RuntimeException('Data scans must be bounded and ordered.');
        $rows = $records[$m[1]] ?? [];
        if ($params) $rows = array_values(array_filter($rows, fn($r) => $r['record_id'] > $params[0]));
        usort($rows, fn($a, $b) => $a['record_id'] <=> $b['record_id']);
        return array_slice($rows, 0, 500);
    };
}

$queries = [];
$before = serialize($records);
$report = RestoreCheckService::inspect(selector($metadata, $records, $queries));
expectSame(true, $report['ok'], 'Standard legacy Gregorian storage is compatible');
expectSame([], $report['schema_issues'], 'No schema migration required for the old schema');
expectSame([], $report['date_issues'], 'Valid Gregorian dates and nullable values are accepted');
expectSame($before, serialize($records), 'Checking restored data never rewrites it');
expectSame(20, count($queries), 'Metadata queries plus one bounded SELECT per table');
expectSame('۱۳۷۱/۰۲/۲۰', Jalali::toJalali($records['customers'][0]['birth_date']), 'Old birth date displays as Jalali');
expectSame('تاریخ پیگیری: ۱۴۰۳/۱۲/۳۰', Jalali::datesInText($records['customer_timeline'][0]['body']), 'Legacy CRM body displays correctly');
expectSame('2025-03-20', Jalali::toGregorian('۱۴۰۳/۱۲/۳۰'), 'Legacy leap date survives editing');
expectSame('۱۴۰۵/۰۶/۱۴ ۰۰:۰۵:۰۹', Jalali::dateTime($records['customer_timeline'][0]['created_at'], true), 'Restored timestamp retains date/time');

// Every editable DATE in the legacy data can be rendered and saved without drift.
foreach ($schema as $table => $columns) {
    foreach ($columns as $column => $type) {
        if ($type !== 'date') continue;
        $date = $records[$table][0][$column];
        $display = Jalali::toJalali($date);
        $html = View::dateInput($column, $display);
        expectSame(true, str_contains($html, 'value="' . $display . '"'), 'Restored DATE input is Jalali');
        expectSame($date, Jalali::toGregorian($display), 'Restored DATE round-trip does not change stored data');
    }
}

$oldMetadata = array_values(array_filter($metadata, fn($c) => $c['TABLE_NAME'] !== 'followups'
    && !($c['TABLE_NAME'] === 'customers' && in_array($c['COLUMN_NAME'], ['mobile', 'birth_date'], true))));
$queries = [];
$missing = RestoreCheckService::inspect(selector($oldMetadata, $records, $queries));
expectSame(false, $missing['ok'], 'Older/incomplete schema is not declared compatible');
expectSame(3, count($missing['schema_issues']), 'Missing non-date columns are checked as well');
expectSame('missing_table', $missing['schema_issues'][2]['reason'], 'Missing table explicitly identified');
expectSame(false, (bool)array_filter($queries, fn($q) => str_contains($q['sql'], 'FROM `followups`')), 'Never query a missing table');

$missingKey = $metadata;
foreach ($missingKey as &$column) {
    if ($column['TABLE_NAME'] === 'customers') $column['COLUMN_KEY'] = '';
}
unset($column);
$queries = [];
$keyReport = RestoreCheckService::inspect(selector($missingKey, $records, $queries));
expectSame(false, $keyReport['ok'], 'An unkeyed table cannot be scanned safely/completely');
expectSame('primary_key_mismatch', $keyReport['schema_issues'][0]['reason'], 'Missing PK detected');
expectSame(false, (bool)array_filter($queries, fn($q) => str_contains($q['sql'], 'FROM `customers`')), 'Skip unsafe pagination instead of looping/skipping rows');

$wrongTypes = $metadata;
foreach ($wrongTypes as &$column) {
    if ($column['TABLE_NAME'] === 'appointments' && in_array($column['COLUMN_NAME'], ['appointment_date', 'start_time'], true)) $column['DATA_TYPE'] = 'varchar';
}
unset($column);
$queries = [];
$types = RestoreCheckService::inspect(selector($wrongTypes, $records, $queries));
expectSame(false, $types['ok'], 'Unexpected temporal storage types are reported, not coerced');
expectSame(2, count($types['schema_issues']), 'DATE and TIME column types checked');
expectSame('date_type_mismatch', $types['schema_issues'][0]['reason'], 'Type mismatch reason');

$dirty = $records;
$dirty['customers'] = [];
foreach (['1404-01-01', '0000-00-00', '2026-02-31', '2500-01-01', null] as $id => $date) {
    $row = $records['customers'][0];
    $row['record_id'] = (string)($id + 1);
    $row['birth_date'] = $date;
    $dirty['customers'][] = $row;
}
$dirty['customer_timeline'][0]['created_at'] = '0000-00-00 00:00:00';
$queries = [];
$before = serialize($dirty);
$issues = RestoreCheckService::inspect(selector($metadata, $dirty, $queries));
expectSame(false, $issues['ok'], 'Dirty legacy data needs review');
expectSame(5, count($issues['date_issues']), 'Detect old hyphen bug, zero date/time, invalid and uneditable dates; allow NULL');
expectSame(['suspected_jalali_year', 'zero_date', 'invalid_date', 'unsupported_date', 'zero_date'], array_column($issues['date_issues'], 'reason'), 'Specific reasons are actionable');
expectSame($before, serialize($dirty), 'Suspicious legacy years are NEVER automatically converted');
$summary = RestoreCheckService::formatReport($issues);
expectSame(true, str_contains($summary, 'customers.birth_date'), 'Report identifies fields');
expectSame(false, str_contains($summary, '1404-01-01'), 'Report does not disclose raw birth dates');
expectSame(true, str_contains($summary, 'هیچ داده‌ای تغییر نکرد'), 'Read-only guarantee is clear');

$many = $records;
$many['customers'] = [];
for ($id = 0; $id <= 500; $id++) {
    $row = $records['customers'][0];
    $row['record_id'] = (string)$id;
    $row['birth_date'] = '1404-01-01';
    $many['customers'][] = $row;
}
$queries = [];
$batched = RestoreCheckService::inspect(selector($metadata, $many, $queries));
expectSame(501, $batched['date_issues'][0]['count'], 'Large tables are checked across batches, including id zero');
expectSame(['0', '1', '2', '3', '4'], $batched['date_issues'][0]['sample_ids'], 'Bound sample IDs to avoid enormous reports');
$customerQueries = array_values(array_filter($queries, fn($q) => str_contains($q['sql'], 'FROM `customers`')));
expectSame(2, count($customerQueries), 'Keyset pagination queries');
expectSame(['499'], $customerQueries[1]['params'], 'Continuation is parameterized');

$failed = false;
try {
    RestoreCheckService::inspect(static fn() => throw new RuntimeException('Simulated DB connection/read error'));
} catch (RuntimeException $e) {
    $failed = true;
}
expectSame(true, $failed, 'Connection/query errors are not reported as a clean backup');
echo "OK: $assertions restore-compatibility assertions (read-only SELECT fixtures, not a live SQL restore)\n";
