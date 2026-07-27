<?php
namespace App\Services;
use App\Core\DB;

final class RetentionService {
    public static function metrics(int $limit=50): array {
        return DB::select("SELECT c.*, MAX(ms.massage_date) last_visit, COUNT(ms.id) visits, COALESCE(SUM(ms.final_amount),0) monetary, DATEDIFF(CURDATE(), MAX(ms.massage_date)) recency_days,
        CASE WHEN COALESCE(SUM(ms.final_amount),0) >= 50000000 OR COUNT(ms.id)>=10 THEN 'vip' WHEN DATEDIFF(CURDATE(), MAX(ms.massage_date))>90 THEN 'lost' WHEN DATEDIFF(CURDATE(), MAX(ms.massage_date))>45 THEN 'at_risk' WHEN COUNT(ms.id)=0 THEN 'new' ELSE 'active' END segment
        FROM customers c LEFT JOIN massage_sessions ms ON ms.customer_id=c.id AND ms.status='completed' WHERE c.deleted_at IS NULL GROUP BY c.id ORDER BY monetary DESC, recency_days DESC LIMIT $limit");
    }
    public static function recommendations(): array { return DB::select("SELECT * FROM followups WHERE status IN ('pending','requested_later') AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY due_date ASC LIMIT 25"); }
}
