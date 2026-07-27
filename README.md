# سامانه فارسی مدیریت مرکز ماساژ و CRM مشتریان

یک وب‌اپلیکیشن PHP 8.2+ و MySQL/MariaDB برای مدیریت واقعی مرکز ماساژ: CRM مشتری، نوبت‌دهی، جلسات ماساژ، پیگیری خودکار، مالی، حقوق درمانگران، گزارش‌ها، تنظیمات برند، تم روشن/تاریک، RTL فارسی و تاریخ شمسی.

## معماری کوتاه

- Backend: PHP 8.2، MVC سبک و ماژولار، PDO Prepared Statements
- Database: MySQL 8 یا MariaDB 10.6+
- Frontend: Bootstrap RTL، Chart.js، فونت Vazirmatn، طراحی Responsive
- Security: Password Hashing، Session امن، CSRF، RBAC، Audit Log، Soft Delete، Validation پایه، Rate Limit ورود
- Extensibility: فایل `config/modules.php` برای افزودن ماژول‌های CRUD، سرویس‌های جداگانه برای Follow-up، Salary، Backup و Notification abstraction
- Multi-branch ready: ستون `branch_id` در جداول کلیدی برای توسعه چند شعبه

## ویژگی‌های پیاده‌سازی‌شده

- ورود امن و نقش‌ها: مدیر کل، مدیر، پذیرش، درمانگر، حسابدار
- مدیریت کاربران، نقش/مجوز، فعال/غیرفعال‌سازی
- CRM مشتریان با پروفایل، برچسب، منبع معرفی، وضعیت، یادداشت و تایم‌لاین
- خدمات/انواع ماساژ با قیمت، مدت و قانون پورسانت
- مدیریت درمانگران با مدل‌های حقوقی مختلف
- نوبت‌دهی با وضعیت‌ها و جلوگیری از رزرو همزمان یک درمانگر
- ثبت جلسات ماساژ، مبلغ، تخفیف، پرداخت، رضایت و یادداشت‌ها
- ایجاد خودکار پیگیری پس از جلسه بر اساس تنظیمات مشتری/خدمت/عمومی
- مرکز پیگیری: امروز، ۷ روز آینده، معوق و ثبت نتیجه تماس
- داشبورد اجرایی با آمار روزانه/ماهانه و نمودار درآمد
- هوشمندی نگهداشت مشتری بر اساس RFM و بخش‌بندی VIP/active/at-risk/lost
- مالی، هزینه‌ها، گزارش سود و خروجی CSV
- محاسبه حقوق و پورسانت درمانگران
- پکیج‌ها و عضویت‌ها
- انبار و حداقل موجودی
- کمپین‌های بازاریابی و معماری آماده اتصال SMS/WhatsApp/Email
- تنظیمات برند، رنگ، تماس، آدرس، بازه پیگیری و تم
- Audit Logs
- بکاپ دستی و دستور CLI برای بکاپ/زمان‌بندی
- UI کاملاً فارسی، RTL، واکنش‌گرا و دارای تم روشن/تاریک

## نیازمندی‌ها

- PHP 8.2 یا بالاتر با افزونه‌های `pdo_mysql`, `mbstring`, `json`
- MySQL 8+ یا MariaDB 10.6+
- وب‌سرور Apache یا Nginx
- برای بکاپ: ابزار `mysqldump`

## نصب محلی روی Linux

```bash
cp .env.example .env
# اطلاعات DB را در .env تنظیم کنید
mysql -u root -p -e "CREATE DATABASE massage_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/console install
php -S 127.0.0.1:8000 -t public
```

سپس آدرس `http://127.0.0.1:8000` را باز کنید.

## نصب روی Windows با XAMPP/Laragon

