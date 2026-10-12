<?php
namespace App\Services;

final class BackupService {
    /**
     * Dump one section's database to a .sql file and return its path.
     *
     * Two rules matter for the file actually being restorable:
     *
     *  - Credentials are passed through a private defaults file rather than
     *    --password on the command line. The command-line form makes the client
     *    print "Using a password on the command line interface can be insecure.";
     *    that warning was previously merged into the dump file, so the very first
     *    line was not SQL and `mysql < backup.sql` died with ERROR 1064 at line 1.
     *  - stderr never goes into the dump. It is captured separately, and the
     *    result is checked to look like a dump before the caller is told it
     *    succeeded, so a warning-only file is never presented as a backup.
     */
    public static function create(): string {
        $dir = self::directory(); if(!is_dir($dir)) mkdir($dir,0755,true);
        // Each section keeps its own database, so backups must carry the tenant
        // in the filename — otherwise the second dump overwrites the first.
        $tenant = \App\Support\Tenant::current();
        $file = $dir . '/backup-' . ($tenant ? $tenant . '-' : '') . date('Ymd-His') . '.sql';

        $defaults = tempnam(sys_get_temp_dir(), 'massage-dump-');
        $errors = tempnam(sys_get_temp_dir(), 'massage-dump-err-');
        if ($defaults === false || $errors === false) throw new \RuntimeException('ساخت فایل موقت برای پشتیبان‌گیری ممکن نشد.');
        @chmod($defaults, 0600);
        try {
            file_put_contents($defaults, self::credentialsFile());
            // --defaults-extra-file must be the FIRST option, otherwise the
            // client ignores it.
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --default-character-set=utf8mb4 %s > %s 2> %s',
                escapeshellarg($defaults),
                escapeshellarg(\App\Support\Tenant::databaseName()),
                escapeshellarg($file),
                escapeshellarg($errors)
            );
            exec($cmd, $out, $code);
            $stderr = trim((string)@file_get_contents($errors));
            if ($code !== 0) {
                @unlink($file);
                throw new \RuntimeException($stderr !== '' ? $stderr : 'mysqldump با خطا پایان یافت.');
            }
            if (!self::looksLikeDump($file)) {
                @unlink($file);
                throw new \RuntimeException('فایل پشتیبان معتبر ساخته نشد' . ($stderr !== '' ? ': ' . $stderr : '.'));
            }
        } finally {
            @unlink($defaults);
            @unlink($errors);
        }
        return $file;
    }

    /**
     * Backup directory. An absolute BACKUP_PATH is honoured as-is so backups can
     * be kept OUTSIDE the web root (recommended); a relative one resolves against
     * the project.
     */
    public static function directory(): string {
        $configured = trim((string)env_value('BACKUP_PATH', 'storage/backups'));
        if ($configured === '') $configured = 'storage/backups';
        return str_starts_with($configured, '/') ? rtrim($configured, '/') : base_path($configured);
    }

    /** Credentials for the client, readable only by the web/CLI user. */
    private static function credentialsFile(): string {
        $quote = static function (string $value): string {
            // cnf values break on '#' and on leading/trailing spaces unless quoted.
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        };
        $lines = "[client]\n"
            . 'host=' . $quote((string)env_value('DB_HOST','127.0.0.1')) . "\n"
            . 'port=' . $quote((string)env_value('DB_PORT','3306')) . "\n"
            . 'user=' . $quote((string)env_value('DB_USERNAME','root')) . "\n";
        $password = (string)env_value('DB_PASSWORD','');
        if ($password !== '') $lines .= 'password=' . $quote($password) . "\n";
        return $lines;
    }

    /**
     * A real dump starts with a comment or a conditional statement. A file that
     * only holds client warnings must never be offered as a restorable backup.
     */
    private static function looksLikeDump(string $file): bool {
        if (!is_file($file) || filesize($file) === 0) return false;
        $head = (string)file_get_contents($file, false, null, 0, 4096);
        $first = '';
        foreach (preg_split('/\R/', $head) ?: [] as $line) {
            if (trim($line) !== '') { $first = ltrim($line); break; }
        }
        return str_starts_with($first, '--') || str_starts_with($first, '/*');
    }

    /** Human-facing date label; keep technical filenames/backups unchanged on disk. */
    public static function displayName(string $filename): string {
        if (!preg_match('/\Abackup-(?:(?:men|women)-)?(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.sql\z/', $filename, $m)) return $filename;
        $date = \App\Support\Jalali::dateTime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]", true);
        return $date !== '' ? 'پشتیبان — ' . $date : $filename;
    }

    public static function list(): array { $dir=self::directory(); return is_dir($dir)?array_values(array_filter(scandir($dir), fn($f)=>str_ends_with($f,'.sql'))):[]; }
}
