<?php
declare(strict_types=1);
namespace App\Support;

/**
 * The public (unauthenticated) site: the landing page and the two main sections
 * «خدمات ماساژ» and «بخش آموزش».
 *
 * Nothing here touches a CRM database — an anonymous visitor must never open a
 * connection to a section's data just by loading the home page. Copy lives in
 * config/public-site.php.
 */
final class PublicSite {
    private static function config(): array {
        static $config = null;
        return $config ??= require base_path('config/public-site.php');
    }

    /** Route names serving a public page, mapped to their section key. */
    public static function sectionKeyFor(string $route): ?string {
        if (!preg_match('/^public\.([a-z_]+)$/', $route, $m)) return null;
        $sections = self::config()['sections'];
        return isset($sections[$m[1]]) ? $m[1] : null;
    }

    public static function url(string $route = 'home'): string {
        return Url::plain($route);
    }

    private static function layout(string $title, string $body): string {
        $config = self::config();
        $brand = (string)$config['brand'];
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . ' | ' . e($brand) . '</title>'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700;800&display=swap" rel="stylesheet">'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">'
            . '<link href="' . asset('css/public.css') . '" rel="stylesheet"></head>'
            . '<body class="public-site"><main class="public-shell">'
            . '<nav class="public-nav"><a class="public-brand" href="' . e(self::url('home')) . '">'
            . '<i class="bi bi-flower1"></i> ' . e($brand) . '</a>'
            . '<a class="public-login-link" href="' . e(url('login')) . '">ورود پرسنل</a></nav>'
            . $body
            . '</main></body></html>';
    }

    /** Section entry points offered to staff, one per configured section. */
    private static function staffButtons(): string {
        if (!Tenant::isMulti()) return '';
        $out = '';
        foreach (Tenant::all() as $code => $tenant) {
            $out .= '<a class="public-staff-button" href="' . e(Url::sectionLogin($code)) . '">'
                . '<i class="bi bi-shield-lock"></i> ورود ' . e($tenant['label']) . '</a>';
        }
        return $out;
    }

    public static function home(): string {
        $config = self::config();
        $cards = '';
        foreach ($config['sections'] as $key => $section) {
            $cards .= '<a class="public-option public-option-' . e($key) . '" id="' . e($key) . '" href="' . e(self::url('public.' . $key)) . '">'
                . '<span class="public-option-icon"><i class="bi ' . e($section['icon']) . '"></i></span>'
                . '<h2>' . e($section['title']) . '</h2>'
                . '<p>' . e($section['summary']) . '</p>'
                . '<span class="public-option-more">مشاهده این بخش <i class="bi bi-arrow-left"></i></span>'
                . '</a>';
        }
        $staff = $config['staff'];
        $body = '<section class="public-hero"><div><p class="public-kicker">' . e($config['tagline']) . '</p>'
            . '<h1>' . e($config['hero']['title']) . '</h1>'
            . '<p>' . e($config['hero']['lead']) . '</p></div></section>'
            . '<section class="public-options" aria-label="بخش‌های اصلی">' . $cards . '</section>'
            . '<section class="public-staff"><div><p class="public-kicker">' . e($staff['kicker']) . '</p>'
            . '<h2>' . e($staff['title']) . '</h2><p>' . e($staff['lead']) . '</p></div>'
            . '<div class="public-staff-actions">'
            . (self::staffButtons() ?: '<a class="public-staff-button" href="' . e(url('login')) . '">'
                . '<i class="bi bi-shield-lock"></i> ' . e($staff['button']) . '</a>')
            . '</div></section>';
        return self::layout('صفحهٔ اصلی', $body);
    }

    public static function section(string $key): ?string {
        $config = self::config();
        if (!isset($config['sections'][$key])) return null;
        $section = $config['sections'][$key];
        $items = '';
        foreach ($section['items'] as $item) {
            $items .= '<li><i class="bi bi-check2"></i> ' . e($item) . '</li>';
        }
        $body = '<article class="public-article">'
            . '<a class="public-back" href="' . e(self::url('home')) . '"><i class="bi bi-arrow-right"></i> بازگشت به صفحهٔ اصلی</a>'
            . '<span class="public-article-icon"><i class="bi ' . e($section['icon']) . '"></i></span>'
            . '<h1>' . e($section['title']) . '</h1>'
            . '<p class="public-article-lead">' . e($section['intro']) . '</p>'
            . '<ul class="public-list">' . $items . '</ul>'
            . '<p class="public-note"><i class="bi bi-info-circle"></i> ' . e($section['note']) . '</p>'
            . '<div class="public-article-actions"><a class="public-login-link" href="' . e(self::url('home')) . '">صفحهٔ اصلی</a>'
            . (self::staffButtons() ?: '<a class="public-staff-button" href="' . e(url('login')) . '">'
                . '<i class="bi bi-shield-lock"></i> ' . e($config['staff']['button']) . '</a>')
            . '</div></article>';
        return self::layout($section['title'], $body);
    }
}