1. پروژه را داخل `htdocs` یا مسیر سایت Laragon قرار دهید.
2. یک دیتابیس با نام `massage_crm` و Collation `utf8mb4_unicode_ci` بسازید.
3. فایل `.env.example` را به `.env` کپی کنید و اطلاعات دیتابیس را تنظیم کنید.
4. از phpMyAdmin فایل `database/schema.sql` را Import کنید یا در ترمینال اجرا کنید:
   ```bash
   php bin/console install
   ```
5. DocumentRoot را روی پوشه `public` تنظیم کنید.

## حساب پیش‌فرض

- Email: `admin@example.com`
- Password: `password`

بعد از اولین ورود حتماً رمز عبور را تغییر دهید.

## راه‌اندازی Production روی Ubuntu + Nginx + PHP-FPM

```bash
sudo apt update
sudo apt install nginx mysql-server php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-cli
sudo mysql -e "CREATE DATABASE massage_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'massage_user'@'localhost' IDENTIFIED BY 'StrongPassword';"
sudo mysql -e "GRANT ALL PRIVILEGES ON massage_crm.* TO 'massage_user'@'localhost'; FLUSH PRIVILEGES;"
cp .env.example .env
# DB_USERNAME و DB_PASSWORD را تنظیم کنید
php bin/console install
sudo chown -R www-data:www-data public/uploads public/storage
sudo chmod -R 775 public/uploads public/storage
```

### نمونه Nginx

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/massage/public;
    index index.php;
    charset utf-8;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### SSL

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d example.com
```

## Cron و زمان‌بندی

برای تولید خودکار پیگیری‌ها، هشدار موجودی و کارهای زمان‌بندی‌شده:

```cron
* * * * * cd /var/www/massage && php bin/console schedule:run >> storage-cron.log 2>&1
0 2 * * * cd /var/www/massage && php bin/console backup:create >> backup-cron.log 2>&1
```

## بکاپ و بازیابی

- بکاپ دستی از پنل «پشتیبان‌گیری» یا دستور:
  ```bash
  php bin/console backup:create
  ```
- فایل‌ها در `public/storage/backups` ذخیره می‌شوند. در محیط Production بهتر است این مسیر را خارج از DocumentRoot قرار دهید و با سیاست دسترسی محدود نگهداری کنید.
- بازیابی:
  ```bash
  mysql -u massage_user -p massage_crm < backup-file.sql
  ```

## تنظیمات مهم PHP-FPM

پیشنهادها:

```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 120
session.cookie_httponly = 1
session.cookie_samesite = Lax
```

در Production مقدار زیر را در `.env` قرار دهید:

```dotenv
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE=true
```

## چک‌لیست امنیت

- تغییر رمز مدیر پیش‌فرض
- استفاده از HTTPS و `SESSION_SECURE=true`
- محدود کردن دسترسی مستقیم به `.env` و فایل‌های بکاپ
- استفاده از کاربر دیتابیس اختصاصی با حداقل دسترسی لازم
- بکاپ روزانه و تست دوره‌ای بازیابی
- به‌روزرسانی PHP/Nginx/MySQL
- محدودسازی IP پنل مدیریت در صورت نیاز
- تنظیم Firewall و Fail2ban روی سرور

## توسعه و افزودن ماژول جدید

1. جدول را در Migration اضافه کنید.
2. تعریف ماژول را به `config/modules.php` اضافه کنید.
3. مجوزهای نقش‌ها را در Seed یا پنل کاربران اضافه کنید.
4. اگر منطق خاص لازم است، سرویس جدید در `app/Services` بسازید.

## پیشنهادهای آینده

- API REST کامل برای اپ موبایل
- پنل مشتری و رزرو آنلاین
- اتصال واقعی SMS، WhatsApp Business، Email و Telegram از طریق `NotificationChannel`
- پرداخت آنلاین و فاکتور رسمی
- چندزبانه و چندارزی
- مدیریت پیشرفته شیفت‌ها، تعطیلات و اتاق‌ها
- BI پیشرفته و تحلیل AI نگهداشت مشتری
