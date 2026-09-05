<?php
namespace App\Support;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Security;

final class View {
    public static function setting(string $key, mixed $default=''): mixed { return DB::value('SELECT value FROM settings WHERE `key`=?', [$key]) ?? $default; }
    public static function render(string $title, string $content, array $opts=[]): string {
        $user = Auth::user(); $brand = self::setting('brand_name', env_value('APP_NAME','مرکز ماساژ آرامش'));
        $primary = self::setting('primary_color','#7c3aed'); $secondary=self::setting('secondary_color','#14b8a6');
        $logo = self::setting('logo_path',''); $theme = $_COOKIE['theme'] ?? self::setting('default_theme','light');
        $nav = require base_path('config/nav.php');
        ob_start(); ?>
<!doctype html><html lang="fa" dir="rtl" data-bs-theme="<?=e($theme)?>" data-timezone="<?=e(date_default_timezone_get())?>" data-today="<?=e(Jalali::toJalali(date('Y-m-d')))?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($title)?> | <?=e($brand)?></title>
<link rel="icon" href="<?=e(self::setting('favicon_path','assets/img/favicon.png'))?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?=asset('css/jalalidatepicker.css')?>" rel="stylesheet">
<link href="<?=asset('css/timepicker.css')?>" rel="stylesheet">
<link href="<?=asset('css/app.css')?>" rel="stylesheet"><style>:root{--primary:<?=e($primary)?>;--secondary:<?=e($secondary)?>}</style>
</head><body class="app-shell">
<?php if($user): $currentRoute = (string)($_GET['r'] ?? 'dashboard'); ?><aside class="sidebar"><div class="brand"><div class="brand-logo"><?php if($logo): ?><img src="<?=e($logo)?>" alt="logo"><?php else: ?><i class="bi bi-flower1"></i><?php endif;?></div><div><b><?=e($brand)?></b><small>CRM ماساژ</small></div></div><nav><?php foreach($nav as $item): if(!Auth::can($item['perm'])) continue; $isActive = $currentRoute === $item['route'] || str_starts_with($currentRoute, $item['route'] . '.'); ?><a class="nav-link <?=$isActive?'active':''?>" href="<?=url($item['route'])?>"><i class="bi <?=e($item['icon'])?>"></i><span><?=e($item['label'])?></span></a><?php endforeach; ?></nav></aside>
<main class="main"><header class="topbar"><button class="btn btn-soft d-lg-none" data-toggle-sidebar><i class="bi bi-list"></i></button><div><h1><?=e($title)?></h1><span class="date-time"><?=e(Jalali::dateTime(date('Y-m-d H:i:s')))?></span></div><div class="top-actions"><button class="btn btn-soft" id="themeToggle" title="تغییر تم"><i class="bi <?=($theme==='dark'?'bi-sun':'bi-moon-stars')?>"></i></button><a class="btn btn-primary" href="<?=url('appointments.create')?>"><i class="bi bi-calendar-plus"></i> رزرو سریع</a><div class="dropdown"><button class="btn btn-soft dropdown-toggle" data-bs-toggle="dropdown"><?=e($user['name'])?></button><ul class="dropdown-menu"><li><span class="dropdown-item-text small text-muted"><?=e($user['role_name'])?></span></li><li><a class="dropdown-item" href="<?=url('settings')?>"><i class="bi bi-gear me-1"></i>تنظیمات</a></li><li><a class="dropdown-item" href="<?=url('profile.password')?>"><i class="bi bi-key me-1"></i>تغییر رمز عبور</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="<?=url('logout')?>"><i class="bi bi-box-arrow-left me-1"></i>خروج</a></li></ul></div></div></header><section class="content">
<?php if(!empty($_SESSION['toast'])): ?><div class="toast-container position-fixed top-0 start-0 p-3"><div class="toast show"><div class="toast-body"><?=e($_SESSION['toast']); unset($_SESSION['toast']);?></div></div></div><?php endif; ?>
<?= $content ?></section></main><?php else: ?><main class="auth-main"><?= $content ?></main><?php endif; ?>
<script src="<?=asset('js/jalalidatepicker.js')?>"></script>
<script src="<?=asset('js/timepicker.js')?>"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script src="<?=asset('js/app.js')?>"></script></body></html><?php return (string)ob_get_clean();
    }
    /** $value is already Jalali user-facing text; never reinterpret a failed POST as Gregorian. */
    public static function dateInput(string $name, ?string $value = null, bool $required = false, ?string $id = null): string {
        $id ??= 'f_' . $name;
        $date = Jalali::toGregorian($value);
        $value = $date !== null ? Jalali::toJalali($date) : (string)$value;
        return '<div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3" aria-hidden="true"></i></span>'
            . '<input id="' . e($id) . '" type="text" name="' . e($name) . '" value="' . e($value)
            . '" class="form-control jalali-input" data-jdp dir="ltr" placeholder="سال/ماه/روز"'
            . ' title="تاریخ شمسی (جلالی)" autocomplete="off" inputmode="numeric"' . ($required ? ' required' : '') . '></div>';
    }

    /** One named input, usable without JS; the local picker is progressive enhancement. */
    public static function timeInput(string $name, ?string $value = null, bool $required = false, ?string $id = null, string $label = 'ساعت'): string {
        $id ??= 'f_' . $name;
        return '<div class="time-field"><div class="time-input-group">'
            . '<input id="' . e($id) . '" type="text" name="' . e($name) . '" value="' . e(ClockTime::display($value))
            . '" class="form-control time-input" data-time-input dir="ltr" inputmode="numeric" placeholder="۰۹:۳۰"'
            . ' autocomplete="off" spellcheck="false" aria-describedby="' . e($id . '_hint ' . $id . '_error') . '"' . ($required ? ' required' : '') . '>'
            . '<button type="button" class="btn btn-soft time-toggle" data-time-toggle hidden aria-label="' . e('انتخاب ' . $label)
            . '" aria-haspopup="dialog" aria-controls="time-picker-panel" aria-expanded="false">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> انتخاب</button></div>'
            . '<div id="' . e($id . '_hint') . '" class="form-text time-hint">۲۴ساعته؛ برای <bdi dir="ltr">۰۹:۳۰</bdi> می‌توانید <bdi dir="ltr">۹۳۰</bdi> بنویسید.</div>'
            . '<div id="' . e($id . '_error') . '" class="time-input-error" data-time-error hidden aria-live="polite"></div></div>';
    }

    public static function csrf(): string { return Security::csrfField(); }
}
