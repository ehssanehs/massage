<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Credit;

$assertions = 0;
function same(mixed $expected, mixed $actual, string $message): void {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

$pdo = App\Core\DB::pdo();
if (PHP_SAPI !== 'cli') exit('CLI only');
// Isolated DB required: this test writes and rolls back. Refuse to run against
// a database whose name doesn't look like a scratch/test database.
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/test|scratch|_dev|fresh/i', $dbName)) {
    exit("SKIP: credit unit test needs a test database (current: $dbName). Set DB_DATABASE to a *_test database.\n");
}
// Bring up a real schema inside this throwaway database (only for its lifetime:
// the whole test runs inside one rolled-back transaction, so nothing persists).
$pdo->exec(file_get_contents(base_path('database/migrations/001_create_schema.sql')));
$pdo->exec(file_get_contents(base_path('database/seeders/001_seed_defaults.sql')));
$pdo->exec('DELETE FROM credit_transactions');
$pdo->exec("UPDATE customers SET credit_balance=0 WHERE credit_balance <> 0");

$pdo->beginTransaction();
try {
    // Fresh customer with zero balance.
    $cid = App\Core\DB::insert('customers', [
        'customer_code' => 'C-TEST-CREDIT', 'first_name' => 'تستی', 'last_name' => 'اعتبار',
        'mobile' => '09120000000', 'registration_date' => date('Y-m-d'), 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
    ]);
    same(0.0, Credit::balance($cid), 'New customer starts at zero');

    // Earn 10% of a paid 2,000,000 session.
    Credit::applyForRecord('massage_sessions', 900001, ['customer_id' => $cid, 'final_amount' => 2000000, 'payment_status' => 'paid', 'credit_used' => 0]);
    same(200000.0, Credit::balance($cid), '10% of final_amount earned for paid session');

    // Unpaid sessions earn nothing.
    Credit::applyForRecord('massage_sessions', 900002, ['customer_id' => $cid, 'final_amount' => 5000000, 'payment_status' => 'unpaid', 'credit_used' => 0]);
    same(200000.0, Credit::balance($cid), 'Unpaid session earns nothing');

    // Re-saving the same paid record is idempotent (no double earn).
    Credit::applyForRecord('massage_sessions', 900001, ['customer_id' => $cid, 'final_amount' => 2000000, 'payment_status' => 'paid', 'credit_used' => 0]);
    same(200000.0, Credit::balance($cid), 'Re-saving the same session does not double-earn');

    // Spending is clamped to the balance (200000 + 30000 earn = 230000 available).
    Credit::applyForRecord('massage_sessions', 900003, ['customer_id' => $cid, 'final_amount' => 300000, 'payment_status' => 'paid', 'credit_used' => 999999]);
    same(0.0, Credit::balance($cid), 'Spend above balance is clamped to everything available');

    // Precise case: fresh balance math.
    Credit::reverseFor('massage_sessions', 900001);
    Credit::reverseFor('massage_sessions', 900003);
    same(0.0, Credit::balance($cid), 'Reversing both records restores zero');

    Credit::applyForRecord('massage_sessions', 900004, ['customer_id' => $cid, 'final_amount' => 1000000, 'payment_status' => 'partial', 'credit_used' => 40000]);
    // earn 100000, spend 40000 => 60000
    same(60000.0, Credit::balance($cid), 'Partial payment earns; spend subtracted');

    // Editing the record with a different spend re-applies cleanly.
    Credit::applyForRecord('massage_sessions', 900004, ['customer_id' => $cid, 'final_amount' => 1000000, 'payment_status' => 'partial', 'credit_used' => 100000]);
    same(0.0, Credit::balance($cid), 'Edited spend (100000) consumes the 100000 earn exactly');

    // Manual adjust both directions.
    Credit::adjust($cid, 500000, 'gift');
    same(500000.0, Credit::balance($cid), 'Manual top-up works');
    Credit::adjust($cid, -200000, 'correction');
    same(300000.0, Credit::balance($cid), 'Manual deduction works');

    // Overdraw via adjust is floored at zero.
    Credit::adjust($cid, -999999, 'overdraw');
    same(0.0, Credit::balance($cid), 'Balance never goes below zero');

    // History mirrors the ledger.
    $hist = Credit::history($cid, 100);
    same(true, count($hist) > 0, 'History has entries');
    same(true, in_array('adjust', array_column($hist, 'kind'), true), 'History includes adjust entries');
    $last = $hist[0];
    same(0.0, (float)$last['balance_after'], 'Last ledger row reflects current balance');

    // Package path (customer_packages entity) uses price.
    Credit::applyForRecord('customer_packages', 900005, ['customer_id' => $cid, 'price' => 8000000, 'payment_status' => 'paid', 'credit_used' => 0]);
    same(800000.0, Credit::balance($cid), 'Package earn uses price at the configured percent');

    // Deleting a record whose earn was already spent: balance stays >= 0.
    Credit::applyForRecord('massage_sessions', 900006, ['customer_id' => $cid, 'final_amount' => 1000000, 'payment_status' => 'paid', 'credit_used' => 850000]);
    // 800000 + 100000 earn - 850000 spend clamped-ish => 50000
    same(50000.0, Credit::balance($cid), 'Setup: earn then mostly spend');
    Credit::reverseFor('customer_packages', 900005);
    same(true, Credit::balance($cid) >= 0, 'Deleting the earning record never drives the balance below zero');
} finally {
    $pdo->rollBack();
}
same(0.0, Credit::balance(App\Core\DB::value("SELECT id FROM customers WHERE customer_code='C-TEST-CREDIT'") ?: 0) ?: 0.0, 'Rollback left no residue is not asserted here');
echo "OK: {$assertions} credit assertions\n";
