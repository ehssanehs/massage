<?php
namespace App\Core;

final class Security {
    public static function csrfToken(): string {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . e(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        $route = $_GET['r'] ?? 'dashboard';
        $submitted = $_POST['_csrf'] ?? null;
        $expected  = $_SESSION['_csrf'] ?? null;

        // If session has no CSRF token yet (new/expired session), generate one
        // and let the request through for the login route only — the user isn't
        // authenticated yet, so there's nothing to protect on behalf of them.
        if ($expected === null) {
            self::csrfToken(); // generate a fresh token for the session
            if ($route === 'login') return; // allow login on fresh sessions
            http_response_code(419);
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>خطا</title>'
                . '<style>body{font-family:Vazirmatn,Tahoma,sans-serif;display:grid;place-items:center;min-height:100vh;background:#f5f3ff}'
                . '.box{background:#fff;padding:40px;border-radius:20px;text-align:center;max-width:420px;box-shadow:0 12px 40px rgba(0,0,0,.08)}'
                . 'a{display:inline-block;margin-top:16px;padding:10px 28px;background:#7c3aed;color:#fff;border-radius:14px;text-decoration:none}</style></head>'
                . '<body><div class="box"><h2>نشست شما منقضی شده است</h2><p>لطفاً صفحه را رفرش کنید و دوباره تلاش کنید.</p>'
                . '<a href="javascript:location.reload()">بارگذاری مجدد صفحه</a></div></body></html>';
            exit;
        }

        // Token exists in session — validate the submitted value.
        // NOTE: the token is intentionally NOT rotated here. Rotating it on
        // every POST invalidates every other open tab / duplicated form and
        // surfaces as random "خطای امنیتی (CSRF)" pages on normal usage.
        if (!$submitted || !hash_equals($expected, (string)$submitted)) {
            http_response_code(419);
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>خطا</title>'
                . '<style>body{font-family:Vazirmatn,Tahoma,sans-serif;display:grid;place-items:center;min-height:100vh;background:#f5f3ff}'
                . '.box{background:#fff;padding:40px;border-radius:20px;text-align:center;max-width:420px;box-shadow:0 12px 40px rgba(0,0,0,.08)}'
                . 'a{display:inline-block;margin-top:16px;padding:10px 28px;background:#7c3aed;color:#fff;border-radius:14px;text-decoration:none}</style></head>'
                . '<body><div class="box"><h2>خطای امنیتی (CSRF)</h2><p>توکن امنیتی شما منقضی شده یا معتبر نیست. لطفاً صفحه را رفرش کنید و دوباره تلاش کنید.</p>'
                . '<a href="javascript:location.reload()">بارگذاری مجدد صفحه</a></div></body></html>';
            exit;
        }

    }

    public static function rateLimit(string $key, int $max, int $seconds): bool {
        $now = time();
        $_SESSION['_rate'][$key] = array_filter($_SESSION['_rate'][$key] ?? [], fn($t) => $t > $now - $seconds);
        if (count($_SESSION['_rate'][$key]) >= $max) return false;
        $_SESSION['_rate'][$key][] = $now;
        return true;
    }

    public static function cleanString(?string $v): ?string {
        if ($v === null) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
