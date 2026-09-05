<?php
declare(strict_types=1);

// CLI-only front-controller harness. Replaces only the DB boundary, never a live database.
namespace App\Core {
    final class DB {
        public static array $queries = [];
        public static array $writes = [];
        public static array $fixtures = [];
        public static bool $overlap = false;
        public static bool $searchFixture = false;
        private static ?\PDO $listDatabase = null;

        private static function listDatabase(): \PDO {
            return self::$listDatabase ??= \SearchFixture::sqlite();
        }

        private static function record(string $sql, array $params): void {
            self::$queries[] = ['sql' => $sql, 'params' => $params];
        }

        public static function select(string $sql, array $params = []): array {
            self::record($sql, $params);
            if (self::$searchFixture && preg_match('/^SELECT (\w+)\.\*.*?\bFROM \1\b/s', $sql)) {
                $statement = self::listDatabase()->prepare($sql);
                $statement->execute($params);
                return $statement->fetchAll();
            }
            if (str_contains($sql, 'AND (start_time < ?')) return self::$overlap ? [['id'=>99]] : [];
            if (str_contains($sql, 'SELECT COUNT(*) visits')) return [['visits'=>1, 'first_visit'=>'2026-03-21', 'last_visit'=>'2026-03-21', 'total'=>1000, 'avg_spend'=>1000, 'avg_score'=>5]];
            if (str_contains($sql, 'SELECT COUNT(*) sessions')) return [['sessions'=>1, 'revenue'=>1000, 'discounts'=>0]];
            if (str_contains($sql, 'SELECT massage_date d')) return [['d'=>'2026-03-21', 'v'=>1000]];
            if (str_contains($sql, 'SELECT s.name, SUM')) return [['name'=>'ماساژ', 'revenue'=>1000]];
            if (str_contains($sql, 'SELECT ms.massage_date,')) return [['massage_date'=>'2026-03-21', 'c'=>'مشتری, "آزمایشی"', 's'=>'ماساژ', 't'=>'درمانگر', 'final_amount'=>1000]];
            if (!preg_match('/\bFROM\s+([a-z_]+)/i', $sql, $m)) throw new \RuntimeException('Unrecognized test query: ' . $sql);
            // Customer lists have correlated session subqueries before the outer FROM.
            if (str_starts_with($sql, 'SELECT customers.*')) $m[1] = 'customers';
            $row = ['id'=>1, 'label'=>'آزمایشی', 'name'=>'آزمایشی', 'status'=>'active', 'created_at'=>'2026-03-21 13:05:09'];
            $extra = match ($m[1]) {
                'users' => ['role_slug'=>'super_admin', 'role_name'=>'مدیر', 'role_id'=>1, 'permissions'=>'[]', 'email'=>'test@example.invalid'],
                'customers' => ['first_name'=>'مشتری', 'last_name'=>'آزمایشی', 'mobile'=>'09120000000', 'customer_code'=>'C260727101', 'birth_date'=>'2026-03-21', 'registration_date'=>'2026-03-21', 'last_visit'=>'2026-03-21', 'visits'=>1, 'monetary'=>1000, 'segment'=>'active'],
                'therapists' => ['hire_date'=>'2026-03-21', 'base_salary'=>100, 'salary_model'=>'base_plus_percentage', 'commission_percentage'=>20, 'fixed_commission'=>0],
                'services' => ['default_price'=>1000, 'duration_minutes'=>60, 'revenue'=>1000],
                'appointments' => ['customer_id'=>1, 'therapist_id'=>1, 'service_id'=>1, 'appointment_date'=>'2026-03-21', 'start_time'=>'10:00:00', 'end_time'=>'11:00:00', 'd'=>'2026-03-21', 's'=>'10:00:00'],
                'massage_sessions' => ['customer_id'=>1, 'therapist_id'=>1, 'service_id'=>1, 'massage_date'=>'2026-03-21', 'recommended_next_visit_date'=>'2026-03-21', 'final_amount'=>1000, 'status'=>'completed'],
                'customer_packages' => ['customer_id'=>1, 'title'=>'پکیج', 'starts_at'=>'2026-03-21', 'expires_at'=>'2026-03-21'],
                'campaigns' => ['starts_at'=>'2026-03-21', 'ends_at'=>'2026-03-21'],
                'expenses' => ['title'=>'هزینه', 'expense_date'=>'2026-03-21', 'amount'=>100],
                'followups' => ['customer_id'=>1, 'due_date'=>'2026-03-21', 'status'=>'contacted', 'customer_name'=>'مشتری', 'customer_status'=>'active', 'mobile'=>'09120000000', 'description'=>'مراجعه در 2026-03-21', 'result'=>'تماس در 2026-03-21', 'contacted_at'=>'2026-03-21 13:05:09'],
                'customer_timeline' => ['customer_id'=>1, 'type'=>'followup_created', 'title'=>'پیگیری', 'body'=>'تاریخ پیگیری: 2026-03-21 <script>alert(1)</script>'],
                'audit_logs' => ['user'=>'مدیر', 'action'=>'create', 'entity'=>'customers', 'entity_id'=>1, 'ip_address'=>'192.0.2.1'],
                'inventory_items' => [],
                default => throw new \RuntimeException('No fixture for table ' . $m[1]),
            };
            if ($m[1] === 'inventory_items') return [];
            if ($m[1] === 'massage_sessions') {
                foreach (self::$writes as $write) if ($write['table'] === 'massage_sessions') $extra = array_replace($extra, $write['data']);
            }
            return [array_replace($row, $extra, self::$fixtures[$m[1]] ?? [])];
        }

