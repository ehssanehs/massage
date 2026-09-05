<?php
declare(strict_types=1);

use App\Services\RestoreCheckService;

/** Synthetic records only. SQLite is in-memory; MySQL fixtures are TEMPORARY tables. */
final class SearchFixture {
    public static function sqlite(): PDO {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        // Test actual SQL predicates, not a stub that returns a row for every LIKE.
        $db->sqliteCreateFunction('CONCAT_WS', static fn($separator, ...$values) => $separator === null ? null : implode($separator, array_filter($values, static fn($value) => $value !== null)), -1);
        $db->sqliteCreateFunction('REGEXP_REPLACE', static fn($value, $pattern, $replacement) => $value === null ? null : preg_replace('~' . str_replace('~', '\\~', $pattern) . '~u', $replacement, $value), 3);
        $db->sqliteCreateFunction('LOWER', static fn($value) => $value === null ? null : mb_strtolower($value, 'UTF-8'), 1);
        $db->exec('PRAGMA case_sensitive_like=ON');
        self::install($db);
        return $db;
    }

    public static function install(PDO $db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (RestoreCheckService::expectedSchema() as $table => $columns) {
            $ddl = [];
            foreach ($columns as $name => $type) {
                $ddl[] = '`' . $name . '` ' . ($name === 'id' ? ($mysql ? 'BIGINT' : 'INTEGER') . ' PRIMARY KEY' : 'TEXT NULL');
            }
            $db->exec('CREATE ' . ($mysql ? 'TEMPORARY ' : '') . "TABLE `$table` (" . implode(', ', $ddl) . ')' . ($mysql ? ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : ''));
        }
        foreach (self::records() as $table => $rows) {
            foreach ($rows as $row) {
                $sql = "INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
                $db->prepare($sql)->execute(array_values($row));
            }
        }
    }

