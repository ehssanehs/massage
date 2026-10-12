# نقشه ایندکس پروژه massage (ehssanehs/massage — Persian Massage CRM)

**Stack:** PHP 8.2+ خالص (بدون فریم‌ورک)، MySQL/MariaDB، Bootstrap 5 RTL، Chart.js، بدون Composer vendor.
**Entry:** `public/index.php` (front controller + روتر با `?r=`) → `app/bootstrap.php`.
**CLI:** `bin/console` (install, migrate, seed, user:password, schedule:run, backup:create, backup:check).
**نصب:** `php bin/console install` + `.env` (از .env.example). اکانت پیش‌فرض: admin@example.com / password

## معماری
- **Core:** `DB` (PDO singleton، insert/update عمومی)، `Auth` (session، RBAC با role+permissions JSON، super_admin=* )، `Security` (CSRF، rate limit، cleanString).
- **Services:** `Audit` (audit_logs)، `FollowUpService` (تولید پیگیری از جلسات)، `SalaryService` (حقوق/پورسانت و جایگزینی پورسانت عادی با نرخ ثبت‌شدهٔ جلسهٔ مشتری عرب)، `ArabCommission` (اعتبارسنجی درصد و ثبت نرخ زمان جلسه)، `RetentionService` (RFM)، `BackupService` (mysqldump)، `RestoreCheckService`، `Notification`، `PaymentMethods` (لیست روش‌های پرداخت در جدول settings)، `Credit` (کیف اعتبار مشتری: لجر credit_transactions + موجودی customers.credit_balance + درصد credit_earn_percent).
- **Support:** `View` (layout RTL، dateInput/timeInput جلالی)، `Jalali` (تبدیل شمسی/میلادی، دوره ۳۳ساله خیام)، `DateRange`، `ClockTime` (نرمال‌سازی ساعت ۲۴ساعته)، `SearchQuery` (نرمال‌سازی فارسی: ی/ي، ک/ك، ارقام، اعراب؛ AND چندواژه‌ای)، `BirthMonth` (فیلتر ماه تولد شمسی ۱-۱۲ بدون سال)، `Tenant` (انتخاب بخش آقایان/خانم‌ها و تعیین دیتابیس همان بخش).
- **UI assets:** jalalidatepicker + timepicker محلی (بدون CDN برای این دو)، app.js.

## روتر (public/index.php ~865 خط)
- ماژول‌های CRUD دیتابیس‌محور از `config/modules.php`: customers, services, therapists, appointments, sessions (massage_sessions), expenses, inventory, packages, campaigns — هر کدام index/create/edit/show/delete با soft delete (deleted_at).
- صفحات اختصاصی: dashboard (آمار + هشدار انبار + نمودار درآمد ۳۰روزه api.revenue)، followups (گروه‌بندی معوق/امروز/آینده + ثبت نتیجه و ویرایش وضعیت/متن نتیجهٔ پیگیری انجام‌شده)، retention (RFM)، finance/reports (DateRange + export.csv با BOM)، salaries (تفکیک پورسانت عادی/عرب)، users (RBAC)، settings (برندینگ/لوگو/روش‌های پرداخت + درصد مشتری عرب)، backup، audit.
- منطق خاص: `check_double_booking` (تداخل نوبت درمانگر)، `after_save` (تایم‌لاین + پیگیری خودکار جلسه completed)، `list_sql` (JOIN+search مشترک برای ردیف/COUNT)، autofill قیمت از data-price خدمت.
- حساب پیش‌فرض لاگین روی فرم هاردکد شده (admin@example.com/password در value) — نکته امنیتی.
- مشتری با `customers.is_arab_customer` مشخص می‌شود؛ `settings.arab_customer_commission_percent` درصد مبلغ نهایی جلسه (۰ تا ۱۰۰، حداکثر دو رقم اعشار) است. هنگام ثبت جلسه، درصد همان زمان در `massage_sessions.arab_commission_percent` ذخیره می‌شود (`NULL` = پورسانت عادی؛ صفر = مشتری عرب با نرخ صفر). محاسبهٔ حقوق، فقط برای جلسات معمولی مدل پورسانت درمانگر را اعمال می‌کند و برای جلسات عرب درصد ثبت‌شده را جایگزین می‌سازد؛ حقوق پایه طبق مدل قبلی محفوظ است. تغییر بعدی تنظیمات/برچسب مشتری، جلسات قدیمی را تغییر نمی‌دهد؛ تغییر مشتریِ خود جلسه، نرخ جدید را اعمال می‌کند. قبل از استفاده روی نصب قدیمی `php bin/console migrate` الزامی است.

