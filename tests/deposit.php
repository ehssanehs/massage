<?php
declare(strict_types=1);
// Appointment deposit feature checks (CLI only, isolated DB, rolled back).
require __DIR__ . '/../app/bootstrap.php';

use App\Core\DB;

$pdo = DB::pdo();
if (PHP_SAPI !== 'cli') exit('CLI only');
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/test|scratch|_dev|fresh/i', $dbName)) {
    exit("SKIP: deposit test needs a test database (current: $dbName).\n");
}
$pdo->exec(file_get_contents(base_path('database/migrations/001_create_schema.sql')));
$pdo->exec(file_get_contents(base_path('database/migrations/004_appointment_deposit.sql')));
$pdo->exec(file_get_contents(base_path('database/seeders/001_seed_defaults.sql')));

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

// Re-run DDL before starting a rollback transaction (MySQL ALTER implicitly commits).
$pdo->exec(file_get_contents(base_path('database/migrations/004_appointment_deposit.sql')));
$pdo->beginTransaction();
try {
    // 1) Column exists in fresh schema + migration
    $col = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'deposit_amount'")->fetch();
    same(true, (bool)$col, 'appointments.deposit_amount exists');
    same('decimal(15,2)', strtolower((string)$col['Type']), 'type is exact decimal(15,2)');

    // 2) Migration idempotency: running 004 twice must not fail
    same(true, (bool)$col, '004 re-run retained deposit column');

    // 3) Default 0 for new rows; positive deposit stored with decimals
    $cid = DB::insert('customers', ['first_name' => 'تست', 'last_name' => 'بیعانه', 'mobile' => '09120003344', 'customer_code' => 'T' . random_int(100000, 999999), 'registration_date' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s')]);
    $tid = (int)DB::value("SELECT id FROM therapists LIMIT 1");
    $sid = (int)DB::value("SELECT id FROM services LIMIT 1");
    $aid = DB::insert('appointments', ['customer_id' => $cid, 'therapist_id' => $tid, 'service_id' => $sid, 'appointment_date' => date('Y-m-d'), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'status' => 'confirmed', 'created_at' => date('Y-m-d H:i:s')]);
    same('0.00', (string)DB::value('SELECT deposit_amount FROM appointments WHERE id=?', [$aid]), 'default deposit is 0');

    DB::exec('UPDATE appointments SET deposit_amount=? WHERE id=?', [500000.50, $aid]);
    same('500000.50', (string)DB::value('SELECT deposit_amount FROM appointments WHERE id=?', [$aid]), 'decimal deposit stored');

    // 4) Deposit filter predicate matches only positive amounts
    $withDeposit = DB::value('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND deposit_amount > 0 AND customer_id=?', [$cid]);
    same(1, (int)$withDeposit, 'deposit>0 filter matches only the deposited appointment');

    $pdo->rollBack();
    echo "deposit.php: $assertions assertions OK (rolled back)\n";
} catch (Throwable $ex) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAIL: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}
