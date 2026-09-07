# نقشه ایندکس پروژه massage (ehssanehs/massage — Persian Massage CRM)

**Stack:** PHP 8.2+ خالص (بدون فریم‌ورک)، MySQL/MariaDB، Bootstrap 5 RTL، Chart.js، بدون Composer vendor.
**Entry:** `public/index.php` (front controller + روتر با `?r=`) → `app/bootstrap.php`.
**CLI:** `bin/console` (install, migrate, seed, user:password, schedule:run, backup:create, backup:check).
**نصب:** `php bin/console install` + `.env` (از .env.example). اکانت پیش‌فرض: admin@example.com / password

## معماری
- **Core:** `DB` (PDO singleton، insert/update عمومی)، `Auth` (session، RBAC با role+permissions JSON، super_admin=* )، `Security` (CSRF، rate limit، cleanString).
- **Services:** `Audit` (audit_logs)، `FollowUpService` (تولید پیگیری از جلسات)، `SalaryService` (حقوق/پورسانت)، `RetentionService` (RFM)، `BackupService` (mysqldump)، `RestoreCheckService`، `Notification`، `PaymentMethods` (لیست روش‌های پرداخت در جدول settings).
- **Support:** `View` (layout RTL، dateInput/timeInput جلالی)، `Jalali` (تبدیل شمسی/میلادی، دوره ۳۳ساله خیام)، `DateRange`، `ClockTime` (نرمال‌سازی ساعت ۲۴ساعته)، `SearchQuery` (نرمال‌سازی فارسی: ی/ي، ک/ك، ارقام، اعراب؛ AND چندواژه‌ای)، `BirthMonth` (فیلتر ماه تولد شمسی ۱-۱۲ بدون سال).
- **UI assets:** jalalidatepicker + timepicker محلی (بدون CDN برای این دو)، app.js.

## روتر (public/index.php ~865 خط)
- ماژول‌های CRUD دیتابیس‌محور از `config/modules.php`: customers, services, therapists, appointments, sessions (massage_sessions), expenses, inventory, packages, campaigns — هر کدام index/create/edit/show/delete با soft delete (deleted_at).
- صفحات اختصاصی: dashboard (آمار + هشدار انبار + نمودار درآمد ۳۰روزه api.revenue)، followups (گروه‌بندی معوق/امروز/آینده + ثبت نتیجه)، retention (RFM)، finance/reports (DateRange + export.csv با BOM)، salaries، users (RBAC)، settings (برندینگ/لوگو/روش‌های پرداخت)، backup، audit.
- منطق خاص: `check_double_booking` (تداخل نوبت درمانگر)، `after_save` (تایم‌لاین + پیگیری خودکار جلسه completed)، `list_sql` (JOIN+search مشترک برای ردیف/COUNT)، autofill قیمت از data-price خدمت.
- حساب پیش‌فرض لاگین روی فرم هاردکد شده (admin@example.com/password در value) — نکته امنیتی.

## دیتابیس (database/schema.sql — 15 جدول)
branches, roles, users, settings, customers, services, therapists, appointments, massage_sessions, followups, customer_timeline, expenses, salary_runs, customer_packages, inventory_items, inventory_movements, campaigns, audit_logs.
- همه‌جا soft delete با deleted_at؛ FKهای InnoDB؛ تاریخ‌ها میلادی در DB، شمسی فقط در UI.
- Seed: 5 نقش (super_admin/manager/receptionist/therapist/accountant)، ادمین، 5 خدمت، 2 درمانگر، 2 مشتری، داده نمونه.

## قراردادهای مهم (برای توسعه آینده)
- هر فرم POST با CSRF (`View::csrf` / `Security::verifyCsrf`).
- خروجی‌ها با `e()`؛ تاریخ ورودی کاربر جلالی است، ذخیره میلادی (`Jalali::toGregorian` در normalize_post).
- فیلدهای select/rel در modules.php به‌صورت `[label, type, options|flags]`؛ typeهای پشتیبانی‌شده در `input_html`.
- جستجو همیشه از `SearchQuery` + `list_sql` — REGEXP_REPLACE حذف شده (سازگار با MySQL قدیمی‌تر).
- ماژول جدید = جدول + تعریف در config/modules.php (+ اختیاری سرویس در app/Services).
- تست‌ها: `php tests/*.php` و `node --test tests/*.test.js` (نیاز PHP 8.2 + pdo_sqlite + Node 18؛ Playwright اختیاری).

## وضعیت اجرا روی این سرور
- کلون در `/root/massage`.
- سرور آماده: PHP 8.2.33 (fpm/cli + pdo_mysql/sqlite, mbstring, curl, xml, zip)، MySQL 8.0.46.
- DB: `massage_crm` با یوزر `massage_user`؛ `.env` تنظیم و `bin/console install` اجرا شده. ادمین: admin@example.com / password.
- نکته: `tests/search.php` باید مثل پروداکشن `numericKeys=[1,2]` پاس دهد (فیکس eabee8b)؛ بدون آن جستجوی ارقام عربی در تست شکست می‌خورد ولی برنامه سالم است.
