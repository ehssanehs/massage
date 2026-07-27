<?php
namespace App\Services;

final class BackupService {
    public static function create(): string {
        $dir = base_path(env_value('BACKUP_PATH','public/storage/backups')); if(!is_dir($dir)) mkdir($dir,0755,true);
        $file = $dir . '/backup-' . date('Ymd-His') . '.sql';
        $cmd = sprintf('mysqldump --host=%s --port=%s --user=%s %s %s > %s 2>&1', escapeshellarg(env_value('DB_HOST','127.0.0.1')), escapeshellarg(env_value('DB_PORT','3306')), escapeshellarg(env_value('DB_USERNAME','root')), env_value('DB_PASSWORD','')!=='' ? '--password='.escapeshellarg(env_value('DB_PASSWORD','')) : '', escapeshellarg(env_value('DB_DATABASE','massage_crm')), escapeshellarg($file));
        exec($cmd, $out, $code); if($code!==0) throw new \RuntimeException(implode("\n", $out)); return $file;
    }
    public static function list(): array { $dir=base_path(env_value('BACKUP_PATH','public/storage/backups')); return is_dir($dir)?array_values(array_filter(scandir($dir), fn($f)=>str_ends_with($f,'.sql'))):[]; }
}
