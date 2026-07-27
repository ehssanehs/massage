<?php
namespace App\Core;
use PDO;
use PDOException;

final class DB {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $host = env_value('DB_HOST','127.0.0.1'); $port = env_value('DB_PORT','3306');
        $db = env_value('DB_DATABASE','massage_crm'); $user = env_value('DB_USERNAME','root'); $pass = env_value('DB_PASSWORD','');
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        try {
            self::$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
            return self::$pdo;
        } catch (PDOException $e) {
            http_response_code(500);
            echo '<div style="font-family:tahoma;direction:rtl;margin:40px"><h2>خطای اتصال پایگاه داده</h2><p>فایل .env و اطلاعات MySQL را بررسی کنید.</p><pre>'.htmlspecialchars($e->getMessage()).'</pre></div>';
            exit;
        }
    }
    public static function select(string $sql, array $params=[]): array { $st=self::pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
    public static function row(string $sql, array $params=[]): ?array { $st=self::pdo()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r?:null; }
    public static function value(string $sql, array $params=[]): mixed { $st=self::pdo()->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
    public static function exec(string $sql, array $params=[]): int { $st=self::pdo()->prepare($sql); $st->execute($params); return $st->rowCount(); }
    public static function insert(string $table, array $data): int {
        $cols = array_keys($data); $place = array_map(fn($c)=>":$c", $cols);
        $sql = "INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$place).")";
        self::exec($sql, $data); return (int)self::pdo()->lastInsertId();
    }
    public static function update(string $table, array $data, string $where, array $params=[]): int {
        $sets=[]; foreach(array_keys($data) as $c) $sets[]="`$c`=:$c";
        return self::exec("UPDATE `$table` SET ".implode(',',$sets)." WHERE $where", array_merge($data,$params));
    }
}
