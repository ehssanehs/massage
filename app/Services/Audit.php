<?php
namespace App\Services;
use App\Core\Auth;
use App\Core\DB;

final class Audit {
    public static function log(string $action, ?string $entity=null, ?int $entityId=null, array $meta=[]): void {
        try { DB::insert('audit_logs', ['user_id'=>Auth::id(), 'action'=>$action, 'entity'=>$entity, 'entity_id'=>$entityId, 'ip_address'=>$_SERVER['REMOTE_ADDR'] ?? '', 'user_agent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,250), 'metadata'=>json_encode($meta, JSON_UNESCAPED_UNICODE), 'created_at'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
    }
}
