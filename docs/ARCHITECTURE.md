# تحلیل نیازمندی و معماری

## نیازمندی‌های تکمیلی حرفه‌ای افزوده‌شده

- آماده‌سازی چندشعبه‌ای با `branches` و `branch_id` در موجودیت‌های اصلی.
- Soft delete برای رکوردهای مهم.
- Audit trail برای ورود/خروج و تغییرات کلیدی.
- لایه انتزاعی Notification برای جلوگیری از وابستگی به یک SMS/WhatsApp provider.
- CLI برای install/migrate/seed/scheduler/backup تا نصب روی سرور و cron ساده باشد.
- RFM ساده برای نگهداشت مشتری و اولویت‌بندی تماس‌ها.
- تنظیمات Branding و Business قابل تغییر در دیتابیس.
- مدل حقوقی قابل تنظیم برای هر درمانگر.

## ساختار پروژه

```text
app/
  Core/        DB, Auth, Security
  Services/    Audit, FollowUp, Salary, Retention, Backup, Notification
  Support/     View, Jalali
config/        lang, nav, modules
database/      migrations, seeders, schema.sql
public/        index.php, assets, uploads/storage
bin/console    ابزار CLI و Scheduler
```

## الگوهای امنیتی

- همه Queryها با PDO prepared statements اجرا می‌شوند.
- همه فرم‌های POST دارای CSRF token هستند.
- رمزها با `password_hash/password_verify` مدیریت می‌شوند.
- خروجی HTML با `htmlspecialchars` escape می‌شود.
- RBAC از نقش و permissions کاربر خوانده می‌شود.
- ورود rate limit سمت session دارد.
- فایل‌های حساس خارج از public نگهداری می‌شوند؛ برای بکاپ Production بهتر است مسیر بکاپ خارج از DocumentRoot تنظیم شود.

## تاریخ شمسی

تاریخ‌ها در دیتابیس به‌صورت Gregorian استاندارد ذخیره می‌شوند و در UI توسط `App\Support\Jalali` به شمسی نمایش داده/از شمسی تبدیل می‌شوند.

## توسعه آینده

برای ماژول‌های CRUD جدید کافی است جدول دیتابیس و تعریف `config/modules.php` اضافه شود. برای منطق پیچیده سرویس اختصاصی در `app/Services` ایجاد می‌شود.