        public static function row(string $sql, array $params = []): ?array {
            return self::select($sql, $params)[0] ?? null;
        }

        public static function value(string $sql, array $params = []): mixed {
            self::record($sql, $params);
            if (self::$searchFixture && str_starts_with($sql, 'SELECT COUNT(*) FROM ')) {
                $statement = self::listDatabase()->prepare($sql);
                $statement->execute($params);
                return $statement->fetchColumn();
            }
            if (str_contains($sql, 'FROM settings')) return match ($params[0] ?? '') {
                'brand_name'=>'سامانه آزمایشی', 'primary_color'=>'#7c3aed', 'secondary_color'=>'#14b8a6', 'default_theme'=>'light', default=>null,
            };
            if (str_contains($sql, 'followup_interval_days')) return 30;
            if (str_contains($sql, 'SUM(amount)')) return 100;
            return 1;
        }

        public static function insert(string $table, array $data): int {
            self::$writes[] = ['operation'=>'insert', 'table'=>$table, 'data'=>$data];
            return 1;
        }

        public static function update(string $table, array $data, string $where, array $params = []): int {
            self::$writes[] = ['operation'=>'update', 'table'=>$table, 'data'=>$data];
            return 1;
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $input = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
    \App\Core\DB::$fixtures = $input['fixtures'] ?? [];
    \App\Core\DB::$overlap = $input['overlap'] ?? false;
    $_GET = $input['get'] ?? ['r'=>'customers.show', 'id'=>1];
    $_POST = $input['post'] ?? [];
    $_SERVER['REQUEST_METHOD'] = isset($input['post']) ? 'POST' : 'GET';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    session_save_path(sys_get_temp_dir());
    session_start();
    $_SESSION = ['uid'=>1, '_csrf'=>'test-only-csrf-token'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') $_POST['_csrf'] = $_SESSION['_csrf'];
    ob_start();
    register_shutdown_function(static function (): void {
        $html = ob_get_clean();
        $result = ['body'=>$html, 'status'=>http_response_code() ?: 200, 'queries'=>\App\Core\DB::$queries, 'writes'=>\App\Core\DB::$writes, 'error'=>error_get_last()];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    });
    if ($input['search_fixture'] ?? false) {
        require __DIR__ . '/fixtures/SearchFixture.php';
        \App\Core\DB::$searchFixture = true;
    }
    require __DIR__ . '/../public/index.php';
}