## دیتابیس (database/schema.sql — 15 جدول)
branches, roles, users, settings, customers, services, therapists, appointments, massage_sessions, followups, customer_timeline, expenses, salary_runs, customer_packages, inventory_items, inventory_movements, campaigns, audit_logs.
- همه‌جا soft delete با deleted_at؛ FKهای InnoDB؛ تاریخ‌ها میلادی در DB، شمسی فقط در UI.
- Seed: 5 نقش (super_admin/manager/receptionist/therapist/accountant)، ادمین، 5 خدمت، 2 درمانگر، 2 مشتری، داده نمونه.

## صفحهٔ عمومی و دو بخش مستقل (آقایان/خانم‌ها)
- `public/index.php` روت `home` را پیش از احراز هویت و بدون هیچ کوئری دیتابیس رندر می‌کند: دو کارت «خدمات ماساژ» و «بخش آموزش» + ورود پرسنل. استایل: `public/assets/css/public.css`.
- `app/Support/Tenant.php` تنها مرجع انتخاب بخش است؛ کلید بخش (`men`/`women`) در نشست سرور ذخیره می‌شود و هیچ‌گاه نام دیتابیس از درخواست خوانده نمی‌شود. با عوض‌شدن بخش، `uid`، `_csrf` و rate-limit نشست پاک می‌شوند.
- `DB_DATABASE_MEN` / `DB_DATABASE_WOMEN` هر بخش را به دیتابیس خودش وصل می‌کند (dbname از همان‌جا، نه از درخواست). اگر هیچ‌کدام تعیین نشده باشد، نصب تک‌بخشی با `DB_DATABASE` ادامه می‌یابد (`Tenant::isMulti()` تعیین‌کننده است).
- مسیرهای `/men` و `/women` نقطهٔ ورود هر بخش‌اند: `public/.htaccess` (Apache) و `public/router.php` (سرور توکار PHP). `url()` و `redirect()` آدرس مطلق با پیشوند بخش می‌سازند تا مسیر بخش بین صفحات حفظ شود؛ `asset()` مطلق است تا از هر پیشوندی کار کند.
- `bin/console` دستورهای دیتابیسی را برای همهٔ بخش‌های تنظیم‌شده اجرا می‌کند (`--tenant=men|women|all`). نام فایل پشتیبان بخش را در خود دارد (`backup-men-….sql`) تا دو بخش روی هم بازنویسی نشوند.
- تست‌ها: `node --test tests/public-tenants-routes.test.js`، `node tests/public-pages-render.cjs` و `python3 tests/tenants-http.py` (دو دیتابیس موقت، ورود واقعی HTTP، رد شدن ورود بین‌بخشی و باطل‌شدن نشست هنگام تعویض بخش).
- متن و محتوای صفحهٔ عمومی در `config/public-site.php` است (بدون کد؛ همان‌جا ویرایش شود).

