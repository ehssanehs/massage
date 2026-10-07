<?php
declare(strict_types=1);
namespace App\Core {
    final class DB {
        public static array $therapist=[];
        public static array $sessions=[];
        public static bool $hasArabSnapshot=true;
        public static function row(string $sql, array $params=[]): ?array { return self::$therapist; }
        public static function value(string $sql, array $params=[]): mixed { return self::$hasArabSnapshot ? 1 : 0; }
        public static function select(string $sql, array $params=[]): array {
            if (!str_contains($sql, "ms.deleted_at IS NULL")) throw new \RuntimeException('Deleted sessions must not contribute to salary');
            if (!self::$hasArabSnapshot && str_contains($sql, 'ms.arab_commission_percent')) throw new \RuntimeException('Old schema must not read the missing snapshot column');
            return self::$sessions;
        }
    }
}
namespace {
    require dirname(__DIR__).'/app/bootstrap.php';
    use App\Core\DB;
    use App\Services\SalaryService;
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    DB::$sessions=[
        ['final_amount'=>1000, 'arab_commission_percent'=>null],
        ['final_amount'=>2000, 'arab_commission_percent'=>'12.50'],
        ['final_amount'=>500, 'arab_commission_percent'=>'0.00'],
    ];
    foreach ([
        ['percentage',300,300,550],
        ['base_plus_percentage',300,300,650],
        ['fixed_per_session',70,70,320],
        ['base_plus_fixed',70,70,420],
        ['fixed_salary',0,0,350],
    ] as [$model,$expectedOrdinary,$ignored,$expectedPayable]) {
        DB::$therapist=['salary_model'=>$model,'base_salary'=>100,'commission_percentage'=>30,'fixed_commission'=>70];
        $c=SalaryService::calculate(1,'2026-10-01','2026-10-31');
        check($c['session_count']===3 && $c['gross']===3500.0, "$model session totals");
        check($c['ordinary_commission']===(float)$expectedOrdinary, "$model ordinary");
        check($c['arab_commission']===250.0, "$model Arab override");
        check($c['commission']===$expectedOrdinary+250.0 && $c['payable']===(float)$expectedPayable, "$model payable");
    }
    DB::$sessions=[['final_amount'=>1000]];
    DB::$therapist=['salary_model'=>'percentage','base_salary'=>0,'commission_percentage'=>30,'fixed_commission'=>0];
    check(SalaryService::calculate(1,'2026-10-01','2026-10-31')['commission']===300.0, 'Old session without snapshot is ordinary');
    echo "arab-commission-salary.php: models and legacy session OK\n";
}
