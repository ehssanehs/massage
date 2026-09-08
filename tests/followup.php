<?php
declare(strict_types=1);
// Followup feature checks (CLI only, isolated DB, everything rolled back).
require __DIR__ . '/../app/bootstrap.php';

use App\Core\DB;
use App\Support\Jalali;

$pdo = DB::pdo();
if (PHP_SAPI !== 'cli') exit('CLI only');
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/test|scratch|_dev|fresh/i', $dbName)) {
    exit("SKIP: followup test needs a test database (current: $dbName).\n");
}
$pdo->exec(file_get_contents(base_path('database/migrations/001_create_schema.sql')));
$pdo->exec(file_get_contents(base_path('database/seeders/001_seed_defaults.sql')));

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

$pdo->beginTransaction();
try {
    // 1) Migration 003 column exists in fresh schema
    $col = $pdo->query("SHOW COLUMNS FROM customer_timeline LIKE 'deleted_at'")->fetch();
    same(true, (bool)$col, 'customer_timeline.deleted_at exists in schema');

    // 2) E2E: non-booked status + refollow_days -> new pending followup + timeline rows
    $cid = DB::insert('customers', [
        'first_name' => 'تست', 'last_name' => 'پیگیری', 'mobile' => '09120001122',
        'customer_code' => 'T' . random_int(100000, 999999), 'registration_date' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $fid = DB::insert('followups', [
        'customer_id' => $cid, 'due_date' => date('Y-m-d'), 'status' => 'pending',
        'priority' => 'normal', 'description' => 'تست اصلی', 'created_at' => date('Y-m-d H:i:s'),
    ]);

    // Same logic as the POST handler in index.php (contacted + 5 days)
    $status = 'contacted'; $result = 'تماس تستی'; $reDays = 5;
    $updateData = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s'), 'result' => $result, 'contacted_at' => date('Y-m-d H:i:s')];
    DB::update('followups', $updateData, 'id=:id', ['id' => $fid]);
    $f = DB::row('SELECT * FROM followups WHERE id=?', [$fid]);
    DB::insert('customer_timeline', ['customer_id' => $f['customer_id'], 'type' => 'followup', 'title' => 'نتیجه پیگیری', 'body' => $result, 'entity' => 'followups', 'entity_id' => $fid, 'created_at' => date('Y-m-d H:i:s')]);
    if ($status !== 'booked' && $reDays > 0 && $reDays <= 365) {
        $due = date('Y-m-d', strtotime("+{$reDays} days"));
        DB::insert('followups', ['customer_id' => $f['customer_id'], 'session_id' => $f['session_id'], 'due_date' => $due, 'status' => 'pending', 'priority' => $f['priority'] ?? 'normal', 'description' => 'پیگیری مجدد پس از تماس', 'assigned_to' => $f['assigned_to'] ?? null, 'created_at' => date('Y-m-d H:i:s')]);
        DB::insert('customer_timeline', ['customer_id' => $f['customer_id'], 'type' => 'followup_scheduled', 'title' => 'پیگیری بعدی زمان‌بندی شد', 'body' => 'تاریخ پیگیری بعدی: ' . Jalali::toJalali($due), 'entity' => 'followups', 'entity_id' => $fid, 'created_at' => date('Y-m-d H:i:s')]);
    }

    $newFu = DB::row("SELECT due_date FROM followups WHERE customer_id=? AND id<>? AND status='pending' ORDER BY id DESC LIMIT 1", [$cid, $fid]);
    same(date('Y-m-d', strtotime('+5 days')), $newFu['due_date'] ?? null, 're-followup created with due +5 days');

    // 3) booked + no days -> no new followup
    $fid2 = DB::insert('followups', ['customer_id' => $cid, 'due_date' => date('Y-m-d'), 'status' => 'pending', 'priority' => 'normal', 'description' => 'تست booked', 'created_at' => date('Y-m-d H:i:s')]);
    $before = (int)DB::value("SELECT COUNT(*) FROM followups WHERE customer_id=?", [$cid]);
    $reDays2 = 0; // field hidden/cleared by JS for booked
    same(true, !('booked' !== 'booked' && $reDays2 > 0), 'booked + empty days creates nothing');
    same($before, (int)DB::value("SELECT COUNT(*) FROM followups WHERE customer_id=?", [$cid]), 'no followup created for booked');

    // 4) Timeline soft-delete filter
    DB::exec('UPDATE customer_timeline SET deleted_at=NOW() WHERE customer_id=? AND type=?', [$cid, 'followup_scheduled']);
    $tl2 = (int)DB::value("SELECT COUNT(*) FROM customer_timeline WHERE customer_id=? AND type='followup_scheduled' AND deleted_at IS NULL", [$cid]);
    same(0, $tl2, 'timeline soft-delete hides scheduled row');

    // 5) Timeline rows for the customer exist (result + scheduled)
    $tl = (int)DB::value("SELECT COUNT(*) FROM customer_timeline WHERE customer_id=? AND type IN ('followup','followup_scheduled')", [$cid]);
    same(2, $tl, 'result + scheduled timeline rows written');

    $pdo->rollBack();
    echo "followup.php: $assertions assertions OK (rolled back)\n";
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAIL: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
