<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth; use App\Core\DB; use App\Core\Security; use App\Support\View; use App\Support\Jalali; use App\Services\Audit; use App\Services\FollowUpService; use App\Services\SalaryService; use App\Services\RetentionService; use App\Services\BackupService;

Security::verifyCsrf();
$modules = require base_path('config/modules.php');
$route = $_GET['r'] ?? 'dashboard';

function toast(string $msg): void { $_SESSION['toast']=$msg; }
function money($n): string { return Jalali::fa(number_format((float)$n)) . ' ریال'; }
function status_badge(?string $s): string { $map=['active'=>'success','vip'=>'warning','new'=>'info','inactive'=>'secondary','at_risk'=>'danger','lost'=>'dark','pending'=>'warning','confirmed'=>'info','completed'=>'success','cancelled'=>'danger','no_show'=>'dark','paid'=>'success','partial'=>'warning','unpaid'=>'danger']; return '<span class="badge text-bg-'.($map[$s]??'secondary').'">'.e(t($s ?? '', $s ?? '')).'</span>'; }
function module_perm(string $module, string $action='view'): string { return $module . '.' . ($action==='manage'?'manage':$action); }
function can_module(string $module, string $action='view'): void { global $modules; Auth::requireCan(($modules[$module]['perm'] ?? $module).'.'.($action==='view'?'view':'manage')); }

function field_value(string $type, mixed $value): string { if(str_contains($type,'date') && $value) return Jalali::toJalali((string)$value); return (string)($value ?? ''); }
function normalize_post(array $fields): array { $data=[]; foreach($fields as $name=>$f){ if(!array_key_exists($name,$_POST)) continue; $type=$f[1]; $val=$_POST[$name]; if($type==='date') $val=Jalali::toGregorian((string)$val); elseif($type==='number') $val=(Jalali::en((string)$val)==='' ? 0 : Jalali::en((string)$val)); elseif($type==='services_multi') $val=json_encode($val ?: [], JSON_UNESCAPED_UNICODE); elseif(is_string($val)) $val=Security::cleanString(Jalali::en($val)); $data[$name]=$val; } return $data; }
function validate_required(array $fields, array $data): array { $err=[]; foreach($fields as $n=>$f){ if(in_array('required',$f,true) && (($data[$n]??'')==='' || ($data[$n]??null)===null)) $err[]=$f[0].' الزامی است.'; } return $err; }

function options(string $type): array { return match($type){
 'customer'=>DB::select("SELECT id, CONCAT(first_name,' ',last_name,' - ',mobile) label FROM customers WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500"),
 'therapist'=>DB::select("SELECT id, CONCAT(name,' (',code,')') label FROM therapists WHERE status='active' ORDER BY name"),
 'service'=>DB::select("SELECT id, name label FROM services WHERE status='active' ORDER BY name"),
 'appointment'=>DB::select("SELECT id, CONCAT(appointment_date,' ',start_time,' #',id) label FROM appointments ORDER BY appointment_date DESC LIMIT 500"),
 default=>[]}; }
function can_module(string $module, string $action='view'): void { 
    global $modules; 
    Auth::requireCan(($modules[$module]['perm'] ?? $module) . '.' . ($action === 'view' ? 'view' : 'manage')); 
}
function list_sql(string $module, array $def, string $where, array $params): array { $table=$def['table']; $select="$table.*"; $join=''; if(in_array($module,['appointments','sessions'],true)){ $select.=" , CONCAT(c.first_name,' ',c.last_name) customer_name, t.name therapist_name, s.name service_name"; $join=" LEFT JOIN customers c ON c.id=$table.customer_id LEFT JOIN therapists t ON t.id=$table.therapist_id LEFT JOIN services s ON s.id=$table.service_id"; } if($module==='packages'){ $select.=" , CONCAT(c.first_name,' ',c.last_name) customer_name"; $join=" LEFT JOIN customers c ON c.id=$table.customer_id"; } if($module==='customers'){ $select.=" , CONCAT(first_name,' ',last_name) full_name, (SELECT MAX(massage_date) FROM massage_sessions ms WHERE ms.customer_id=customers.id AND status='completed') last_visit, (SELECT COALESCE(SUM(final_amount),0) FROM massage_sessions ms WHERE ms.customer_id=customers.id AND status='completed') total_spent"; }
 $sql="SELECT $select FROM $table $join WHERE $where ORDER BY $table.id DESC"; return [$sql,$params]; }
