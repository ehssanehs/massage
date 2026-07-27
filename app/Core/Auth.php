<?php
namespace App\Core;
use App\Services\Audit;

final class Auth {
    public static function user(): ?array {
        if (empty($_SESSION['uid'])) return null;
        static $user = null; if ($user) return $user;
        $user = DB::row("SELECT u.*, r.name role_name, r.slug role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active'", [$_SESSION['uid']]);
        return $user ?: null;
    }
    public static function id(): ?int { return self::user()['id'] ?? null; }
    public static function login(string $email, string $password): bool {
        $u = DB::row("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1", [$email]);
        if (!$u || !password_verify($password, $u['password_hash'])) return false;
        session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; DB::exec("UPDATE users SET last_login_at=NOW() WHERE id=?", [$u['id']]); Audit::log('login','users',(int)$u['id']); return true;
    }
    public static function logout(): void { if (self::id()) Audit::log('logout','users',self::id()); $_SESSION=[]; session_destroy(); }
    public static function requireLogin(): void { if (!self::user()) redirect('login'); }
    public static function can(string $permission): bool {
        $u = self::user(); if (!$u) return false; if (($u['role_slug'] ?? '') === 'super_admin') return true;
        $perms = json_decode($u['permissions'] ?: '[]', true) ?: [];
        $rolePerms = json_decode(DB::value('SELECT permissions FROM roles WHERE id=?', [$u['role_id']]) ?: '[]', true) ?: [];
        $perms = array_values(array_unique(array_merge($rolePerms, $perms)));
        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }
    public static function requireCan(string $permission): void { if (!self::can($permission)) { http_response_code(403); echo \App\Support\View::render('خطای دسترسی','<div class="alert alert-danger">شما به این بخش دسترسی ندارید.</div>'); exit; } }
}
