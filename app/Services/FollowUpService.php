<?php
namespace App\Services;
use App\Core\DB;

final class FollowUpService {
    public static function intervalFor(int $customerId, int $serviceId): int {
        $c = DB::value('SELECT followup_interval_days FROM customers WHERE id=?', [$customerId]); if ($c) return (int)$c;
        $s = DB::value('SELECT followup_interval_days FROM services WHERE id=?', [$serviceId]); if ($s) return (int)$s;
        return (int)(DB::value("SELECT value FROM settings WHERE `key`='default_followup_days'") ?: 30);
    }
    public static function createForSession(int $sessionId): void {
        $row = DB::row('SELECT customer_id, service_id, massage_date FROM massage_sessions WHERE id=?', [$sessionId]); if(!$row) return;
        $days = self::intervalFor((int)$row['customer_id'], (int)$row['service_id']);
        $due = date('Y-m-d', strtotime($row['massage_date'] . " +$days days"));
        DB::insert('followups', ['customer_id'=>$row['customer_id'], 'session_id'=>$sessionId, 'due_date'=>$due, 'status'=>'pending', 'priority'=>'normal', 'description'=>'پیگیری خودکار پس از جلسه ماساژ', 'created_at'=>date('Y-m-d H:i:s')]);
        DB::insert('customer_timeline', ['customer_id'=>$row['customer_id'], 'type'=>'followup_created', 'title'=>'ایجاد پیگیری خودکار', 'body'=>'تاریخ پیگیری: '.$due, 'created_at'=>date('Y-m-d H:i:s')]);
    }
    public static function generateDueFromLastSessions(): int {
        $rows = DB::select("SELECT ms.id FROM massage_sessions ms LEFT JOIN followups f ON f.session_id=ms.id WHERE ms.status='completed' AND f.id IS NULL");
        foreach($rows as $r) self::createForSession((int)$r['id']); return count($rows);
    }
}
