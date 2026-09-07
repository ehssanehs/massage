<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Customer credit (loyalty wallet).
 *
 * Design:
 * - `customers.credit_balance` is a cached balance for fast display.
 * - `credit_transactions` is the append-only ledger: every earn/spend/adjust
 *   writes one row with the signed amount and the balance right after it.
 * - Earning is idempotent per source record: re-saving a session/package first
 *   reverses that record's previous earn+spend entries (kind reversal via
 *   negative counterparts is avoided — instead the original entries are
 *   deleted and re-created, because a source record owns exactly its entries).
 * - Balance never goes below zero: spends are clamped on write.
 */
final class Credit {
    public const EARN_PERCENT_KEY = 'credit_earn_percent';

    /** Earn percent from settings (0-100), defaults to 10. */
    public static function earnPercent(): float {
        $raw = DB::value('SELECT value FROM settings WHERE `key`=?', [self::EARN_PERCENT_KEY]);
        if (!is_numeric($raw)) return 10.0;
        return min(100.0, max(0.0, (float)$raw));
    }

    /** Current cached balance of a customer. */
    public static function balance(int $customerId): float {
        return (float)(DB::value('SELECT credit_balance FROM customers WHERE id=?', [$customerId]) ?? 0);
    }

    /**
     * Append a ledger entry and move the cached balance. Returns the new balance.
     * $amount is signed (earn/positive-adjust > 0, spend/negative-adjust < 0).
     * The balance is floored at zero so a spend can never overdraw.
     */
    public static function post(int $customerId, string $kind, float $amount, ?string $entity = null, ?int $entityId = null, ?string $note = null, ?int $actorId = null): float {
        if ($customerId <= 0) throw new \InvalidArgumentException('مشتری نامعتبر است.');
        $amount = round($amount, 2);
        if ($amount == 0.0) return self::balance($customerId);
        $balance = self::balance($customerId);
        $new = round($balance + $amount, 2);
        if ($new < 0) { $amount = round(-$balance, 2); $new = 0.0; }
        DB::exec('UPDATE customers SET credit_balance=?, updated_at=NOW() WHERE id=?', [$new, $customerId]);
        DB::insert('credit_transactions', [
            'customer_id' => $customerId,
            'kind' => $kind,
            'amount' => $amount,
            'balance_after' => $new,
            'entity' => $entity,
            'entity_id' => $entityId,
            'note' => $note,
            'created_by' => $actorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $new;
    }

    /**
     * Reverse every ledger entry a source record (session/package) previously
     * created, restoring the balance. Called before re-applying on edit, and
     * on soft-delete so removed records leave no credit behind.
     *
     * Reversal runs newest-first (LIFO) and bypasses the zero floor: a refund
     * must restore EXACTLY what was posted, otherwise clamping would corrupt
     * the balance when other records moved it in between. The floor still
     * applies to fresh spends in post().
     */
    public static function reverseFor(string $entity, int $entityId): float {
        // Only real postings are reversible; refund rows of earlier reversals
        // are already the mirror image — reversing them again would pay out twice.
        $rows = DB::select("SELECT id, customer_id, amount FROM credit_transactions WHERE entity=? AND entity_id=? AND kind IN ('earn','spend','adjust') ORDER BY id DESC", [$entity, $entityId]);
        $ids = array_map(static fn($row) => (int)$row['id'], $rows);
        $balance = null;
        foreach ($rows as $row) {
            $customerId = (int)$row['customer_id'];
            $refund = -(float)$row['amount'];
            $balance = round(self::balance($customerId) + $refund, 2);
            DB::exec('UPDATE customers SET credit_balance=?, updated_at=NOW() WHERE id=?', [$balance, $customerId]);
            DB::insert('credit_transactions', [
                'customer_id' => $customerId,
                'kind' => 'refund',
                'amount' => $refund,
                'balance_after' => $balance,
                'entity' => $entity,
                'entity_id' => $entityId,
                'note' => 'برگشت اثر رکورد حذف/ویرایش‌شده',
                'created_by' => self::actor(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        // Remove exactly the reversed rows (by id): refund rows created by an
        // earlier reverse-and-reapply of the same record are reversed again and
        // removed as well, while the fresh refund rows of THIS call stay.
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            DB::exec("DELETE FROM credit_transactions WHERE id IN ($placeholders)", $ids);
        }
        // The ledger is the source of truth: recompute the displayed balance
        // from the full SUM (clamped at zero) so refund rows that were clamped
        // away earlier cannot leave stale credit behind.
        $customerId = $rows ? (int)$rows[0]['customer_id'] : 0;
        if ($customerId > 0) {
            $sum = (float)DB::value('SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE customer_id=?', [$customerId]);
            $balance = round(max(0.0, $sum), 2);
            DB::exec('UPDATE customers SET credit_balance=?, updated_at=NOW() WHERE id=?', [$balance, $customerId]);
        }
        return $balance ?? 0.0;
    }

    /**
     * Recompute earn+spend for a session/package from its current data.
     * Earn = earnPercent() of final amount when payment_status is paid/partial.
     * Spend = min(requested credit use, current balance).
     */
    public static function applyForRecord(string $entity, int $recordId, array $data): float {
        $customerId = (int)($data['customer_id'] ?? 0);
        if ($customerId <= 0) return 0.0;
        self::reverseFor($entity, $recordId);
        $amount = (float)($data['final_amount'] ?? $data['price'] ?? 0);
        $status = (string)($data['payment_status'] ?? 'paid');
        $actor = self::actor();
        if ($amount > 0 && in_array($status, ['paid', 'partial'], true)) {
            $earn = round($amount * self::earnPercent() / 100, 2);
            if ($earn > 0) self::post($customerId, 'earn', $earn, $entity, $recordId, 'اعتبار خرید (' . self::earnPercent() . '٪)', $actor);
        }
        $use = round((float)($data['credit_used'] ?? 0), 2);
        if ($use > 0) {
            $spend = min($use, self::balance($customerId));
            if ($spend > 0) self::post($customerId, 'spend', -$spend, $entity, $recordId, 'کسر از اعتبار در پرداخت', $actor);
        }
        return self::balance($customerId);
    }

    /** Manual top-up/deduction from the customer profile. Positive = top-up. */
    public static function adjust(int $customerId, float $amount, string $note = ''): float {
        return self::post($customerId, 'adjust', $amount, 'customers', $customerId, $note !== '' ? $note : 'اصلاح دستی اعتبار', self::actor());
    }

    /** Recent ledger entries for a customer, newest first. */
    public static function history(int $customerId, int $limit = 50): array {
        return DB::select('SELECT * FROM credit_transactions WHERE customer_id=? ORDER BY id DESC LIMIT ' . max(1, (int)$limit), [$customerId]);
    }

    private static function actor(): ?int {
        return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    }
}