function render_module_list(string $module): string { global $modules; $def=$modules[$module]; can_module($module); $table=$def['table']; $q=Security::cleanString($_GET['q'] ?? null); $page=max(1,(int)($_GET['page']??1)); $per=20; $where="$table.deleted_at IS NULL"; $params=[]; if($q){$ors=[]; foreach($def['search'] as $c){$ors[]="$table.$c LIKE ?"; $params[]="%$q%";} $where.=' AND ('.implode(' OR ',$ors).')';}
 [$sql,$params]=list_sql($module,$def,$where,$params); $total=(int)DB::value("SELECT COUNT(*) FROM $table WHERE $where", $params); $rows=DB::select($sql." LIMIT $per OFFSET ".(($page-1)*$per), $params); ob_start(); ?>
<div class="card p-3"><div class="d-flex flex-wrap gap-2 justify-content-between mb-3"><form class="d-flex gap-2"><input type="hidden" name="r" value="<?=e($module)?>"><input name="q" value="<?=e($q)?>" class="form-control" placeholder="جستجو..."><button class="btn btn-soft">جستجو</button></form><?php if(Auth::can($def['perm'].'.manage')): ?><a class="btn btn-primary" href="<?=url($module.'.create')?>"><i class="bi bi-plus-lg"></i> افزودن</a><?php endif;?></div><div class="table-responsive"><table class="table align-middle"><thead><tr><?php foreach($def['columns'] as $c=>$l): ?><th><?=e($l)?></th><?php endforeach;?><th>عملیات</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><?php foreach($def['columns'] as $c=>$l): ?><td><?php $v=$row[$c]??''; echo str_contains($c,'date')||str_contains($c,'visit')||str_ends_with($c,'_at')?e(Jalali::toJalali($v)): (str_contains($c,'amount')||str_contains($c,'price')||str_contains($c,'spent')?money($v):(str_contains($c,'status')||$c==='segment'?status_badge($v):e($v))); ?></td><?php endforeach; ?><td class="text-nowrap"><a class="btn btn-sm btn-soft" href="<?=url($module.'.show',['id'=>$row['id']])?>">نمایش</a><?php if(Auth::can($def['perm'].'.manage')): ?> <a class="btn btn-sm btn-outline-primary" href="<?=url($module.'.edit',['id'=>$row['id']])?>">ویرایش</a> <form method="post" action="<?=url($module.'.delete',['id'=>$row['id']])?>" class="d-inline" onsubmit="return confirm('حذف شود؟')"><?=View::csrf()?><button class="btn btn-sm btn-outline-danger">حذف</button></form><?php endif;?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="20" class="empty">رکوردی یافت نشد.</td></tr><?php endif;?></tbody></table></div><div class="small text-muted">تعداد: <?=Jalali::fa($total)?></div></div><?php return (string)ob_get_clean(); }
function module_form(string $module, ?int $id=null, array $errors=[]): string { global $modules; $def=$modules[$module]; $row=$id?DB::row('SELECT * FROM '.$def['table'].' WHERE id=?',[$id]):[]; ob_start(); if($errors) echo '<div class="alert alert-danger">'.implode('<br>',array_map('e',$errors)).'</div>'; ?><form method="post" class="card p-4"><?=View::csrf()?><div class="row g-3"><?php foreach($def['fields'] as $n=>$f): ?><div class="col-md-6 <?=($f[1]==='textarea'||$f[1]==='services_multi')?'col-lg-12':''?>"><?=input_html($n,$f,$row[$n] ?? null)?></div><?php endforeach;?></div><div class="mt-4 d-flex gap-2"><button class="btn btn-primary">ذخیره</button><a class="btn btn-soft" href="<?=url($module)?>">انصراف</a></div></form><?php return (string)ob_get_clean(); }
function handle_module(string $module, string $action): void { global $modules; $def=$modules[$module]; $table=$def['table']; if($action==='index') echo View::render($def['title'], render_module_list($module)); elseif($action==='create'){ can_module($module,'manage'); if($_SERVER['REQUEST_METHOD']==='POST'){ $data=normalize_post($def['fields']); $errors=validate_required($def['fields'],$data); if($module==='appointments') $errors=array_merge($errors, check_double_booking($data)); if(!$errors){ if($module==='customers') {$data['customer_code']='C'.date('ymd').random_int(100,999); $data['registration_date']=date('Y-m-d');} $data['created_by']=Auth::id(); $data['created_at']=date('Y-m-d H:i:s'); $id=DB::insert($table,$data); after_save($module,$id,$data,true); Audit::log($module.'.create',$table,$id); toast('رکورد با موفقیت ایجاد شد.'); redirect($module.'.show',['id'=>$id]); } echo View::render('افزودن '.$def['title'], module_form($module,null,$errors)); } else echo View::render('افزودن '.$def['title'], module_form($module)); } elseif($action==='edit'){ can_module($module,'manage'); $id=(int)$_GET['id']; if($_SERVER['REQUEST_METHOD']==='POST'){ $data=normalize_post($def['fields']); $errors=validate_required($def['fields'],$data); if($module==='appointments') $errors=array_merge($errors, check_double_booking($data,$id)); if(!$errors){ $data['updated_at']=date('Y-m-d H:i:s'); DB::update($table,$data,'id=:id',['id'=>$id]); after_save($module,$id,$data,false); Audit::log($module.'.update',$table,$id); toast('رکورد بروزرسانی شد.'); redirect($module.'.show',['id'=>$id]); } echo View::render('ویرایش '.$def['title'], module_form($module,$id,$errors)); } else echo View::render('ویرایش '.$def['title'], module_form($module,$id)); } elseif($action==='delete'){ can_module($module,'manage'); DB::exec("UPDATE $table SET deleted_at=NOW() WHERE id=?",[(int)$_GET['id']]); Audit::log($module.'.delete',$table,(int)$_GET['id']); toast('رکورد حذف شد.'); redirect($module); } elseif($action==='show'){ echo View::render($def['title'], show_record($module,(int)$_GET['id'])); } }
function check_double_booking(array $data, int $ignoreId=0): array { if(empty($data['therapist_id'])||empty($data['appointment_date'])||empty($data['start_time'])||empty($data['end_time'])) return []; $row=DB::row("SELECT id FROM appointments WHERE therapist_id=? AND appointment_date=? AND status NOT IN ('cancelled','no_show') AND id<>? AND (start_time < ? AND end_time > ?) LIMIT 1",[$data['therapist_id'],$data['appointment_date'],$ignoreId,$data['end_time'],$data['start_time']]); return $row?['این درمانگر در بازه زمانی انتخاب‌شده نوبت دیگری دارد.']:[]; }
function after_save(string $module, int $id, array $data, bool $new): void { if($module==='sessions' && (($data['status']??'completed')==='completed')){ FollowUpService::createForSession($id); DB::insert('customer_timeline',['customer_id'=>$data['customer_id'],'type'=>'session','title'=>'ثبت جلسه ماساژ','body'=>'مبلغ: '.($data['final_amount']??0),'entity'=>'massage_sessions','entity_id'=>$id,'created_at'=>date('Y-m-d H:i:s')]); } if($module==='customers' && $new) DB::insert('customer_timeline',['customer_id'=>$id,'type'=>'registration','title'=>'ثبت‌نام مشتری','body'=>'پرونده مشتری ایجاد شد','created_at'=>date('Y-m-d H:i:s')]); }
function show_record(string $module, int $id): string { global $modules; $def=$modules[$module]; $row=DB::row('SELECT * FROM '.$def['table'].' WHERE id=?',[$id]); if(!$row) return '<div class="alert alert-warning">یافت نشد.</div>'; ob_start(); ?><div class="card p-4"><div class="d-flex justify-content-between"><h3><?=e($def['title'])?> #<?=Jalali::fa($id)?></h3><a class="btn btn-outline-primary" href="<?=url($module.'.edit',['id'=>$id])?>">ویرایش</a></div><div class="row g-3 mt-2"><?php foreach($def['fields'] as $n=>$f): ?><div class="col-md-6"><div class="meta"><span><?=e($f[0])?></span><b><?=e(field_value($f[1],$row[$n]??''))?></b></div></div><?php endforeach;?></div></div><?php if($module==='customers') echo customer_profile_extra($id); return (string)ob_get_clean(); }
function customer_profile_extra(int $id): string { $metrics=DB::row("SELECT COUNT(*) visits, MIN(massage_date) first_visit, MAX(massage_date) last_visit, COALESCE(SUM(final_amount),0) total, AVG(final_amount) avg_spend, AVG(satisfaction_score) avg_score FROM massage_sessions WHERE customer_id=? AND status='completed'",[$id]); $tl=DB::select('SELECT * FROM customer_timeline WHERE customer_id=? ORDER BY created_at DESC LIMIT 50',[$id]); ob_start(); ?><div class="row g-3 mt-1"><div class="col-md-3"><div class="stat"><span>تعداد مراجعات</span><b><?=Jalali::fa($metrics['visits']??0)?></b></div></div><div class="col-md-3"><div class="stat"><span>آخرین مراجعه</span><b><?=Jalali::toJalali($metrics['last_visit']??'')?></b></div></div><div class="col-md-3"><div class="stat"><span>کل خرید</span><b><?=money($metrics['total']??0)?></b></div></div><div class="col-md-3"><div class="stat"><span>رضایت میانگین</span><b><?=Jalali::fa(round((float)($metrics['avg_score']??0),1))?></b></div></div></div><div class="card p-4 mt-3"><h4>تایم‌لاین CRM</h4><div class="timeline"><?php foreach($tl as $i): ?><div><time><?=Jalali::toJalali($i['created_at'])?></time><b><?=e($i['title'])?></b><p><?=e($i['body'])?></p></div><?php endforeach; if(!$tl): ?><p class="empty">هنوز رویدادی ثبت نشده است.</p><?php endif;?></div></div><?php return (string)ob_get_clean(); }

if($route==='login'){
    if(Auth::user()) redirect('dashboard');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // Session probe: the login form carries a token stored in the session on GET.
        // If it does not round-trip, sessions are broken (cookies blocked or the
        // server-side save path not writable) — without this check the login
        // would succeed, then bounce straight back here with no message at all.
        if(($_POST['_probe']??'')!=='' && empty($_SESSION['_login_probe']))
            $error='نشست (Session) ذخیره نمی‌شود؛ کوکی‌های مرورگر غیرفعال‌اند یا ذخیره‌سازی نشست روی سرور کار نمی‌کند. لطفاً کوکی‌ها را فعال کرده و دوباره تلاش کنید.';
        elseif(!Security::rateLimit('login',5,300)) $error='تلاش بیش از حد. چند دقیقه بعد امتحان کنید.';
        elseif(Auth::login($_POST['email']??'', $_POST['password']??'')){ unset($_SESSION['_login_probe']); redirect('dashboard'); }
        else $error='ایمیل یا رمز عبور اشتباه است.';
    }
    $_SESSION['_login_probe']=$probe=bin2hex(random_bytes(16));
    ob_start(); ?><div class="auth-card"><h1>ورود به سامانه مدیریت ماساژ</h1><?php if(!empty($error)): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><?=View::csrf()?><input type="hidden" name="_probe" value="<?=e($probe)?>"><label class="form-label">ایمیل</label><input name="email" type="email" class="form-control" required value="admin@example.com"><label class="form-label mt-3">رمز عبور</label><input name="password" type="password" class="form-control" required value="password"><button class="btn btn-primary w-100 mt-4">ورود امن</button></form><p class="text-muted small mt-3">حساب پیش‌فرض: admin@example.com / password</p></div><?php echo View::render('ورود', ob_get_clean()); exit;
}
if($route==='logout'){ Auth::logout(); redirect('login'); }
if($route==='profile.password'){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $current=$_POST['current_password']??''; $new=$_POST['new_password']??''; $confirm=$_POST['confirm_password']??'';
        $user=DB::row('SELECT password_hash FROM users WHERE id=?',[Auth::id()]);
        if(!$user||!password_verify($current,$user['password_hash'])) $error='رمز عبور فعلی اشتباه است.';
        elseif(strlen($new)<6) $error='رمز جدید باید حداقل ۶ کاراکتر باشد.';
        elseif($new!==$confirm) $error='تکرار رمز عبور مطابقت ندارد.';
        else{ DB::update('users',['password_hash'=>password_hash($new,PASSWORD_DEFAULT),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>Auth::id()]); Audit::log('password.change','users',Auth::id()); toast('رمز عبور با موفقیت تغییر کرد.'); redirect('dashboard'); }
    }
    ob_start(); ?><div class="card p-4" style="max-width:500px"><h3 class="mb-3">تغییر رمز عبور</h3><?php if(!empty($error)): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><form method="post"><?=View::csrf()?><label class="form-label">رمز عبور فعلی</label><input name="current_password" type="password" class="form-control mb-3" required><label class="form-label">رمز عبور جدید</label><input name="new_password" type="password" class="form-control mb-3" required minlength="6"><label class="form-label">تکرار رمز عبور جدید</label><input name="confirm_password" type="password" class="form-control mb-3" required minlength="6"><button class="btn btn-primary">ذخیره رمز جدید</button></form></div><?php echo View::render('تغییر رمز عبور', ob_get_clean()); exit;
}
Auth::requireLogin();

