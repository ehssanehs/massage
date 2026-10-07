<?php
namespace App\Services;
use App\Core\DB;
use App\Services\ArabCommission;

final class SalaryService {
    public static function calculate(int $therapistId, string $from, string $to): array {
        $t = DB::row('SELECT * FROM therapists WHERE id=?', [$therapistId]); if(!$t) return [];
        $hasArabSnapshots=ArabCommission::hasSessionColumn();
        $snapshotSelect=$hasArabSnapshots ? '' : 'NULL AS arab_commission_percent,';
        $sessions = DB::select("SELECT ms.*, $snapshotSelect s.name service_name FROM massage_sessions ms LEFT JOIN services s ON s.id=ms.service_id WHERE ms.therapist_id=? AND ms.status='completed' AND ms.deleted_at IS NULL AND ms.massage_date BETWEEN ? AND ?", [$therapistId,$from,$to]);
        $gross = 0.0; $ordinaryGross = 0.0; $ordinaryCount = 0; $arabCommission = 0.0;
        foreach ($sessions as $s) {
            $amount = (float)$s['final_amount']; $gross += $amount;
            if (($s['arab_commission_percent'] ?? null) !== null) {
                $arabCommission += $amount * (float)$s['arab_commission_percent'] / 100;
            } else { $ordinaryGross += $amount; $ordinaryCount++; }
        }
        $count=count($sessions); $base=(float)$t['base_salary']; $ordinaryCommission=0.0;
        $model=$t['salary_model'];
        if (in_array($model,['percentage','base_plus_percentage'], true)) $ordinaryCommission += $ordinaryGross * ((float)$t['commission_percentage']/100);
        if (in_array($model,['fixed_per_session','base_plus_fixed'], true)) $ordinaryCommission += $ordinaryCount * (float)$t['fixed_commission'];
        $commission=$ordinaryCommission+$arabCommission;
        $payable = (str_starts_with($model,'base') || $model==='fixed_salary') ? $base + $commission : $commission;
        return ['therapist'=>$t,'sessions'=>$sessions,'session_count'=>$count,'gross'=>$gross,'base_salary'=>$base,'commission'=>$commission,'ordinary_commission'=>$ordinaryCommission,'arab_commission'=>$arabCommission,'bonuses'=>0,'deductions'=>0,'payable'=>$payable];
    }
}