    public static function records(): array {
        $customer = ['status'=>'active', 'registration_date'=>'2026-03-21', 'created_at'=>'2026-03-21 10:00:00', 'deleted_at'=>null];
        $customers = [
            ['id'=>0, 'first_name'=>'صفر', 'last_name'=>'آزمایشی', 'mobile'=>'0', 'customer_code'=>'ZERO'],
            ['id'=>1, 'first_name'=>'علي', 'last_name'=>'كاظمي', 'mobile'=>'09121234567', 'customer_code'=>'C-001'],
            ['id'=>2, 'first_name'=>'محمد رضا', 'last_name'=>'نیک فر', 'mobile'=>'09120000002', 'customer_code'=>'C-002'],
            ['id'=>3, 'first_name'=>'محمدرضا', 'last_name'=>'نيك‌فر', 'mobile'=>'09120000003', 'customer_code'=>'C-003'],
            ['id'=>4, 'first_name'=>'عَــلِی', 'last_name'=>'رَضَایِی', 'mobile'=>'09120000004', 'customer_code'=>'C-004'],
            ['id'=>5, 'first_name'=>"سارا\u{00a0}سادات", 'last_name'=>"مهدوی\u{200f}", 'mobile'=>'09120000005', 'customer_code'=>'C-005'],
            ['id'=>6, 'first_name'=>'Sara', 'last_name'=>"O'Connor", 'mobile'=>'NO-NUMBER', 'customer_code'=>'CASE-SENSITIVE'],
            ['id'=>7, 'first_name'=>null, 'last_name'=>'شریفی', 'mobile'=>null, 'customer_code'=>'NULL-NAME'],
            ['id'=>8, 'first_name'=>'نشانه', 'last_name'=>'آزمایشی', 'mobile'=>'09120000008', 'customer_code'=>'C_08%!', 'occupation'=>'path\\leaf'],
            ['id'=>9, 'first_name'=>'مهسا', 'last_name'=>'کریمی', 'mobile'=>'۰۹۳۵۱۲۳۴۵۶۷', 'customer_code'=>'C-009'],
            ['id'=>10, 'first_name'=>'مرز', 'last_name'=>'نام', 'mobile'=>'END', 'customer_code'=>'START'],
            ['id'=>11, 'first_name'=>'همسایه', 'last_name'=>'آزمایشی', 'mobile'=>'NOT-A-NUMBER', 'customer_code'=>'C908ZZ'],
            ['id'=>99, 'first_name'=>'علی', 'last_name'=>'کاظمی', 'mobile'=>'09121234567', 'customer_code'=>'DELETED', 'deleted_at'=>'2026-04-01 00:00:00'],
        ];
        // A full second page of matches is needed to exercise the count/offset path.
        for ($i = 1; $i <= 25; $i++) $customers[] = ['id'=>100+$i, 'first_name'=>'آزمون', 'last_name'=>'صفحه ' . $i, 'mobile'=>'0900000' . $i, 'customer_code'=>'PAGE-' . $i];
        $birthDates = [0=>'1985-01-01', 1=>'1990-03-21', 2=>'1990-03-22', 3=>'1990-03-23', 4=>'1990-03-20',
            5=>null, 6=>'0000-00-00', 7=>'', 8=>'2025-03-20', 9=>'2025-03-21', 10=>'2025-03-19', 11=>'1990-03-24', 99=>'1990-03-22'];
        $customers = array_map(static fn($row) => array_replace($customer, $row, [
            'birth_date'=>$row['id'] >= 101 ? '1990-03-21' : $birthDates[$row['id']],
        ]), $customers);
        $appointment = ['therapist_id'=>1, 'service_id'=>1, 'appointment_date'=>'2026-03-21', 'start_time'=>'10:00:00', 'end_time'=>'11:00:00', 'status'=>'pending', 'deleted_at'=>null];
        $session = ['therapist_id'=>1, 'service_id'=>1, 'massage_date'=>'2026-03-21', 'final_amount'=>1000, 'status'=>'completed', 'deleted_at'=>null];
        return [
            'customers'=>$customers,
            'therapists'=>[['id'=>1, 'name'=>'آرش كريمي', 'code'=>'T_01', 'phone'=>'09130000001', 'status'=>'active']],
            'services'=>[
                ['id'=>1, 'name'=>'ماساژ سوئدی', 'description'=>'آرامش بدن', 'default_price'=>1000, 'duration_minutes'=>60, 'status'=>'active'],
                ['id'=>2, 'name'=>'Deep\\Tissue 100%_Firm!', 'description'=>null, 'default_price'=>1500, 'duration_minutes'=>60, 'status'=>'active'],
            ],
            'appointments'=>[
                array_replace($appointment, ['id'=>1, 'customer_id'=>1]),
                array_replace($appointment, ['id'=>2, 'customer_id'=>9, 'service_id'=>2]),
                array_replace($appointment, ['id'=>3, 'customer_id'=>500, 'therapist_id'=>500, 'service_id'=>500, 'notes'=>'رزرو بدون رابطه']),
                array_replace($appointment, ['id'=>4, 'customer_id'=>1, 'deleted_at'=>'2026-04-01 00:00:00']),
            ],
            'massage_sessions'=>[
                array_replace($session, ['id'=>11, 'customer_id'=>1]),
                array_replace($session, ['id'=>12, 'customer_id'=>9, 'service_id'=>2]),
                array_replace($session, ['id'=>13, 'customer_id'=>1, 'deleted_at'=>'2026-04-01 00:00:00']),
            ],
            'customer_packages'=>[
                ['id'=>21, 'customer_id'=>1, 'title'=>'پکیج آرامش', 'total_sessions'=>10, 'used_sessions'=>1, 'expires_at'=>'2026-12-01', 'payment_status'=>'paid'],
                ['id'=>22, 'customer_id'=>9, 'title'=>'پکیج تابستان', 'total_sessions'=>5, 'used_sessions'=>1, 'expires_at'=>'2026-12-01', 'payment_status'=>'paid'],
            ],
            'inventory_items'=>[['id'=>31, 'name'=>'روغن كنجد', 'category'=>'ماساژ', 'status'=>'active', 'quantity'=>10]],
        ];
    }
}