## ماتریس پیگیری
- روت `followups.matrix` (منوی «ماتریس پیگیری»، مجوز `followups.view`): سطرها **همهٔ مشتریان** (حتی بدون پیگیری) و ستون‌ها **ترتیب پیگیری‌ها** (پیگیری ۱، ۲، ۳ ...) هستند؛ هر خانه وضعیت، تاریخ سررسید شمسی و متن کامل نتیجهٔ همان پیگیری را نشان می‌دهد.
- تعداد ستون‌ها یک بیشینهٔ **سراسری** است (نه صفحه‌ای) تا سرستون‌ها با ورق‌زدن تغییر نکنند. همهٔ پیگیری‌های مشتریان همان صفحه در **یک کوئری** خوانده می‌شوند (بدون N+1) و گروه‌بندی در PHP انجام می‌شود.
- صفحه فقط نمایشی است (بدون فرم POST)؛ هر خانه و نام مشتری به پروندهٔ مشتری لینک دارد. جستجو (نام کامل/موبایل/کد) و صفحه‌بندی مثل فهرست مشتریان است.
- `render_followup_matrix()` در `public/index.php`؛ استایل‌های `.matrix-*` در `public/assets/css/app.css` (ستون مشتری چسبان، اسکرول افقی).
- منوی فعال با «دقیق‌ترین تطابق» انتخاب می‌شود؛ در غیر این‌صورت هم «مرکز پیگیری» و هم «ماتریس پیگیری» هم‌زمان فعال می‌شدند.
- تست‌ها: `node --test tests/followup-matrix-routes.test.js` و `python3 tests/followup-matrix-http.py` (دیتابیس موقت، دادهٔ واقعی با ۰/۱/۳ پیگیری، اثبات فقط‌خواندنی‌بودن).

## قراردادهای مهم (برای توسعه آینده)
- هر فرم POST با CSRF (`View::csrf` / `Security::verifyCsrf`).
- خروجی‌ها با `e()`؛ تاریخ ورودی کاربر جلالی است، ذخیره میلادی (`Jalali::toGregorian` در normalize_post).
- فیلدهای select/rel در modules.php به‌صورت `[label, type, options|flags]`؛ typeهای پشتیبانی‌شده در `input_html`.
- جستجو همیشه از `SearchQuery` + `list_sql` — REGEXP_REPLACE حذف شده (سازگار با MySQL قدیمی‌تر).
- ماژول جدید = جدول + تعریف در config/modules.php (+ اختیاری سرویس در app/Services).
- تست‌ها: `php tests/*.php` و `node --test tests/*.test.js` (نیاز PHP 8.2 + pdo_sqlite + Node 18؛ Playwright اختیاری).

## بیعانه نوبت‌ها
- `app/Support/AppointmentDeposit.php`: نرمال‌سازی دقیق مبلغ غیرمنفی و تشخیص وجود ستون در بکاپ قدیمی.
- `appointments.deposit_amount`، migration 004؛ فرم/نمایش/ستون لیست و فیلتر `deposit=1` در public/index.php.
- تست‌ها: `deposit-routes.test.js`، `deposit.php`، `deposit-http.py` (HTTP/MySQL واقعی روی دیتابیس‌های موقت).

- فهرست‌های ماژول‌های CRUD با کلیک روی سرستون‌ها به‌صورت صعودی/نزولی در خود SQL مرتب می‌شوند؛ مرتب‌سازی قبل از صفحه‌بندی انجام می‌شود و با جستجو، ماه تولد و فیلتر بیعانه حفظ می‌شود. ورودی `sort` فقط ستون‌های فهرست‌شده در `config/modules.php` و `dir` فقط asc/desc را می‌پذیرد. جدول‌های فرعیِ بدون صفحه‌بندی در مرورگر روی ردیف‌های نمایش‌داده‌شده مرتب می‌شوند (نه رکوردهای بیرون از محدودهٔ جدول).
- تست: `node --test tests/sort-routes.test.js tests/table-sort.test.js` و `python3 tests/sort-http.py` (MySQL/HTTP با دیتابیس موقت).

## وضعیت اجرا روی این سرور
- کلون در `/root/massage`.
- سرور آماده: PHP 8.2.33 (fpm/cli + pdo_mysql/sqlite, mbstring, curl, xml, zip)، MySQL 8.0.46.
- DB: `massage_crm` با یوزر `massage_user`؛ `.env` تنظیم و `bin/console install` اجرا شده. ادمین: admin@example.com / password.
- نکته: `tests/search.php` باید مثل پروداکشن `numericKeys=[1,2]` پاس دهد (فیکس eabee8b)؛ بدون آن جستجوی ارقام عربی در تست شکست می‌خورد ولی برنامه سالم است.
