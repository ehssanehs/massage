<?php
declare(strict_types=1);
namespace App\Support;

use App\Services\Booking;
use App\Services\GiftCard;

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

    /**
     * Image for a catalog entry: the photo the owner uploaded (or the bundled
     * one), falling back to the line-art illustration and finally the generic
     * mark. Returning a pair keeps the <img> able to pick object-fit.
     */
    private static function art(array $type): array {
        $image = trim((string)($type['image'] ?? ''));
        if ($image !== '') {
            // Stored paths are relative to public/: "uploads/site/x.jpg" for an
            // upload, "assets/img/..." for a bundled photo. asset() adds the
            // /assets/ prefix itself, so strip a duplicate one.
            $url = str_starts_with($image, 'uploads/')
                ? Url::upload($image)
                : asset(str_starts_with($image, 'assets/') ? substr($image, 7) : $image);
            return [$url, true];
        }
        $known = self::config()['illustrations'];
        $slug = (string)($type['illustration'] ?? '');
        if ($slug === '' || !in_array($slug, $known, true)) return [asset('img/massage/generic.svg'), false];
        return [asset('img/massage/' . $slug . '.svg'), false];
    }

    private static function navItems(): array {
        return [
            'home' => 'خانه',
            'massage-types' => 'انواع ماساژ',
            'booking' => 'رزرو نوبت',
            'booking-status' => 'پیگیری نوبت',
            'gift' => 'کارت هدیه',
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

    /** Booking link for one center; in a single-section install both point to the same form. */
    private static function centerBookingLink(string $section): string {
        $params = Tenant::isMulti() ? ['section' => $section] : [];
        return '<a class="link-strong" href="' . e(self::url('booking', $params)) . '">رزرو نوبت آنلاین</a>';
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
        [$artUrl, $isPhoto] = self::art($type);
        return '<a class="type-card" href="' . e($url) . '">'
            . '<span class="type-card-art"><img class="' . ($isPhoto ? 'is-photo' : '') . '" src="' . e($artUrl) . '" alt="" width="240" height="180" loading="lazy"></span>'
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
            . '<a class="btn-solid" href="' . e(self::url('booking')) . '">' . e(SiteContent::block('home.hero.primary', 'رزرو نوبت آنلاین')) . '</a>'
            . '<a class="btn-quiet" href="' . e(self::url('massage-types')) . '">' . e(SiteContent::block('home.hero.secondary', 'دیدن انواع ماساژ')) . '</a>'
            . '</div></div>'
            . '<div class="hero-art"><img src="' . e(asset('img/massage/hero.svg')) . '" alt="" width="560" height="480" fetchpriority="high"></div>'
            . '</section>'
            . '<section class="centers" id="centers" aria-labelledby="centers-title">'
            . self::sectionHead(SiteContent::block('centers.title', 'دو مرکز، دو فضای مستقل'))
            . '<div class="center-grid">'
            . '<article class="center-card center-card-men"><h3>' . e(SiteContent::block('centers.men.title', 'مرکز ماساژ آقایان')) . '</h3>'
            . '<p>' . e(SiteContent::block('centers.men.body')) . '</p>'
            . '<p class="center-card-note">' . self::centerBookingLink('men') . '</p></article>'
            . '<article class="center-card center-card-women"><h3>' . e(SiteContent::block('centers.women.title', 'مرکز ماساژ بانوان')) . '</h3>'
            . '<p>' . e(SiteContent::block('centers.women.body')) . '</p>'
            . '<p class="center-card-note">' . self::centerBookingLink('women') . '</p></article>'
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
            . '<div class="type-page-art"><img class="' . (trim((string)($type['image'] ?? '')) !== '' ? 'is-photo' : '') . '" src="' . e(self::art($type)[0]) . '" alt="" width="360" height="270"></div>'
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

    /**
     * Online booking. Two steps without JavaScript: pick a service and a day,
     * then choose one of the free times and leave a name and a mobile.
     * Nothing here trusts the browser: Booking re-reads the schedule and
     * re-checks the slot when the form is submitted.
     */
    public static function booking(?string $section, array $query, ?array $result = null, bool $needsChoice = false): string {
        if ($needsChoice) {
            $cards = '';
            foreach (Booking::SECTIONS as $key => $label) {
                $cards .= '<a class="center-card center-card-' . e($key) . '" href="' . e(self::url('booking', ['section' => $key])) . '">'
                    . '<h3>' . e($label) . '</h3><p>زمان‌های خالی این مرکز را ببینید و نوبت بگیرید.</p>'
                    . '<span class="center-card-note">انتخاب این مرکز</span></a>';
            }
            $body = '<section class="page-head"><h1>' . e(SiteContent::block('booking.title', 'رزرو نوبت آنلاین')) . '</h1>'
                . '<p>' . e(SiteContent::block('booking.lead')) . '</p></section>'
                . '<div class="center-grid">' . $cards . '</div>';
            return self::layout('رزرو نوبت', $body, 'booking', SiteContent::block('booking.lead'));
        }

        $services = Booking::services($section);
        $serviceId = (int)($query['service'] ?? 0);
        $date = (string)($query['date'] ?? '');
        $openDays = Booking::days($section, 7);
        // Only days that actually have a free time for the chosen treatment, so
        // a guest cannot pick a day that turns out to be empty.
        $availableDays = $serviceId > 0 ? Booking::availableDays($section, $serviceId, 12) : [];
        $slots = ($serviceId > 0 && isset($availableDays[$date])) ? Booking::slots($section, $serviceId, $date) : [];

        $serviceOptions = '';
        foreach ($services as $service) {
            $selected = (int)$service['id'] === $serviceId ? ' selected' : '';
            $serviceOptions .= '<option value="' . (int)$service['id'] . '"' . $selected . '>'
                . e($service['name']) . ' — ' . self::num((int)$service['duration_minutes']) . ' دقیقه'
                . ((float)$service['default_price'] > 0 ? ' (' . self::num(number_format((float)$service['default_price'])) . ' تومان)' : '')
                . '</option>';
        }

        $sectionField = $section !== null ? '<input type="hidden" name="section" value="' . e($section) . '">' : '';
        $pickForm = '<form class="booking-pick" method="get" action="' . e(self::url('booking')) . '">'
            . '<input type="hidden" name="r" value="booking">' . $sectionField
            . '<div class="field"><label for="bk-service">خدمت</label>'
            . '<select id="bk-service" name="service" required>' . ($serviceId === 0 ? '<option value="">انتخاب کنید</option>' : '') . $serviceOptions . '</select></div>'
            . '<button class="btn-quiet" type="submit">' . ($serviceId === 0 ? 'نمایش روزهای خالی' : 'تغییر خدمت') . '</button></form>';

        $dayForm = '';
        if ($serviceId > 0) {
            if ($availableDays === []) {
                $dayForm = '<p class="state-note">برای این خدمت در روزهای آینده زمان خالی نیست. خدمت دیگری را انتخاب کنید یا تلفنی هماهنگ کنید.</p>';
            } else {
                $dayOptions = '';
                foreach ($availableDays as $value => $info) {
                    $selected = $value === $date ? ' selected' : '';
                    $dayOptions .= '<option value="' . e($value) . '"' . $selected . '>' . e($info['label'])
                        . ' — ' . self::num($info['slots']) . ' زمان خالی</option>';
                }
                $dayForm = '<form class="booking-pick" method="get" action="' . e(self::url('booking')) . '">'
                    . '<input type="hidden" name="r" value="booking">' . $sectionField
                    . '<input type="hidden" name="service" value="' . $serviceId . '">'
                    . '<div class="field"><label for="bk-date">روز (فقط روزهای دارای زمان خالی)</label>'
                    . '<select id="bk-date" name="date" required>'
                    . (!isset($availableDays[$date]) ? '<option value="">انتخاب کنید</option>' : '') . $dayOptions
                    . '</select></div>'
                    . '<button class="btn-quiet" type="submit">نمایش ساعت‌های خالی</button></form>';
            }
        }

        $notice = '';
        if ($result !== null && $result['ok']) {
            $appointment = $result['appointment'];
            $notice = '<div class="booking-done" role="status"><h2>نوبت شما ثبت شد</h2>'
                . '<p>' . e($appointment['service']) . ' — ' . e(Booking::dayLabel($appointment['date'])) . ' ساعت ' . self::num($appointment['time']) . '</p>'
                . '<p class="booking-ref">کد پیگیری: <strong dir="ltr">' . e((string)$result['ref']) . '</strong></p>'
                . '<div class="code-warning" role="alert"><strong>این کد را حتماً یادداشت کنید.</strong> برای دیدن وضعیت و لغو نوبت، تنها راه شما همین کد پیگیری است.</div>'
                . '<p class="center-card-note">نوبت پس از تأیید مرکز قطعی می‌شود. وضعیت را با همین کد می‌توانید ببینید.</p>'
                . '<p><a class="link-strong" href="' . e(self::url('booking-status', ['ref' => (string)$result['ref']])) . '">مشاهدهٔ وضعیت نوبت</a></p></div>';
        } elseif ($result !== null && $result['errors'] !== []) {
            $items = '';
            foreach ($result['errors'] as $error) $items .= '<li>' . e($error) . '</li>';
            $notice = '<div class="booking-error" role="alert"><ul>' . $items . '</ul></div>';
        }

        $slotBlock = '';
        if ($services === []) {
            $slotBlock = '<p class="state-note">هنوز خدمتی برای رزرو آنلاین ثبت نشده است. لطفاً تلفنی هماهنگ کنید.</p>';
        } elseif ($openDays === []) {
            $slotBlock = '<p class="state-note">برنامهٔ کاری مرکز هنوز ثبت نشده است؛ فعلاً رزرو آنلاین ممکن نیست. لطفاً تلفنی هماهنگ کنید.</p>';
        } elseif ($serviceId > 0 && $date !== '') {
            if ($slots === []) {
                $slotBlock = '<p class="state-note">برای این روز زمان خالی نمانده است؛ روز دیگری را امتحان کنید.</p>';
            } else {
                $times = '';
                foreach ($slots as $slot) {
                    $checked = (string)($query['time'] ?? '') === $slot['time'] ? ' checked' : '';
                    $times .= '<label class="slot"><input type="radio" name="time" value="' . e($slot['time']) . '" required' . $checked . '>'
                        . '<span>' . e($slot['label']) . '</span></label>';
                }
                $slotBlock = '<form class="booking-form" method="post" action="' . e(self::url('booking')) . '">'
                    . View::csrf()
                    . '<input type="hidden" name="service_id" value="' . $serviceId . '">'
                    . '<input type="hidden" name="date" value="' . e($date) . '">'
                    . $sectionField
                    . '<fieldset class="slots"><legend>ساعت خالی (' . self::num(count($slots)) . ' زمان)</legend>' . $times . '</fieldset>'
                    . '<div class="field"><label for="bk-name">نام و نام خانوادگی</label><input id="bk-name" name="name" required minlength="3" autocomplete="name"></div>'
                    . '<div class="field"><label for="bk-mobile">موبایل</label><input id="bk-mobile" name="mobile" required inputmode="numeric" pattern="09[0-9]{9}" placeholder="09xxxxxxxxx" dir="ltr" autocomplete="tel"></div>'
                    . '<div class="field"><label for="bk-note">توضیح (اختیاری)</label><textarea id="bk-note" name="note" rows="2" maxlength="300"></textarea></div>'
                    . '<button class="btn-solid" type="submit">ثبت نوبت</button>'
                    . '<p class="form-note">نوبت با وضعیت «در انتظار تأیید» ثبت می‌شود و مرکز آن را تأیید می‌کند.</p>'
                    . '</form>';
            }
        }

        $status = '';
        if (isset($query['ref'])) {
            $found = Booking::findByRef($section, (string)$query['ref']);
            $status = $found === null
                ? '<div class="state-note">نوبتی با این کد پیدا نشد.</div>'
                : '<div class="booking-done"><h2>وضعیت نوبت</h2><p>' . e($found['service']) . ' — ' . e(Booking::dayLabel((string)$found['appointment_date'])) . ' ساعت ' . self::num(substr((string)$found['start_time'], 0, 5)) . '</p>'
                  . '<p>وضعیت: ' . e(self::statusLabel((string)$found['status'])) . '</p></div>';
        }

        $body = '<section class="page-head"><h1>' . e(SiteContent::block('booking.title', 'رزرو نوبت آنلاین')) . '</h1>'
            . '<p>' . e(SiteContent::block('booking.lead')) . '</p>'
            . ($section !== null ? '<p class="page-head-note">' . e(Booking::sectionLabel($section)) . '</p>' : '')
            . '<p class="page-head-note">قبلاً نوبت گرفته‌اید؟ <a class="link-strong" href="' . e(self::url('booking-status')) . '">وضعیت نوبت را با کد پیگیری ببینید</a></p>'
            . '</section>'
            . $notice . $status . $pickForm . $dayForm . $slotBlock;
        return self::layout('رزرو نوبت', $body, 'booking', SiteContent::block('booking.lead'));
    }

    /**
     * Track a booking by its reference. The guest only knows the code, so the
     * lookup checks every centre rather than asking them to remember which one.
     */
    public static function bookingStatus(string $reference, ?array $found = null, bool $looked = false, bool $cancelled = false, string $cancelError = ''): string {
        $field = '<form class="booking-form booking-track" method="get" action="' . e(self::url('booking-status')) . '">'
            . '<input type="hidden" name="r" value="booking-status">'
            . '<div class="field"><label for="track-ref">کد پیگیری نوبت</label>'
            . '<input id="track-ref" name="ref" required maxlength="12" dir="ltr" placeholder="مثلاً 80192C" '
            . 'autocomplete="off" spellcheck="false" value="' . e(strtoupper($reference)) . '"></div>'
            . '<button class="btn-solid" type="submit">مشاهدهٔ وضعیت</button>'
            . '<p class="form-note">این کد را پس از ثبت نوبت دریافت کرده‌اید.</p></form>';

        $result = '';
        if ($cancelled) {
            $result = '<div class="booking-done" role="status"><h2>نوبت لغو شد</h2>'
                . '<p>این نوبت از فهرست مرکز حذف شد. اگر زمان دیگری می‌خواهید، نوبت تازه بگیرید.</p>'
                . '<p><a class="link-strong" href="' . e(self::url('booking')) . '">رزرو نوبت جدید</a></p></div>';
        } elseif ($cancelError !== '') {
            $result = '<div class="booking-error" role="alert"><ul><li>' . e($cancelError) . '</li></ul></div>';
        } elseif ($looked) {
            if ($found === null) {
                $result = '<div class="booking-error" role="alert"><ul><li>نوبتی با این کد پیدا نشد. کد را بررسی کنید یا با مرکز تماس بگیرید.</li></ul></div>';
            } else {
                $when = Booking::dayLabel((string)$found['appointment_date']) . ' ساعت ' . self::num(substr((string)$found['start_time'], 0, 5));
                $result = '<div class="booking-done"><h2>وضعیت نوبت</h2>'
                    . '<p class="booking-ref">کد: <strong dir="ltr">' . e((string)$found['booking_ref']) . '</strong></p>'
                    . '<p>' . e((string)$found['service']) . ' — ' . e($when) . '</p>'
                    . '<p>وضعیت: <strong>' . e(self::statusLabel((string)$found['status'])) . '</strong></p>'
                    . (!empty($found['section']) ? '<p class="center-card-note">' . e(Booking::sectionLabel((string)$found['section'])) . '</p>' : '')
                    . ($found['status'] === 'pending'
                        ? '<details class="cancel-box"><summary>لغو این نوبت</summary>'
                          . '<p class="form-note">لغو فقط تا قبل از تأیید مرکز ممکن است و قابل بازگشت نیست.</p>'
                          . '<form method="post" action="' . e(self::url('booking-status', ['ref' => (string)$found['booking_ref']])) . '">'
                          . View::csrf()
                          . '<input type="hidden" name="ref" value="' . e((string)$found['booking_ref']) . '">'
                          . '<input type="hidden" name="action" value="cancel">'
                          . '<button class="btn-quiet" type="submit">بله، این نوبت را لغو کن</button>'
                          . '</form></details>'
                        : '<p class="center-card-note">این نوبت تأیید شده است؛ برای تغییر یا لغو، با مرکز تماس بگیرید.</p>')
                    . '</div>';
            }
        }

        $body = '<section class="page-head"><h1>پیگیری نوبت</h1>'
            . '<p>کد پیگیری‌ای که هنگام ثبت نوبت گرفتید را وارد کنید تا وضعیت آن را ببینید.</p></section>'
            . $result . $field;
        return self::layout('پیگیری نوبت', $body, 'booking-status');
    }

    public static function statusLabel(string $status): string {
        return [
            'pending' => 'در انتظار تأیید', 'confirmed' => 'تأیید شده', 'arrived' => 'حاضر شده',
            'in_progress' => 'در حال انجام', 'completed' => 'انجام شده', 'cancelled' => 'لغو شده', 'no_show' => 'عدم مراجعه',
        ][$status] ?? $status;
    }

    /** The gift-card page: presets from the panel, no form until the gateway is wired. */
    public static function gift(?array $result = null): string {
        $enabled = GiftCard::enabled();
        $notice = '';
        if ($result !== null && ($result['errors'] ?? []) !== []) {
            $items = '';
            foreach ($result['errors'] as $error) $items .= '<li>' . e($error) . '</li>';
            $notice = '<div class="booking-error" role="alert"><ul>' . $items . '</ul></div>';
        }

        $form = '';
        if ($enabled) {
            $presets = '';
            foreach (GiftCard::presets() as $index => $amount) {
                $presets .= '<label class="slot"><input type="radio" name="amount" value="' . $amount . '"' . ($index === 1 ? ' checked' : '') . ' required>'
                    . '<span>' . self::num(number_format($amount)) . ' ریال</span></label>';
            }
            $form = '<form class="booking-form gift-form" method="post" action="' . e(self::url('gift')) . '">'
                . View::csrf()
                . '<fieldset class="slots"><legend>مبلغ کارت هدیه</legend>' . $presets . '</fieldset>'
                . '<div class="field"><label for="g-name">نام خریدار</label><input id="g-name" name="buyer_name" required minlength="3" autocomplete="name"></div>'
                . '<div class="field"><label for="g-mobile">موبایل خریدار</label><input id="g-mobile" name="buyer_mobile" required inputmode="numeric" pattern="09[0-9]{9}" dir="ltr" placeholder="09xxxxxxxxx" autocomplete="tel"></div>'
                . '<div class="field"><label for="g-recipient">نام گیرنده (اختیاری)</label><input id="g-recipient" name="recipient_name"></div>'
                . '<div class="field"><label for="g-recipient-mobile">موبایل گیرنده (اختیاری)</label><input id="g-recipient-mobile" name="recipient_mobile" inputmode="numeric" pattern="09[0-9]{9}" dir="ltr" placeholder="09xxxxxxxxx"></div>'
                . '<div class="field"><label for="g-message">پیام روی کارت (اختیاری)</label><textarea id="g-message" name="message" rows="2" maxlength="200"></textarea></div>'
                . '<button class="btn-solid" type="submit">پرداخت و دریافت کارت</button>'
                . '<p class="form-note">پس از پرداخت موفق، کد کارت هدیه نمایش داده می‌شود و اعتبار آن یک سال است.</p>'
                . '</form>';
        } else {
            $form = '<p class="state-note">فروش آنلاین کارت هدیه هنوز فعال نشده است. برای خرید، با مرکز تماس بگیرید.</p>';
        }

        $body = '<section class="page-head"><h1>' . e(SiteContent::block('gift.title', 'کارت هدیه ماساژ')) . '</h1>'
            . '<p>' . e(SiteContent::block('gift.lead')) . '</p></section>' . $notice . $form;
        return self::layout('کارت هدیه', $body, 'gift', SiteContent::block('gift.lead'));
    }

    /** What the buyer sees after the gateway sends them back. */
    public static function paymentResult(bool $ok, ?array $card = null, string $error = '', string $code = ''): string {
        if ($ok && $card !== null) {
            $body = '<section class="page-head"><h1>کارت هدیهٔ شما آماده است</h1>'
                . '<p>پرداخت با موفقیت انجام شد.</p></section>'
                . '<div class="booking-done"><p class="booking-ref">کد کارت هدیه: <strong dir="ltr">' . e((string)$card['code']) . '</strong></p>'
                . '<p>مبلغ: ' . self::num(number_format((float)$card['amount'])) . ' ریال</p>'
                . (($card['recipient_name'] ?? null) ? '<p>گیرنده: ' . e((string)$card['recipient_name']) . '</p>' : '')
                . '<p class="center-card-note">این کد را نگه دارید؛ هنگام مراجعه همان را اعلام کنید. اعتبار کارت یک سال است.</p>'
                . '<p><a class="link-strong" href="' . e(self::url('home')) . '">بازگشت به صفحهٔ اصلی</a></p></div>';
            return self::layout('کارت هدیه', $body, 'gift');
        }
        $body = '<section class="page-head"><h1>پرداخت انجام نشد</h1>'
            . '<p>' . e($error !== '' ? $error : 'پرداخت تأیید نشد یا لغو شد.') . '</p>'
            . (!$ok && $code !== '' ? '<p class="state-note">کد سفارش: <span dir="ltr">' . e($code) . '</span></p>' : '')
            . '<p class="section-more"><a class="btn-quiet" href="' . e(self::url('gift')) . '">تلاش دوباره</a></p></section>';
        return self::layout('پرداخت ناموفق', $body, 'gift');
    }

    /** Shown for an unknown catalog slug, with a way back instead of a dead end. */
    public static function notFound(): string {
        $body = '<section class="page-head"><h1>این صفحه پیدا نشد</h1>'
            . '<p>ممکن است نشانی اشتباه باشد یا این ماساژ از فهرست حذف شده باشد.</p>'
            . '<p class="section-more"><a class="btn-solid" href="' . e(self::url('massage-types')) . '">دیدن فهرست ماساژها</a></p></section>';
        return self::layout('صفحه پیدا نشد', $body, 'massage-types');
    }
}
