<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Support\Jalali;

/** Read-only checks on a database AFTER restoring it to an isolated test database. */
final class RestoreCheckService {
    private const BATCH_SIZE = 500;
    private const SAMPLE_LIMIT = 5;

    /**
     * Read the application's own DDL, NOT an uploaded SQL dump. Nothing is executed.
     * Only column declarations from our controlled CREATE TABLE format are parsed.
     * @return array<string, array<string, string>>
     */
    public static function expectedSchema(): array {
        $sql = file_get_contents(base_path('database/migrations/001_create_schema.sql'));
        if ($sql === false) throw new \RuntimeException('Cannot read the application schema.');
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)\s*\((.*?)\)\s*ENGINE=/si', $sql, $tables, PREG_SET_ORDER);
        $schema = [];
        foreach ($tables as $table) {
            preg_match_all('/(?:^|,)\s*`?(\w+)`?\s+(BIGINT|INT|TINYINT|VARCHAR|TEXT|JSON|DATETIME|DATE|TIME|DECIMAL)\b/i', $table[2], $columns, PREG_SET_ORDER);
            foreach ($columns as $column) $schema[$table[1]][$column[1]] = strtolower($column[2]);
        }
        if (!$schema || count($schema) !== count($tables)) throw new \RuntimeException('Cannot parse the application schema.');
        return $schema;
    }

    /**
     * The optional SELECT callback is a test seam; production uses the configured DB.
     * No import, UPDATE, ALTER, migration, date repair, or writes of any kind occur.
     */
    public static function inspect(?callable $select = null): array {
        $select ??= [DB::class, 'select'];
        $database = $select('SELECT DATABASE() AS database_name', [])[0]['database_name'] ?? null;
        if (!$database) throw new \RuntimeException('No database selected.');
        $metadata = $select('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()', []);
        $actual = $primaryKeys = [];
        foreach ($metadata as $column) {
            $actual[$column['TABLE_NAME']][$column['COLUMN_NAME']] = strtolower($column['DATA_TYPE']);
            if ($column['COLUMN_KEY'] === 'PRI') $primaryKeys[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
        }

        $schema = self::expectedSchema();
        $report = ['database'=>$database, 'tables_expected'=>count($schema), 'date_values_checked'=>0, 'schema_issues'=>[], 'date_issues'=>[]];
        foreach ($schema as $table => $columns) {
            if (!isset($actual[$table])) {
                $report['schema_issues'][] = ['table'=>$table, 'column'=>null, 'reason'=>'missing_table'];
                continue;
            }
            $dateColumns = [];
            foreach ($columns as $column => $type) {
                if (!isset($actual[$table][$column])) {
                    $report['schema_issues'][] = ['table'=>$table, 'column'=>$column, 'reason'=>'missing_column'];
                    continue;
                }
                if (!in_array($type, ['date', 'datetime', 'time'], true)) continue;
                if ($actual[$table][$column] !== $type) {
                    $report['schema_issues'][] = ['table'=>$table, 'column'=>$column, 'reason'=>'date_type_mismatch', 'expected'=>$type, 'actual'=>$actual[$table][$column]];
                    continue;
                }
                if ($type !== 'time') $dateColumns[] = $column;
            }
            // Every current table has an id PK, except settings whose PK is `key`.
            $key = isset($columns['id']) ? 'id' : 'key';
            if (!$dateColumns || !isset($actual[$table][$key])) continue;
            // Pagination is complete only with the expected single-column PK.
            if (($primaryKeys[$table] ?? []) !== [$key]) {
                $report['schema_issues'][] = ['table'=>$table, 'column'=>$key, 'reason'=>'primary_key_mismatch'];
                continue;
            }
            $projection = ['`' . $key . '` AS record_id'];
            foreach ($dateColumns as $column) $projection[] = 'CAST(`' . $column . '` AS CHAR) AS `' . $column . '`';
            $lastId = null;
            do {
                $sql = 'SELECT ' . implode(', ', $projection) . ' FROM `' . $table . '`'
                    . ($lastId === null ? '' : ' WHERE `' . $key . '` > ?')
                    . ' ORDER BY `' . $key . '` LIMIT ' . self::BATCH_SIZE;
                $rows = $select($sql, $lastId === null ? [] : [$lastId]);
                foreach ($rows as $row) {
                    foreach ($dateColumns as $column) {
                        if ($row[$column] === null) continue;
                        $report['date_values_checked']++;
                        $reason = self::dateIssue((string)$row[$column]);
                        if ($reason === null) continue;
                        $issueKey = $table . '.' . $column . '.' . $reason;
                        $report['date_issues'][$issueKey] ??= ['table'=>$table, 'column'=>$column, 'reason'=>$reason, 'count'=>0, 'sample_ids'=>[]];
                        $issue = &$report['date_issues'][$issueKey];
                        $issue['count']++;
                        if (count($issue['sample_ids']) < self::SAMPLE_LIMIT) $issue['sample_ids'][] = (string)$row['record_id'];
                        unset($issue);
                    }
                    $lastId = $row['record_id'];
                }
            } while (count($rows) === self::BATCH_SIZE);
        }
        $report['date_issues'] = array_values($report['date_issues']);
        $report['ok'] = !$report['schema_issues'] && !$report['date_issues'];
        return $report;
    }

    private static function dateIssue(string $value): ?string {
        if (str_starts_with($value, '0000-00-00')) return 'zero_date';
        $jalali = Jalali::toJalali($value);
        if ($jalali === '') return 'invalid_date';
        // Old toGregorian accepted 1404-01-01 verbatim. This is suspicious, not
        // proof of a Jalali value: never guess or automatically rewrite history.
        if ((int)substr($value, 0, 4) <= Jalali::MAX_YEAR) return 'suspected_jalali_year';
        if (Jalali::toGregorian($jalali) !== substr($value, 0, 10)) return 'unsupported_date';
        return null;
    }

    public static function formatReport(array $report): string {
        $lines = [
            'بررسی فقط‌خواندنی دیتابیس: ' . $report['database'],
            'جدول‌های مورد انتظار: ' . Jalali::fa($report['tables_expected']),
            'مقدارهای تاریخ بررسی‌شده: ' . Jalali::fa($report['date_values_checked']),
        ];
        foreach ($report['schema_issues'] as $issue) {
            $field = $issue['table'] . ($issue['column'] === null ? '' : '.' . $issue['column']);
            $detail = match ($issue['reason']) {
                'missing_table' => 'جدول وجود ندارد.',
                'missing_column' => 'ستون وجود ندارد.',
                'primary_key_mismatch' => 'کلید اصلی تک‌ستونی مورد انتظار وجود ندارد؛ بررسی تاریخ‌های این جدول کامل نشد.',
                'date_type_mismatch' => 'نوع ستون: ' . $issue['actual'] . '؛ نوع مورد انتظار: ' . $issue['expected'],
            };
            $lines[] = '[ساختار] ' . $field . ': ' . $detail;
        }
        foreach ($report['date_issues'] as $issue) {
            $detail = match ($issue['reason']) {
                'zero_date' => 'تاریخ صفر قدیمی؛ نیازمند بررسی.',
                'invalid_date' => 'تاریخ نامعتبر یا فرمت پشتیبانی‌نشده.',
                'suspected_jalali_year' => 'سال مشکوک به جلالی در ستون میلادی؛ نیازمند بررسی دستی، نه تبدیل خودکار.',
                'unsupported_date' => 'تاریخ خارج از بازهٔ قابل ورود در تقویم.',
            };
            // Do not expose customer names, text, birth dates, credentials or hashes.
            $lines[] = '[تاریخ] ' . $issue['table'] . '.' . $issue['column'] . ': ' . $detail
                . ' تعداد: ' . Jalali::fa($issue['count']) . '؛ شناسه‌های نمونه: ' . implode(', ', $issue['sample_ids']);
        }
        $lines[] = $report['ok']
            ? 'در ساختار و تاریخ‌های بررسی‌شده مورد ناسازگار پیدا نشد.'
            : 'پیش از جایگزینی دیتابیس اصلی، موارد بالا را بررسی کنید.';
        $lines[] = 'هیچ داده‌ای تغییر نکرد. این بررسی، کامل بودن بکاپ یا صحت مبالغ و روابط را تضمین نمی‌کند.';
        return implode("\n", $lines) . "\n";
    }
}
