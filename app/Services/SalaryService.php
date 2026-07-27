<?php
namespace App\Services;
use App\Core\DB;

final class SalaryService {
    public static function calculate(int $therapistId, string $from, string $to): array {
        $t = DB::row('SELECT * FROM therapists WHERE id=?', [$therapistId]); if(!$t) return [];
        $sessions = DB::select("SELECT ms.*, s.name service_name FROM massage_sessions ms LEFT JOIN services s ON s.id=ms.service_id WHERE ms.therapist_id=? AND ms.status='completed' AND ms.massage_date BETWEEN ? AND ?", [$therapistId,$from,$to]);
        $gross = array_sum(array_map(fn($s)=>(float)$s['final_amount'], $sessions)); $count=count($sessions); $base=(float)$t['base_salary']; $commission=0;
        $model=$t['salary_model'];
        if (in_array($model,['percentage','base_plus_percentage'], true)) $commission += $gross * ((float)$t['commission_percentage']/100);
        if (in_array($model,['fixed_per_session','base_plus_fixed'], true)) $commission += $count * (float)$t['fixed_commission'];
        $payable = (str_starts_with($model,'base') || $model==='fixed_salary') ? $base + $commission : $commission;
        return ['therapist'=>$t,'sessions'=>$sessions,'session_count'=>$count,'gross'=>$gross,'base_salary'=>$base,'commission'=>$commission,'bonuses'=>0,'deductions'=>0,'payable'=>$payable];
    }
}