if(isset($modules[$route])) { handle_module($route,'index'); exit; }
if(preg_match('/^([a-z_]+)\.(create|edit|delete|show)$/',$route,$m) && isset($modules[$m[1]])){ handle_module($m[1],$m[2]); exit; }

if($route==='dashboard'){ Auth::requireCan('dashboard.view'); $today=date('Y-m-d');
 $stats=['appt'=>DB::value('SELECT COUNT(*) FROM appointments WHERE appointment_date=? AND deleted_at IS NULL',[$today]),'sessions'=>DB::value("SELECT COUNT(*) FROM massage_sessions WHERE massage_date=? AND status='completed' AND deleted_at IS NULL",[$today]),'revenue'=>DB::value("SELECT COALESCE(SUM(final_amount),0) FROM massage_sessions WHERE massage_date=? AND status='completed' AND deleted_at IS NULL",[$today]),'month'=>DB::value("SELECT COALESCE(SUM(final_amount),0) FROM massage_sessions WHERE massage_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND status='completed' AND deleted_at IS NULL"),'new'=>DB::value('SELECT COUNT(*) FROM customers WHERE registration_date=? AND deleted_at IS NULL',[$today]),'follow'=>DB::value("SELECT COUNT(*) FROM followups WHERE status IN ('pending','requested_later') AND due_date<=CURDATE() AND (deleted_at IS NULL OR deleted_at IS NULL)")];
 $top=DB::select("SELECT s.name, SUM(ms.final_amount) revenue FROM massage_sessions ms JOIN services s ON s.id=ms.service_id WHERE ms.status='completed' AND ms.deleted_at IS NULL GROUP BY s.id ORDER BY revenue DESC LIMIT 5");
 // --- Inventory low-stock alarm ---
 $lowStock = DB::select("SELECT * FROM inventory_items WHERE deleted_at IS NULL AND status='active' AND quantity < minimum_quantity ORDER BY (minimum_quantity - quantity) DESC LIMIT 100");
 $lowCount = count($lowStock);
 $overdueFollow = DB::select("SELECT f.*, CONCAT(c.first_name,' ',c.last_name) customer_name, c.mobile FROM followups f JOIN customers c ON c.id=f.customer_id AND c.deleted_at IS NULL WHERE f.due_date < CURDATE() AND f.status IN ('pending','requested_later') AND (f.deleted_at IS NULL) ORDER BY f.due_date ASC LIMIT 5");

 ob_start(); ?>
<div class="row g-3">
<?php foreach([['نوبت‌های امروز',$stats['appt'],'bi-calendar2-check',''],['جلسات امروز',$stats['sessions'],'bi-clipboard-heart',''],['درآمد امروز',money($stats['revenue']),'bi-cash',''],['درآمد ماه',money($stats['month']),'bi-graph-up',''],['مشتریان جدید',$stats['new'],'bi-person-plus',''],['پیگیری معوق/امروز',$stats['follow'],'bi-telephone', $stats['follow']>0?'text-danger':'']] as $s): ?>
<div class="col-md-4 col-xl-2"><div class="stat <?= $s[3] ? 'border border-danger border-2' : '' ?>"><i class="bi <?=$s[2]?>"></i><span><?=$s[0]?></span><b class="<?=$s[3]?>"><?=$s[1]?></b></div></div>
<?php endforeach;?>
</div>

<?php if($lowCount>0): ?>
<div class="alert-card inventory-alert mt-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="m-0"><i class="bi bi-exclamation-triangle-fill text-danger ms-2"></i> هشدار موجودی انبار - <?= Jalali::fa($lowCount) ?> قلم زیر حداقل</h4>
    <a href="<?=url('inventory')?>" class="btn btn-sm btn-danger"><i class="bi bi-box-seam"></i> مدیریت انبار</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>کالا</th><th>دسته</th><th>موجودی فعلی</th><th>حداقل مجاز</th><th>کمبود</th><th>واحد</th><th>وضعیت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach($lowStock as $it): $short = max(0, (float)$it['minimum_quantity'] - (float)$it['quantity']); ?>
        <tr class="table-danger-light">
          <td><b><?=e($it['name'])?></b></td>
          <td><?=e($it['category']??'-')?></td>
          <td><span class="badge text-bg-danger"><?= Jalali::fa($it['quantity']) ?></span></td>
          <td><?= Jalali::fa($it['minimum_quantity']) ?></td>
          <td><span class="text-danger fw-bold"><?= Jalali::fa($short) ?></span></td>
          <td><?= e($it['unit']??'-') ?></td>
          <td><?= status_badge($it['status']) ?></td>
          <td><a class="btn btn-sm btn-outline-danger" href="<?=url('inventory.edit',['id'=>$it['id']])?>">افزایش موجودی</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> این اقلام موجودی‌شان کمتر از حد حداقل تعریف‌شده است. لطفاً نسبت به سفارش مجدد اقدام کنید.</div>
</div>
<?php endif; ?>

<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card p-4">
      <h4><i class="bi bi-graph-up-arrow"></i> روند درآمد ۳۰ روز اخیر</h4>
      <canvas id="revenueChart" data-url="<?=url('api.revenue')?>"></canvas>
    </div>
    <?php if(!empty($overdueFollow)): ?>
    <div class="card p-4 mt-3 border-warning">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="m-0"><i class="bi bi-telephone-exclamation text-warning"></i> پیگیری‌های معوق فوری</h5>
        <a href="<?=url('followups',['filter'=>'active'])?>" class="btn btn-sm btn-warning">مشاهده همه</a>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0"><thead><tr><th>تاریخ سررسید</th><th>مشتری</th><th>موبایل</th><th>شرح</th></tr></thead>
        <tbody>
          <?php foreach($overdueFollow as $of): ?>
          <tr><td class="text-danger"><?=Jalali::toJalali($of['due_date'])?></td><td><?=e($of['customer_name'])?></td><td dir="ltr"><?=e($of['mobile'])?></td><td><?=e($of['description'])?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="card p-4">
      <h4><i class="bi bi-stars"></i> خدمات برتر</h4>
      <?php foreach($top as $r): ?><div class="d-flex justify-content-between border-bottom py-2"><span><?=e($r['name'])?></span><b><?=money($r['revenue'])?></b></div><?php endforeach;?>
      <?php if(!$top): ?><p class="empty my-3">داده‌ای برای نمایش وجود ندارد.</p><?php endif;?>
    </div>
    <div class="card p-4 mt-3 <?= $lowCount>0?'border-danger':'' ?>">
      <h5><i class="bi bi-archive"></i> وضعیت انبار</h5>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <span>اقلام زیر حداقل</span>
        <b class="fs-4 <?= $lowCount>0?'text-danger':'text-success' ?>"><?= Jalali::fa($lowCount) ?></b>
      </div>
      <?php if($lowCount>0): ?>
        <div class="alert alert-danger py-2 mt-3 mb-2"><i class="bi bi-exclamation-triangle"></i> نیاز به سفارش مجدد دارید!</div>
        <a href="<?=url('inventory')?>" class="btn btn-danger w-100 btn-sm mt-2">بررسی انبار</a>
      <?php else: ?>
        <div class="alert alert-success py-2 mt-3 mb-2"><i class="bi bi-check-circle"></i> موجودی انبار در وضعیت مطلوب است.</div>
      <?php endif; ?>
      <div class="small text-muted mt-2">مجموع اقلام فعال: <?= Jalali::fa((int)DB::value("SELECT COUNT(*) FROM inventory_items WHERE deleted_at IS NULL AND status='active'")) ?></div>
    </div>
  </div>
</div>
<?php echo View::render('داشبورد', ob_get_clean()); exit; }
if($route==='api.revenue'){ header('Content-Type: application/json'); $rows=DB::select("SELECT massage_date d, SUM(final_amount) v FROM massage_sessions WHERE massage_date>=DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status='completed' GROUP BY massage_date ORDER BY massage_date"); echo json_encode(['labels'=>array_map(fn($r)=>Jalali::toJalali($r['d']),$rows),'values'=>array_map(fn($r)=>(float)$r['v'],$rows)], JSON_UNESCAPED_UNICODE); exit; }
if($route==='followups'){
    Auth::requireCan('followups.view');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        Auth::requireCan('followups.manage');
        $id=(int)($_POST['id']??0);
        if($id){
            $updateData=['status'=>$_POST['status'],'updated_at'=>date('Y-m-d H:i:s')];
            if(isset($_POST['result']))$updateData['result']=Security::cleanString($_POST['result']);
            if(in_array($_POST['status'],['contacted','not_answered','interested','booked','refused','requested_later'],true))$updateData['contacted_at']=date('Y-m-d H:i:s');
            DB::update('followups',$updateData,'id=:id',['id'=>$id]);
            $f=DB::row('SELECT * FROM followups WHERE id=?',[$id]);
            if($f) DB::insert('customer_timeline',['customer_id'=>$f['customer_id'],'type'=>'followup','title'=>'نتیجه پیگیری','body'=>($_POST['result']??t($_POST['status'],$_POST['status'])),'entity'=>'followups','entity_id'=>$id,'created_at'=>date('Y-m-d H:i:s')]);
            toast('نتیجه پیگیری ثبت شد.');
        }
        redirect('followups', ['filter'=>$_GET['filter']??'active']);
    }
    // Generate missing followups button
    if(isset($_GET['generate'])){
        Auth::requireCan('followups.manage');
        $cnt = FollowUpService::generateDueFromLastSessions();
        toast($cnt.' پیگیری جدید ایجاد شد.');
        redirect('followups');
    }

    $filter=$_GET['filter']??'active';
    $groups=[];
    if($filter==='active'){
        $groups=[
            'معوق (گذشته)'=>"f.due_date<CURDATE() AND f.status IN ('pending','requested_later')",
            'امروز'=>"f.due_date=CURDATE() AND f.status IN ('pending','requested_later')",
            '۷ روز آینده'=>"f.due_date>CURDATE() AND f.due_date<=DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND f.status IN ('pending','requested_later')",
            'آینده دور'=>"f.due_date>DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND f.status IN ('pending','requested_later')"
        ];
    } elseif($filter==='done'){
        $groups=[
            'تماس گرفته شد'=>"f.status='contacted'",
            'پاسخ نداد'=>"f.status='not_answered'",
            'علاقه‌مند'=>"f.status='interested'",
            'رزرو شد'=>"f.status='booked'",
            'رد کرد'=>"f.status='refused'",
            'تماس بعداً'=>"f.status='requested_later' AND f.contacted_at IS NOT NULL"
        ];
    } else {
        $groups=['همه پیگیری‌ها'=>"1=1"];
    }

    $totalCount=(int)DB::value("SELECT COUNT(*) FROM followups f WHERE f.deleted_at IS NULL AND f.status IN ('pending','requested_later')");
    $todayCount=(int)DB::value("SELECT COUNT(*) FROM followups f WHERE f.deleted_at IS NULL AND f.due_date<=CURDATE() AND f.status IN ('pending','requested_later')");
    $overdueCount=(int)DB::value("SELECT COUNT(*) FROM followups f WHERE f.deleted_at IS NULL AND f.due_date<CURDATE() AND f.status IN ('pending','requested_later')");
    $doneCount=(int)DB::value("SELECT COUNT(*) FROM followups f WHERE f.deleted_at IS NULL AND f.status NOT IN ('pending','requested_later')");
    $todayStr = Jalali::toJalali(date('Y-m-d'));

    ob_start();
    ?>
    <div class="followup-page">
      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="stat stat-followup"><div class="d-flex justify-content-between w-100 align-items-center"><div><span>پیگیری فعال</span><b><?=Jalali::fa($totalCount)?></b></div><i class="bi bi-telephone-outbound fs-2 text-primary"></i></div></div></div>
        <div class="col-6 col-md-3"><div class="stat stat-followup border-warning <?= $overdueCount>0?'bg-warning-subtle':'' ?>"><div class="d-flex justify-content-between w-100 align-items-center"><div><span>معوق</span><b class="text-danger"><?=Jalali::fa($overdueCount)?></b></div><i class="bi bi-exclamation-triangle fs-2 text-warning"></i></div></div></div>
        <div class="col-6 col-md-3"><div class="stat stat-followup"><div class="d-flex justify-content-between w-100 align-items-center"><div><span>امروز + معوق</span><b class="<?= $todayCount>0?'text-danger':'' ?>"><?=Jalali::fa($todayCount)?></b></div><i class="bi bi-calendar-event fs-2 text-danger"></i></div></div></div>
        <div class="col-6 col-md-3"><div class="stat stat-followup"><div class="d-flex justify-content-between w-100 align-items-center"><div><span>انجام شده</span><b><?=Jalali::fa($doneCount)?></b></div><i class="bi bi-check-circle fs-2 text-success"></i></div></div></div>
      </div>

      <div class="card p-3 mb-4">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn <?=($filter==='active')?'btn-primary':'btn-soft'?>" href="<?=url('followups',['filter'=>'active'])?>"><i class="bi bi-lightning-charge"></i> فعال و معوق</a>
            <a class="btn <?=($filter==='done')?'btn-primary':'btn-soft'?>" href="<?=url('followups',['filter'=>'done'])?>"><i class="bi bi-check2-all"></i> انجام شده</a>
            <a class="btn <?=($filter==='all')?'btn-primary':'btn-soft'?>" href="<?=url('followups',['filter'=>'all'])?>"><i class="bi bi-list-ul"></i> همه</a>
          </div>
          <div class="d-flex gap-2">
            <span class="small text-muted align-self-center"><i class="bi bi-calendar3"></i> امروز: <?=e($todayStr)?></span>
            <a class="btn btn-sm btn-outline-secondary" href="<?=url('followups',['generate'=>1,'filter'=>$filter])?>" onclick="return confirm('پیگیری‌های جاافتاده از جلسات قبلی ساخته شوند؟')"><i class="bi bi-plus-circle"></i> ساخت پیگیری‌های جاافتاده</a>
          </div>
        </div>
      </div>

      <?php
      $hasAny = false;
      foreach($groups as $title=>$w){
        $rows=DB::select("SELECT f.*, CONCAT(c.first_name,' ',c.last_name) customer_name, c.mobile, c.status customer_status FROM followups f JOIN customers c ON c.id=f.customer_id AND c.deleted_at IS NULL WHERE f.deleted_at IS NULL AND ($w) ORDER BY f.due_date ASC, FIELD(f.priority,'high','normal','low'), f.id DESC LIMIT 500");
        if(count($rows)>0) $hasAny = true;
      }
      if(!$hasAny && $filter==='active'){
        echo '<div class="card p-5 text-center"><div class="empty-state"><i class="bi bi-telephone-x fs-1 text-muted"></i><h4 class="mt-3">پیگیری فعالی وجود ندارد!</h4><p class="text-muted">در حال حاضر هیچ پیگیری معوق یا پیش‌رو ثبت نشده است. اگر جلساتی بدون پیگیری دارید، دکمه زیر را بزنید.</p><a class="btn btn-primary mt-2" href="'.url('followups',['generate'=>1]).'"><i class="bi bi-magic"></i> تولید خودکار پیگیری‌ها</a></div></div>';
      }
      ?>

      <?php foreach($groups as $title=>$w){
        $rows=DB::select("SELECT f.*, CONCAT(c.first_name,' ',c.last_name) customer_name, c.mobile, c.status customer_status FROM followups f JOIN customers c ON c.id=f.customer_id AND c.deleted_at IS NULL WHERE f.deleted_at IS NULL AND ($w) ORDER BY f.due_date ASC, FIELD(f.priority,'high','normal','low'), f.id DESC LIMIT 500");
        $badgeClass = match(true){
          str_contains($title,'معوق')=> 'text-bg-danger',
          $title==='امروز'=> 'text-bg-warning',
          str_contains($title,'۷ روز')=> 'text-bg-info',
          default => 'text-bg-secondary'
        };
      ?>
      <div class="card p-0 mb-4 followup-group-card overflow-hidden">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="bi bi-collection"></i> <?=e($title)?> <span class="badge <?=e($badgeClass)?> ms-2"><?=Jalali::fa(count($rows))?></span></h5>
          <?php if(count($rows)>0): ?><span class="small text-muted">مرتب‌سازی بر اساس تاریخ سررسید و اولویت</span><?php endif; ?>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0 followup-table">
            <thead class="table-light"><tr><th style="min-width:110px">تاریخ سررسید</th><th style="min-width:70px">اولویت</th><th style="min-width:140px">مشتری</th><th>موبایل</th><th style="min-width:180px">شرح</th><th>نتیجه قبلی</th><th style="min-width:340px">ثبت نتیجه</th></tr></thead>
            <tbody>
            <?php foreach($rows as $r): 
              $isOverdue = ($r['due_date'] < date('Y-m-d') && in_array($r['status'],['pending','requested_later'],true));
              $rowClass = $isOverdue ? 'table-danger-light' : '';
            ?>
            <tr class="<?=e($rowClass)?>">
              <td>
                <?php if($isOverdue): ?><span class="badge text-bg-danger mb-1"><i class="bi bi-exclamation-triangle"></i> معوق</span><br><?php endif; ?>
                <?=Jalali::toJalali($r['due_date'])?>
                <div class="small text-muted"><?=e($r['due_date'])?></div>
              </td>
              <td><?php $p=$r['priority']??'normal'; $pm=['high'=>['danger','بالا'], 'normal'=>['info','عادی'], 'low'=>['secondary','پایین']]; $pp=$pm[$p]??$pm['normal']; echo '<span class="badge text-bg-'.$pp[0].'">'.e($pp[1]).'</span>';?></td>
              <td>
                <a href="<?=url('customers.show',['id'=>$r['customer_id']])?>" class="fw-bold text-decoration-none"><?=e($r['customer_name'])?></a>
                <div class="small text-muted"><?=e(t($r['customer_status']??'',$r['customer_status']??''))?></div>
              </td>
              <td dir="ltr"><a href="tel:<?=e($r['mobile'])?>" class="text-decoration-none"><?=e($r['mobile'])?></a></td>
              <td><span class="text-wrap" style="max-width:200px;display:inline-block"><?=e($r['description']??'-')?></span></td>
              <td><?php if(!empty($r['result'])): ?><span class="result-chip"><?=e($r['result'])?></span><?php else: ?><span class="text-muted small">—</span><?php endif;?><div class="small text-muted mt-1"><?php if(!empty($r['contacted_at'])) echo Jalali::toJalali($r['contacted_at']); ?></div></td>
              <td>
                <?php if(in_array($r['status'],['pending','requested_later'],true)):?>
                <form method="post" class="followup-action-form"><?=View::csrf()?><input type="hidden" name="id" value="<?=$r['id']?>"><div class="d-flex flex-wrap gap-1 align-items-center">
                  <select name="status" class="form-select form-select-sm" style="min-width:130px;width:auto">
                    <option value="contacted">تماس گرفته شد</option>
                    <option value="not_answered">پاسخ نداد</option>
                    <option value="interested">علاقه‌مند</option>
                    <option value="booked" class="text-success">رزرو شد ✓</option>
                    <option value="requested_later">تماس بعداً</option>
                    <option value="refused" class="text-danger">رد کرد</option>
                  </select>
                  <input name="result" class="form-control form-control-sm" placeholder="یادداشت نتیجه..." style="min-width:120px;width:140px">
                  <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> ثبت</button>
                </div></form>
                <?php else:?>
                <span class="badge text-bg-<?=($r['status']==='booked'?'success':($r['status']==='refused'?'danger':'info'))?>"><?=e(t($r['status'],$r['status']))?></span>
                <a href="<?=url('customers.show',['id'=>$r['customer_id']])?>" class="btn btn-sm btn-soft ms-1"><i class="bi bi-eye"></i></a>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach; if(!$rows): ?><tr><td colspan="7" class="empty py-4 text-center"><i class="bi bi-inbox fs-4 d-block mb-2"></i> موردی در این بخش وجود ندارد.</td></tr><?php endif;?>
            </tbody>
          </table>
        </div>
      </div>
      <?php } ?>
    </div>
    <?php echo View::render('مرکز پیگیری', ob_get_clean()); exit; }
