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
<!doctype html><html lang="fa" dir="rtl" data-bs-theme="<?=e($theme)?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($title)?> | <?=e($brand)?></title>
<link rel="icon" href="<?=e(self::setting('favicon_path','assets/img/favicon.png'))?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?=asset('css/app.css')?>" rel="stylesheet"><style>:root{--primary:<?=e($primary)?>;--secondary:<?=e($secondary)?>}</style>
</head><body class="app-shell">
<?php if($user): ?><aside class="sidebar"><div class="brand"><div class="brand-logo"><?php if($logo): ?><img src="<?=e($logo)?>" alt="logo"><?php else: ?><i class="bi bi-flower1"></i><?php endif;?></div><div><b><?=e($brand)?></b><small>CRM ماساژ</small></div></div><nav><?php foreach($nav as $item): if(!Auth::can($item['perm'])) continue; ?><a class="nav-link <?=($_GET['r']??'dashboard')===$item['route']?'active':''?>" href="<?=url($item['route'])?>"><i class="bi <?=e($item['icon'])?>"></i><span><?=e($item['label'])?></span></a><?php endforeach; ?></nav></aside>
<main class="main"><header class="topbar"><button class="btn btn-soft d-lg-none" data-toggle-sidebar><i class="bi bi-list"></i></button><div><h1><?=e($title)?></h1><span><?=Jalali::fa(date('Y/m/d H:i'))?></span></div><div class="top-actions"><button class="btn btn-soft" id="themeToggle"><i class="bi bi-moon-stars"></i></button><a class="btn btn-primary" href="<?=url('appointments.create')?>"><i class="bi bi-calendar-plus"></i> رزرو سریع</a><div class="dropdown"><button class="btn btn-soft dropdown-toggle" data-bs-toggle="dropdown"><?=e($user['name'])?></button><ul class="dropdown-menu"><li><span class="dropdown-item-text small text-muted"><?=e($user['role_name'])?></span></li><li><a class="dropdown-item" href="<?=url('settings')?>">تنظیمات</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="<?=url('logout')?>">خروج</a></li></ul></div></div></header><section class="content">
<?php if(!empty($_SESSION['toast'])): ?><div class="toast-container position-fixed top-0 start-0 p-3"><div class="toast show"><div class="toast-body"><?=e($_SESSION['toast']); unset($_SESSION['toast']);?></div></div></div><?php endif; ?>
<?= $content ?></section></main><?php else: ?><main class="auth-main"><?= $content ?></main><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script src="<?=asset('js/app.js')?>"></script></body></html><?php return (string)ob_get_clean();
    }
    public static function csrf(): string { return Security::csrfField(); }
}
