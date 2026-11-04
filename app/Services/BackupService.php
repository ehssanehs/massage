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
    /** How the last dump was produced: "mysqldump" or "php". */
    private static string $lastMethod = 'mysqldump';
    public static function lastMethod(): string { return self::$lastMethod; }

    /**
     * Dump the current section's database.
     *
     * The command-line client is tried first because it is faster and handles
     * every column type. It fails on plenty of real hosts though (limited MySQL
     * user without PROCESS/LOCK TABLES, mysqldump missing, exec() disabled), and
     * a failed backup must not be the end of the story: when the client cannot
     * produce a valid file, the same database is dumped through PDO instead.
     */
    public static function create(): string {
        $dir = self::directory(); if(!is_dir($dir)) mkdir($dir,0755,true);
        // Each section keeps its own database, so backups must carry the tenant
        // in the filename — otherwise the second dump overwrites the first.
        $tenant = \App\Support\Tenant::current();
        $file = $dir . '/backup-' . ($tenant ? $tenant . '-' : '') . date('Ymd-His') . '.sql';
        $database = \App\Support\Tenant::databaseName();

        $problem = '';
        if (self::canRunClient()) {
            try {
                self::dumpWithClient($database, $file);
                self::$lastMethod = 'mysqldump';
                return $file;
            } catch (\RuntimeException $e) {
                $problem = $e->getMessage();
                @unlink($file);
            }
        } else {
            $problem = 'دستور mysqldump روی این سرور در دسترس نیست.';
        }

        try {
            self::dumpWithPdo($database, $file);
            self::$lastMethod = 'php';
            return $file;
        } catch (\Throwable $e) {
            @unlink($file);
            throw new \RuntimeException(
                'پشتیبان‌گیری انجام نشد. '
                . ($problem !== '' ? 'خطای mysqldump: ' . $problem . ' ' : '')
                . 'خطای روش جایگزین: ' . $e->getMessage()
            );
        }
    }

    /** True when exec() is usable and the client binary exists. */
    private static function canRunClient(): bool {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) return false;
        $out = [];
        $code = 1;
        @exec('command -v mysqldump 2>/dev/null', $out, $code);
        return $code === 0 && $out !== [];
    }

    /**
     * Run the client. Throws with the real reason instead of a bare "failed":
     * the exit code alone is useless to whoever has to fix the server.
     */
    private static function dumpWithClient(string $database, string $file): void {
        $defaults = tempnam(sys_get_temp_dir(), 'massage-dump-');
        $errors = tempnam(sys_get_temp_dir(), 'massage-dump-err-');
        if ($defaults === false || $errors === false) throw new \RuntimeException('ساخت فایل موقت برای پشتیبان‌گیری ممکن نشد.');
        @chmod($defaults, 0600);
        try {
            file_put_contents($defaults, self::credentialsFile());
            // --defaults-extra-file must come first or the client ignores it.
            // --single-transaction/--quick/--skip-lock-tables let a user without
            // the LOCK TABLES privilege dump consistently; --no-tablespaces and
            // --set-gtid-purged=OFF avoid the PROCESS/SUPER checks MySQL 8 makes.
            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --default-character-set=utf8mb4 '
                . '--single-transaction --quick --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF %s > %s 2> %s',
                escapeshellarg($defaults),
                escapeshellarg($database),
                escapeshellarg($file),
                escapeshellarg($errors)
            );
            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);
            $stderr = trim((string)@file_get_contents($errors));
            if ($code !== 0) {
                throw new \RuntimeException($stderr !== ''
                    ? $stderr
                    : 'mysqldump با کد ' . $code . ' پایان یافت و پیامی برنگرداند.');
            }
            if (!self::looksLikeDump($file)) {
                throw new \RuntimeException('فایل تولیدشده ساختار یک دامپ معتبر را ندارد' . ($stderr !== '' ? ': ' . $stderr : '.'));
            }
        } finally {
            @unlink($defaults);
            @unlink($errors);
        }
    }

    /**
     * Pure-PHP dump: SHOW CREATE TABLE plus batched INSERTs.
     *
     * Slower than the client but works with nothing but the PDO connection the
     * application already has, which is exactly the situation this is here for.
     */
    private static function dumpWithPdo(string $database, string $file): void {
        $pdo = \App\Core\DB::pdo();
        $handle = fopen($file, 'wb');
        if ($handle === false) throw new \RuntimeException('نوشتن فایل پشتیبان ممکن نشد: ' . $file);

        $write = static function (string $line) use ($handle): void { fwrite($handle, $line); };
        try {
            $write("-- Massage CRM backup (PHP dump)\n");
            $write('-- database: ' . $database . "\n");
            $write('-- generated: ' . date('Y-m-d H:i:s') . "\n\n");
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = [];
            foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $row) {
                $tables[] = (string)array_values($row)[0];
            }

            foreach ($tables as $table) {
                $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch();
                if (!$create) continue;
                $write("\n--\n-- Table `{$table}`\n--\n\n");
                $write('DROP TABLE IF EXISTS `' . $table . "`;\n");
                $write((string)($create['Create Table'] ?? array_values($create)[1] ?? '') . ";\n\n");

                $offset = 0;
                $chunk = 500;
                $columns = null;
                while (true) {
                    $rows = $pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . $chunk . ' OFFSET ' . $offset)->fetchAll(\PDO::FETCH_ASSOC);
                    if ($rows === []) break;
                    if ($columns === null) {
                        $columns = '`' . implode('`,`', array_keys($rows[0])) . '`';
                    }
                    $values = [];
                    foreach ($rows as $row) {
                        $cells = [];
                        foreach ($row as $value) {
                            $cells[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                        }
                        $values[] = '(' . implode(',', $cells) . ')';
                    }
                    $write('INSERT INTO `' . $table . '` (' . $columns . ") VALUES\n" . implode(",\n", $values) . ";\n");
                    $offset += $chunk;
                    if (count($rows) < $chunk) break;
                }
                $write("\n");
            }

            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        if (!self::looksLikeDump($file)) {
            throw new \RuntimeException('فایل پشتیبان معتبر ساخته نشد.');
        }
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
