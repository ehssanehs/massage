<?php
declare(strict_types=1);
namespace App\Support;

/**
 * The public (unauthenticated) site: landing page, the massage catalog and the
 * education page.
 *
 * Copy and the catalog come from SiteContent (its own connection, see there), so
 * an anonymous visitor never reads a section's CRM tables. Nothing here writes
 * to the database.
 */
final class PublicSite {
    private static function config(): array {
        static $config = null;
        return $config ??= require base_path('config/public-site.php');
    }

    /** Route names serving a public page, mapped to their section key. */
    public static function sectionKeyFor(string $route): ?string {
        if (!preg_match('/^public\.([a-z_]+)$/', $route, $m)) return null;
        // Only the two legacy public section aliases are recognised; the rest of
        // the site is served by dedicated routes (home/services/massage/education).
        return in_array($m[1], ['massage', 'education'], true) ? $m[1] : null;
    }

    public static function url(string $route = 'home', array $params = []): string {
        return Url::plain($route, $params);
    }

    /** Persian digits for anything shown to a visitor. */
    public static function num(int|string $value): string {
        return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$value);
    }

    private static function brand(): string {
        // Config default, overridable from the site panel. Deliberately not read
        // from the CRM settings table: the public site keeps its own connection.
        return SiteContent::block('site.brand', (string)self::config()['brand']);
    }

    /** Illustration path for a catalog entry; falls back to the generic mark. */
    private static function art(?string $slug): string {
        $known = self::config()['illustrations'];
        $slug = (string)$slug;
        if ($slug === '' || !in_array($slug, $known, true)) return asset('img/massage/generic.svg');
        return asset('img/massage/' . $slug . '.svg');
    }

    private static function navItems(): array {
        return [
            'home' => 'خانه',
            'massage-types' => 'انواع ماساژ',
            'education' => 'آموزش',
        ];
    }

    private static function header(string $active): string {
        $links = '';
        foreach (self::navItems() as $route => $label) {
            $current = $route === $active ? ' aria-current="page"' : '';
            $links .= '<a class="site-nav-link" href="' . e(self::url($route)) . '"' . $current . '>' . e($label) . '</a>';
        }
        $links .= '<a class="site-nav-link" href="' . e(self::url('home')) . '#centers">مراکز</a>';
        $links .= '<a class="site-nav-link" href="' . e(self::url('home')) . '#contact">تماس</a>';

        return '<a class="skip-link" href="#main">رفتن به محتوای اصلی</a>'
            . '<header class="site-header"><div class="site-header-inner">'
            . '<a class="site-wordmark" href="' . e(self::url('home')) . '"><span class="site-wordmark-mark" aria-hidden="true">'
            . self::archMark() . '</span>' . e(self::brand()) . '</a>'
            . '<nav class="site-nav" aria-label="ناوبری اصلی"><details class="site-nav-mobile"><summary>منو</summary><div class="site-nav-list">'
            . $links . '</div></details><div class="site-nav-wide">' . $links . '</div></nav>'
            . '<a class="site-staff-link" href="' . e(url('login')) . '">ورود پرسنل</a>'
            . '</div></header>';
    }

    /**
     * The identity motif: a pointed arch, repeated in the logo, section rules and
     * the catalog dividers. It ties the site to the bathhouse/spa architecture
     * the brand sits in, instead of a generic leaf or lotus.
     */
    public static function archMark(): string {
        return '<svg class="arch-mark" viewBox="0 0 24 28" width="24" height="28" aria-hidden="true" focusable="false">'
            . '<path d="M2 26V13C2 6.9 6.5 2 12 2s10 4.9 10 11v13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>'
            . '</svg>';
    }

    private static function footer(): string {
        $phone = SiteContent::block('contact.phone');
        $address = SiteContent::block('contact.address');
        $hours = SiteContent::block('contact.hours');
        $note = SiteContent::block('footer.note');
        $socials = '';
        foreach (['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'whatsapp' => 'واتساپ'] as $key => $label) {
            $href = SiteContent::block('social.' . $key);
            if ($href === '') continue;
            $socials .= '<a class="site-footer-link" href="' . e($href) . '" rel="noopener">' . e($label) . '</a>';
        }
        $rows = '';
        if ($phone !== '') $rows .= '<a class="site-footer-phone" href="tel:' . e(preg_replace('/[^0-9+]/', '', $phone)) . '">' . e($phone) . '</a>';
        if ($address !== '') $rows .= '<p class="site-footer-text">' . e($address) . '</p>';
        if ($hours !== '') $rows .= '<p class="site-footer-text">' . e($hours) . '</p>';

        return '<footer class="site-footer" id="contact"><div class="site-footer-inner">'
            . '<div class="site-footer-block"><p class="site-footer-title">' . e(self::brand()) . '</p>'
            . ($rows !== '' ? $rows : '<p class="site-footer-text">نشانی و شماره تماس را از پنل مدیریت سایت وارد کنید.</p>')
            . '</div>'
            . '<div class="site-footer-block"><p class="site-footer-title">دسترسی</p>'
            . '<a class="site-footer-link" href="' . e(self::url('massage-types')) . '">انواع ماساژ</a>'
            . '<a class="site-footer-link" href="' . e(self::url('home')) . '#centers">مراکز آقایان و بانوان</a>'
            . '<a class="site-footer-link" href="' . e(self::url('education')) . '">آموزش</a>'
            . $socials
            . '</div>'
            . '<div class="site-footer-block"><p class="site-footer-title">پرسنل</p>'
            . self::staffLinks()
            . '</div>'
            . '</div>'
            . ($note !== '' ? '<p class="site-footer-note">' . e($note) . '</p>' : '')
            . '</footer>';
    }

    /**
     * Staff entry points. In a two-section install each center keeps its own
     * login path, so the public site never mixes the two staff areas.
     */
    private static function staffLinks(): string {
        if (!Tenant::isMulti()) {
            return '<a class="site-footer-link" href="' . e(url('login')) . '">ورود به پنل</a>';
        }
        $out = '';
        foreach (Tenant::all() as $code => $tenant) {
            $out .= '<a class="site-footer-link" href="' . e(Url::sectionLogin($code)) . '">ورود ' . e($tenant['label']) . '</a>';
        }
        return $out;
    }

    private static function layout(string $title, string $body, string $active = 'home', string $description = ''): string {
        $config = self::config();
        $brand = self::brand();
        $desc = $description !== '' ? $description : $brand . '؛ ماساژ تخصصی آقایان و بانوان';
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . ' | ' . e($brand) . '</title>'
            . '<meta name="description" content="' . e($desc) . '">'
            . '<meta property="og:title" content="' . e($title) . '">'
            . '<meta property="og:description" content="' . e($desc) . '">'
            . '<meta property="og:type" content="website">'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">'
            . '<link href="' . asset('css/public.css') . '" rel="stylesheet"></head>'
            . '<body class="site">'
            . self::header($active)
            . '<main id="main" class="site-main">' . $body . '</main>'
            . self::footer()
            . '</body></html>';
    }

    /** One catalog entry as a card. */
    private static function card(array $type, bool $withSummary = true): string {
        $slug = (string)$type['slug'];
        $url = self::url('massage-type', ['slug' => $slug]);
        $meta = [];
        if (!empty($type['duration_minutes'])) $meta[] = self::num((int)$type['duration_minutes']) . ' دقیقه';
        if ($type['price_from'] !== null && (float)$type['price_from'] > 0) {
            $meta[] = 'از ' . self::num(number_format((float)$type['price_from'])) . ' تومان';
        } else {
            $meta[] = 'استعلام قیمت';
        }
        return '<a class="type-card" href="' . e($url) . '">'
            . '<span class="type-card-art"><img src="' . e(self::art($slug)) . '" alt="" width="240" height="180" loading="lazy"></span>'
            . '<span class="type-card-body"><span class="type-card-title">' . e($type['title']) . '</span>'
            . ($withSummary && !empty($type['summary']) ? '<span class="type-card-summary">' . e($type['summary']) . '</span>' : '')
            . '<span class="type-card-meta">' . e(implode(' · ', $meta)) . '</span></span></a>';
    }

    private static function sectionHead(string $title, string $lead = ''): string {
        return '<div class="section-head"><span class="section-arch" aria-hidden="true">' . self::archMark() . '</span>'
            . '<h2>' . e($title) . '</h2>'
            . ($lead !== '' ? '<p>' . e($lead) . '</p>' : '')
            . '</div>';
    }

    public static function home(): string {
        $heroTitle = SiteContent::block('home.hero.title', 'بدنی آرام، ذهنی رهاشده');
        $heroLead = SiteContent::block('home.hero.lead');
        $types = SiteContent::types();
        $featured = array_slice($types, 0, 6);

        $cards = '';
        foreach ($featured as $type) $cards .= self::card($type);

        $empty = $types === []
            ? '<p class="state-note">فهرست ماساژها هنوز وارد نشده است. مدیر سایت می‌تواند آن را از پنل «مدیریت سایت» اضافه کند.</p>'
            : '';

        $body = '<section class="hero">'
            . '<div class="hero-copy"><h1>' . e($heroTitle) . '</h1>'
            . ($heroLead !== '' ? '<p class="hero-lead">' . e($heroLead) . '</p>' : '')
            . '<div class="hero-actions">'
            . '<a class="btn-solid" href="' . e(self::url('massage-types')) . '">' . e(SiteContent::block('home.hero.secondary', 'دیدن انواع ماساژ')) . '</a>'
            . '<a class="btn-quiet" href="#centers">مراکز آقایان و بانوان</a>'
            . '</div></div>'
            . '<div class="hero-art"><img src="' . e(asset('img/massage/hero.svg')) . '" alt="" width="560" height="480" fetchpriority="high"></div>'
            . '</section>'
            . '<section class="centers" id="centers" aria-labelledby="centers-title">'
            . self::sectionHead(SiteContent::block('centers.title', 'دو مرکز، دو فضای مستقل'))
            . '<div class="center-grid">'
            . '<article class="center-card center-card-men"><h3>' . e(SiteContent::block('centers.men.title', 'مرکز ماساژ آقایان')) . '</h3>'
            . '<p>' . e(SiteContent::block('centers.men.body')) . '</p>'
            . '<p class="center-card-note">برای رزرو، با مرکز تماس بگیرید.</p></article>'
            . '<article class="center-card center-card-women"><h3>' . e(SiteContent::block('centers.women.title', 'مرکز ماساژ بانوان')) . '</h3>'
            . '<p>' . e(SiteContent::block('centers.women.body')) . '</p>'
            . '<p class="center-card-note">برای رزرو، با مرکز تماس بگیرید.</p></article>'
            . '</div></section>'
            . '<section class="types-preview" aria-labelledby="types-title">'
            . self::sectionHead(SiteContent::block('services.title', 'انواع ماساژ'), SiteContent::block('services.lead'))
            . $empty . '<div class="type-grid">' . $cards . '</div>'
            . ($types !== [] ? '<p class="section-more"><a class="link-strong" href="' . e(self::url('massage-types')) . '">همهٔ ' . self::num(count($types)) . ' نوع ماساژ</a></p>' : '')
            . '</section>'
            . '<section class="intro" aria-labelledby="intro-title">'
            . '<div class="intro-copy"><h2 id="intro-title">' . e(SiteContent::block('home.intro.title', 'چرا اینجا')) . '</h2>'
            . '<p>' . e(SiteContent::block('home.intro.body')) . '</p></div>'
            . '<div class="intro-art" aria-hidden="true">' . self::archPattern() . '</div>'
            . '</section>';

        return self::layout('صفحهٔ اصلی', $body, 'home', $heroLead !== '' ? $heroLead : '');
    }

    /** A repeating arch band, used as the one deliberate visual gesture on the page. */
    private static function archPattern(): string {
        $arches = '';
        for ($i = 0; $i < 5; $i++) {
            $arches .= '<path d="M' . ($i * 40) . ' 120V60c0-11 9-20 20-20s20 9 20 20v60" fill="none" stroke="currentColor" stroke-width="2"/>';
        }
        return '<svg viewBox="0 0 200 120" width="200" height="120">' . $arches . '</svg>';
    }

    public static function services(): string {
        $types = SiteContent::types();
        $cards = '';
        foreach ($types as $type) $cards .= self::card($type);

        $body = '<section class="page-head">'
            . '<h1>' . e(SiteContent::block('services.title', 'انواع ماساژ')) . '</h1>'
            . '<p>' . e(SiteContent::block('services.lead')) . '</p></section>'
            . ($types === []
                ? '<p class="state-note">هنوز نوع ماساژی ثبت نشده است. مدیر سایت می‌تواند آن را از پنل «مدیریت سایت» اضافه کند.</p>'
                : '<div class="type-grid type-grid-wide">' . $cards . '</div>');

        return self::layout('انواع ماساژ', $body, 'massage-types', SiteContent::block('services.lead'));
    }

    public static function type(string $slug): ?string {
        $type = SiteContent::type($slug);
        if ($type === null) return null;
        $meta = [];
        if (!empty($type['duration_minutes'])) $meta[] = 'مدت تقریبی: ' . self::num((int)$type['duration_minutes']) . ' دقیقه';
        if ($type['price_from'] !== null && (float)$type['price_from'] > 0) {
            $meta[] = 'شروع قیمت: ' . self::num(number_format((float)$type['price_from'])) . ' تومان';
        }
        $others = '';
        foreach (array_slice(SiteContent::types(), 0, 4) as $other) {
            if ($other['slug'] === $slug) continue;
            $others .= '<a class="chip" href="' . e(self::url('massage-type', ['slug' => (string)$other['slug']])) . '">' . e($other['title']) . '</a>';
        }
        $body = '<article class="type-page">'
            . '<a class="back-link" href="' . e(self::url('massage-types')) . '">بازگشت به فهرست ماساژها</a>'
            . '<div class="type-page-head">'
            . '<div class="type-page-art"><img src="' . e(self::art((string)$type['illustration'])) . '" alt="" width="360" height="270"></div>'
            . '<div><h1>' . e($type['title']) . '</h1>'
            . (!empty($type['summary']) ? '<p class="type-page-summary">' . e($type['summary']) . '</p>' : '')
            . ($meta !== [] ? '<p class="type-page-meta">' . e(implode(' · ', $meta)) . '</p>' : '')
            . '</div></div>'
            . (!empty($type['body']) ? '<div class="type-page-body">' . nl2br(e($type['body'])) . '</div>' : '')
            . '<div class="type-page-actions"><a class="btn-solid" href="' . e(self::url('home')) . '#contact">هماهنگی نوبت</a>'
            . '<a class="btn-quiet" href="' . e(self::url('massage-types')) . '">ماساژهای دیگر</a></div>'
            . ($others !== '' ? '<div class="chips"><span class="chips-label">همچنین ببینید:</span>' . $others . '</div>' : '')
            . '</article>';
        return self::layout((string)$type['title'], $body, 'massage-type', (string)($type['summary'] ?? ''));
    }

    public static function education(): string {
        $body = '<section class="page-head"><h1>' . e(SiteContent::block('education.title', 'بخش آموزش')) . '</h1>'
            . '<p>' . e(SiteContent::block('education.body')) . '</p></section>';
        return self::layout('آموزش', $body, 'education', SiteContent::block('education.body'));
    }

    /** Shown for an unknown catalog slug, with a way back instead of a dead end. */
    public static function notFound(): string {
        $body = '<section class="page-head"><h1>این صفحه پیدا نشد</h1>'
            . '<p>ممکن است نشانی اشتباه باشد یا این ماساژ از فهرست حذف شده باشد.</p>'
            . '<p class="section-more"><a class="btn-solid" href="' . e(self::url('massage-types')) . '">دیدن فهرست ماساژها</a></p></section>';
        return self::layout('صفحه پیدا نشد', $body, 'massage-types');
    }
}