if($route==='retention'){ $rows=RetentionService::metrics(); ob_start(); ?><div class="card p-4"><h3>هوشمندی نگهداشت مشتری (RFM)</h3><table class="table"><tr><th>مشتری</th><th>آخرین مراجعه</th><th>تعداد</th><th>ارزش مالی</th><th>بخش</th><th>پیشنهاد</th></tr><?php foreach($rows as $r): ?><tr><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=Jalali::toJalali($r['last_visit'])?></td><td><?=Jalali::fa($r['visits'])?></td><td><?=money($r['monetary'])?></td><td><?=status_badge($r['segment'])?></td><td><?=e(($r['segment']==='at_risk'||$r['segment']==='lost')?'تماس فوری و پیشنهاد تخفیف بازگشت':'حفظ ارتباط و پیشنهاد رزرو بعدی')?></td></tr><?php endforeach;?></table></div><?php echo View::render('نگهداشت مشتری', ob_get_clean()); exit; }
if($route==='finance' || $route==='reports'){ Auth::requireCan(($route==='finance'?'finance':'reports').'.view'); $from=Jalali::toGregorian($_GET['from']??date('Y-m-01')); $to=Jalali::toGregorian($_GET['to']??date('Y-m-d')); $rev=DB::row("SELECT COUNT(*) sessions, COALESCE(SUM(final_amount),0) revenue, COALESCE(SUM(discount),0) discounts FROM massage_sessions WHERE status='completed' AND massage_date BETWEEN ? AND ?",[$from,$to]); $exp=DB::value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ? AND deleted_at IS NULL',[$from,$to]); ob_start(); ?><form class="card p-3 mb-3"><div class="row g-2 align-items-end"><input type="hidden" name="r" value="<?=e($route)?>"><div class="col-md-3"><label class="form-label">از تاریخ</label><div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3"></i></span><input name="from" class="form-control" data-jdp value="<?=Jalali::toJalali($from)?>" placeholder="1404/01/01" autocomplete="off"></div></div><div class="col-md-3"><label class="form-label">تا تاریخ</label><div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3"></i></span><input name="to" class="form-control" data-jdp value="<?=Jalali::toJalali($to)?>" placeholder="1404/12/29" autocomplete="off"></div></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-bar-chart"></i> گزارش</button><a class="btn btn-soft" href="<?=url('export.csv',['from'=>$from,'to'=>$to])?>"><i class="bi bi-download"></i> خروجی CSV</a></div></div></form><div class="row g-3"><div class="col-md-3"><div class="stat"><span>درآمد</span><b><?=money($rev['revenue'])?></b></div></div><div class="col-md-3"><div class="stat"><span>هزینه</span><b><?=money($exp)?></b></div></div><div class="col-md-3"><div class="stat"><span>سود خالص</span><b><?=money($rev['revenue']-$exp)?></b></div></div><div class="col-md-3"><div class="stat"><span>تعداد جلسات</span><b><?=Jalali::fa($rev['sessions'])?></b></div></div></div><div class="card p-4 mt-3"><h4>درآمد بر اساس درمانگر/خدمت/منبع معرفی</h4><p>این بخش آماده چاپ و خروجی‌گیری است و داده‌ها از رکوردهای واقعی محاسبه می‌شود.</p></div><?php echo View::render($route==='finance'?'مالی':'گزارش‌ها', ob_get_clean()); exit; }
if($route==='export.csv'){ Auth::requireCan('reports.view'); header('Content-Type:text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=report.csv'); echo "date,customer,service,therapist,amount\n"; foreach(DB::select("SELECT ms.massage_date, CONCAT(c.first_name,' ',c.last_name) c, s.name s, t.name t, ms.final_amount FROM massage_sessions ms LEFT JOIN customers c ON c.id=ms.customer_id LEFT JOIN services s ON s.id=ms.service_id LEFT JOIN therapists t ON t.id=ms.therapist_id WHERE ms.massage_date BETWEEN ? AND ?",[$_GET['from'],$_GET['to']]) as $r) echo implode(',',array_map(fn($v)=>'"'.str_replace('"','""',(string)$v).'"',$r))."\n"; exit; }
if($route==='salaries'){ Auth::requireCan('salaries.view'); $therapists=DB::select("SELECT id,name FROM therapists WHERE status='active'"); $tid=(int)($_GET['therapist_id']??($therapists[0]['id']??0)); $from=Jalali::toGregorian($_GET['from']??date('Y-m-01')); $to=Jalali::toGregorian($_GET['to']??date('Y-m-d')); $calc=$tid?SalaryService::calculate($tid,$from,$to):[]; ob_start(); ?><form class="card p-3 mb-3"><div class="row g-2 align-items-end"><input type="hidden" name="r" value="salaries"><div class="col-md-3"><label class="form-label">درمانگر</label><select name="therapist_id" class="form-select"><?php foreach($therapists as $t): ?><option value="<?=$t['id']?>" <?=$tid===$t['id']?'selected':''?>><?=e($t['name'])?></option><?php endforeach;?></select></div><div class="col-md-3"><label class="form-label">از تاریخ</label><div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3"></i></span><input name="from" class="form-control" data-jdp value="<?=Jalali::toJalali($from)?>" placeholder="1404/01/01" autocomplete="off"></div></div><div class="col-md-3"><label class="form-label">تا تاریخ</label><div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3"></i></span><input name="to" class="form-control" data-jdp value="<?=Jalali::toJalali($to)?>" placeholder="1404/12/29" autocomplete="off"></div></div><div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-calculator"></i> محاسبه</button></div></div></form><?php if($calc): ?><div class="card p-4"><h3>فیش محاسبات</h3><div class="row g-3"><div class="col"><div class="stat"><span>جلسات</span><b><?=Jalali::fa($calc['session_count'])?></b></div></div><div class="col"><div class="stat"><span>فروش</span><b><?=money($calc['gross'])?></b></div></div><div class="col"><div class="stat"><span>حقوق پایه</span><b><?=money($calc['base_salary'])?></b></div></div><div class="col"><div class="stat"><span>پورسانت</span><b><?=money($calc['commission'])?></b></div></div><div class="col"><div class="stat"><span>قابل پرداخت</span><b><?=money($calc['payable'])?></b></div></div></div></div><?php endif; echo View::render('حقوق و پورسانت', ob_get_clean()); exit; }
if($route==='users'){
    Auth::requireCan('users.manage');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $data=['name'=>$_POST['name'],'email'=>$_POST['email'],'role_id'=>(int)$_POST['role_id'],'status'=>$_POST['status'],'permissions'=>json_encode($_POST['permissions']??[],JSON_UNESCAPED_UNICODE),'updated_at'=>date('Y-m-d H:i:s')];
        if(!empty($_POST['password'])) $data['password_hash']=password_hash($_POST['password'],PASSWORD_DEFAULT);
        if(!empty($_POST['user_id'])){
            DB::update('users',$data,'id=:id',['id'=>(int)$_POST['user_id']]);
            Audit::log('user.update','users',(int)$_POST['user_id']);
        } else {
            if(empty($_POST['password'])){$error='رمز عبور برای کاربر جدید الزامی است.';}
            else{$data['created_at']=date('Y-m-d H:i:s'); DB::insert('users',$data); Audit::log('user.create','users',(int)DB::value('SELECT LAST_INSERT_ID()'));}
        }
        if(empty($error)){toast('کاربر ذخیره شد.'); redirect('users');}
    }
    $editUser=null; $editId=(int)($_GET['edit_id']??0);
    if($editId) $editUser=DB::row('SELECT u.*, r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?',[$editId]);
    if($_GET['delete_id']??0){
        $delId=(int)$_GET['delete_id'];
        if($delId===Auth::id()){toast('نمی‌توانید خودتان را حذف کنید.');}
        else{DB::exec('UPDATE users SET deleted_at=NOW() WHERE id=?',[$delId]); Audit::log('user.delete','users',$delId); toast('کاربر حذف شد.');}
        redirect('users');
    }
    $roles=DB::select('SELECT * FROM roles');
    $users=DB::select('SELECT u.*, r.name role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.deleted_at IS NULL ORDER BY u.id DESC');
    ob_start(); ?><div class="row g-3"><div class="col-lg-5"><form method="post" class="card p-3"><?=View::csrf()?><?php if(!empty($error)):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?><h4><?=$editUser?'ویرایش کاربر: '.e($editUser['name']):'کاربر جدید'?></h4><?php if($editUser):?><input type="hidden" name="user_id" value="<?=$editUser['id']?>"><?php endif;?><input name="name" class="form-control mb-2" placeholder="نام" value="<?=e($editUser['name']??'')?>" required><input name="email" class="form-control mb-2" placeholder="ایمیل" type="email" value="<?=e($editUser['email']??'')?>" required><input name="password" class="form-control mb-2" placeholder="<?=$editUser?'رمز جدید (خالی بگذارید تا تغییر نکند)':'رمز عبور'?>" <?=empty($editUser)?'required':''?>><select name="role_id" class="form-select mb-2"><?php foreach($roles as $r): ?><option value="<?=$r['id']?>" <?=((int)($editUser['role_id']??0)===$r['id'])?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><select name="status" class="form-select mb-2"><option value="active" <?=($editUser['status']??'active')==='active'?'selected':''?>>فعال</option><option value="disabled" <?=($editUser['status']??'')==='disabled'?'selected':''?>>غیرفعال</option></select><div class="d-flex gap-2"><button class="btn btn-primary">ذخیره</button><?php if($editUser):?><a class="btn btn-soft" href="<?=url('users')?>">انصراف</a><?php endif;?></div></form></div><div class="col-lg-7"><div class="card p-3"><div class="table-responsive"><table class="table"><tr><th>نام</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>عملیات</th></tr><?php foreach($users as $u): ?><tr><td><?=e($u['name'])?></td><td><?=e($u['email'])?></td><td><?=e($u['role'])?></td><td><?=status_badge($u['status'])?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?=url('users',['edit_id'=>$u['id']])?>">ویرایش</a><?php if($u['id']!==Auth::id()):?> <a class="btn btn-sm btn-outline-danger" href="<?=url('users',['delete_id'=>$u['id']])?>" onclick="return confirm('آیا از حذف این کاربر مطمئن هستید؟')">حذف</a><?php endif;?></td></tr><?php endforeach;?></table></div></div></div></div><?php echo View::render('کاربران و نقش‌ها', ob_get_clean()); exit; }
if($route==='settings'){
    Auth::requireCan('settings.manage');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        // Handle logo upload
        if(!empty($_FILES['logo_file']['tmp_name'])){
            $f=$_FILES['logo_file'];
            $allowed=['image/png','image/jpeg','image/svg+xml','image/webp'];
            if(in_array($f['type'],$allowed,true)&&$f['size']<5*1024*1024){
                $ext=pathinfo($f['name'],PATHINFO_EXTENSION);
                if(!$ext)$ext='png';
                $dest='uploads/logo.'.$ext;
                $fullDest=public_path($dest);
                if(!is_dir(dirname($fullDest)))@mkdir(dirname($fullDest),0775,true);
                move_uploaded_file($f['tmp_name'],$fullDest);
                DB::exec('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['logo_path',asset($dest)]);
                Audit::log('settings.logo','settings',0);
            } else { $logoError='فایل نامعتبر است. فقط PNG/JPG/SVG/WebP تا ۵ مگابایت مجاز است.'; }
        }
        // Handle favicon upload
        if(!empty($_FILES['favicon_file']['tmp_name'])){
            $f=$_FILES['favicon_file'];
            $allowed=['image/png','image/x-icon','image/svg+xml','image/webp'];
            if(in_array($f['type'],$allowed,true)&&$f['size']<2*1024*1024){
                $ext=pathinfo($f['name'],PATHINFO_EXTENSION);
                if(!$ext)$ext='png';
                $dest='uploads/favicon.'.$ext;
                $fullDest=public_path($dest);
                if(!is_dir(dirname($fullDest)))@mkdir(dirname($fullDest),0775,true);
                move_uploaded_file($f['tmp_name'],$fullDest);
                DB::exec('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',['favicon_path',asset($dest)]);
            }
        }
        foreach($_POST['settings']??[] as $k=>$v){ DB::exec('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,$v]); }
        toast('تنظیمات ذخیره شد.'); redirect('settings');
    }
    $keys=['brand_name'=>'نام برند','primary_color'=>'رنگ اصلی','secondary_color'=>'رنگ دوم','website_title'=>'عنوان وب‌سایت','contact_phone'=>'تلفن','contact_phone_2'=>'تلفن دوم','address'=>'آدرس','default_followup_days'=>'بازه پیگیری پیش‌فرض','currency'=>'واحد پول','default_theme'=>'تم پیش‌فرض (light/dark)','instagram'=>'اینستاگرام','telegram'=>'تلگرام','whatsapp'=>'واتساپ'];
    $currentLogo=View::setting('logo_path','');
    $currentFavicon=View::setting('favicon_path','');
    ob_start(); ?>
    <form method="post" enctype="multipart/form-data" class="card p-4">
        <?=View::csrf()?>
        <?php if(!empty($logoError)):?><div class="alert alert-danger"><?=e($logoError)?></div><?php endif;?>
        <h4 class="mb-3"><i class="bi bi-palette"></i> برندینگ و لوگو</h4>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">لوگوی سامانه</label>
                <?php if($currentLogo):?><div class="mb-2"><img src="<?=e($currentLogo)?>" alt="logo" style="max-height:80px;border-radius:12px"></div><?php endif;?>
                <input type="file" name="logo_file" accept="image/*" class="form-control">
                <div class="form-text">PNG/JPG/SVG/WebP — حداکثر ۵ مگابایت</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">فاوآیکون</label>
                <?php if($currentFavicon):?><div class="mb-2"><img src="<?=e($currentFavicon)?>" alt="favicon" style="max-height:40px;border-radius:8px"></div><?php endif;?>
                <input type="file" name="favicon_file" accept="image/*" class="form-control">
                <div class="form-text">PNG/ICO/SVG — حداکثر ۲ مگابایت</div>
            </div>
        </div>
        <hr class="my-3">
        <h4 class="mb-3"><i class="bi bi-gear"></i> تنظیمات عمومی</h4>
        <div class="row g-3">
            <?php foreach($keys as $k=>$l): ?><div class="col-md-6"><label class="form-label"><?=$l?></label><input name="settings[<?=$k?>]" value="<?=e(View::setting($k,''))?>" class="form-control"></div><?php endforeach;?>
        </div>
        <button class="btn btn-primary mt-4"><i class="bi bi-check-lg"></i> ذخیره تنظیمات</button>
    </form>
    <?php echo View::render('تنظیمات', ob_get_clean()); exit; }
if($route==='backup'){ Auth::requireCan('backup.manage'); $error=null; if($_SERVER['REQUEST_METHOD']==='POST'){ try{BackupService::create(); toast('پشتیبان تهیه شد.'); redirect('backup');}catch(Throwable $e){$error=$e->getMessage();}} ob_start(); if($error) echo '<div class="alert alert-danger">'.e($error).'</div>'; ?><div class="card p-4"><form method="post"><?=View::csrf()?><button class="btn btn-primary">ایجاد بکاپ دستی</button></form><h4 class="mt-4">فایل‌های بکاپ</h4><ul><?php foreach(BackupService::list() as $f): ?><li><?=e($f)?></li><?php endforeach;?></ul><p class="text-muted">برای بکاپ زمان‌بندی‌شده از cron طبق مستندات استفاده کنید.</p></div><?php echo View::render('پشتیبان‌گیری', ob_get_clean()); exit; }
if($route==='audit'){ Auth::requireCan('audit.view'); $rows=DB::select('SELECT a.*, u.name user FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200'); ob_start(); ?><div class="card p-3"><table class="table"><tr><th>زمان</th><th>کاربر</th><th>عمل</th><th>رکورد</th><th>IP</th></tr><?php foreach($rows as $r): ?><tr><td><?=Jalali::toJalali($r['created_at'])?></td><td><?=e($r['user'])?></td><td><?=e($r['action'])?></td><td><?=e($r['entity'].' #'.$r['entity_id'])?></td><td><?=e($r['ip_address'])?></td></tr><?php endforeach;?></table></div><?php echo View::render('ممیزی', ob_get_clean()); exit; }
http_response_code(404); echo View::render('یافت نشد','<div class="alert alert-warning">صفحه یافت نشد.</div>');
