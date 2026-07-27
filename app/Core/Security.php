<?php
namespace App\Core;

final class Security {
    public static function csrfToken(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf']=bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
    public static function csrfField(): string { return '<input type="hidden" name="_csrf" value="'.e(self::csrfToken()).'">'; }
    public static function verifyCsrf(): void { if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['_csrf']) || !hash_equals($_SESSION['_csrf'] ?? '', (string)$_POST['_csrf']))) { http_response_code(419); exit('CSRF token mismatch'); } }
    public static function rateLimit(string $key, int $max, int $seconds): bool {
        $now=time(); $_SESSION['_rate'][$key] = array_filter($_SESSION['_rate'][$key] ?? [], fn($t)=>$t>$now-$seconds);
        if (count($_SESSION['_rate'][$key]) >= $max) return false;
        $_SESSION['_rate'][$key][]=$now; return true;
    }
    public static function cleanString(?string $v): ?string { if ($v===null) return null; $v=trim($v); return $v===''?null:$v; }
}
