# آموزش گام‌به‌گام نصب و راه‌اندازی سامانه مدیریت مرکز ماساژ

این سامانه یک وب‌اپلیکیشن PHP برای مدیریت مرکز ماساژ شامل CRM مشتریان، نوبت‌دهی، مدیریت مالی و گزارش‌ها است. در این آموزش، نصب این سامانه را روی سیستم‌عامل‌های مختلف یاد خواهید ­گرفت.

---

## فهرست مطالب

1. [پیش‌نیازها](#پیش‌نیازها)
2. [نصب روی Ubuntu (سرور واقعی)](#نصب-روی-ubuntu-سرور-واقعی)
3. [نصب روی Windows با XAMPP](#نصب-روی-windows-با-xampp)
4. [نصب روی aaPanel (پنل هاستینگ)](#نصب-روی-aapanel-پنل-هاستینگ)
5. [تنظیمات بعد از نصب](#تنظیمات-بعد-از-نصب)
6. [عیب‌یابی](#عیب‌یابی)
7. [امنیت و نکات مهم](#امنیت-و-نکات-مهم)
8. [بکاپ و بازیابی](#بکاپ-و-بازیابی)

---

## پیش‌نیازها

قبل از شروع نصب، مطمئن شوید که سیستم شما این شرایط را دارد:

| نیاز | حداقل نسخه |
|------|------------|
| PHP | 8.2 یا بالاتر |
| MySQL | 8+ یا MariaDB 10.6+ |
| وب‌سرور | Apache یا Nginx |
| افزونه‌های PHP | pdo_mysql, mbstring, json |
| mysqldump | برای بکاپ خودکار |

---

## نصب روی Ubuntu (سرور واقعی)

### مرحله ۱: به‌روزرسانی سیستم

```bash
sudo apt update && sudo apt upgrade -y
```

### مرحله ۲: نصب وب‌سرور Nginx

```bash
sudo apt install nginx -y
sudo systemctl start nginx
sudo systemctl enable nginx
```

### مرحله ۳: نصب PHP 8.2 و افزونه‌ها

```bash
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-cli php8.2-json php8.2-curl php8.2-xml php8.2-zip -y
```

**بررسی نصب PHP:**
```bash
php -v
```

### مرحله ۴: نصب MySQL

```bash
sudo apt install mysql-server -y
sudo mysql_secure_installation
```

در این مرحله از شما سوالاتی پرسیده می‌شود:
- رمز عبور root را تنظیم کنید
- حذف دیتابیس تست: بله
- حذف کاربر anonymous: بله
- غیرفعال کردن ورود ریموت root: بله

### مرحله ۵: ساخت دیتابیس

```bash
sudo mysql -u root -p
```

وقتی وارد MySQL شدید:
```sql
CREATE DATABASE massage_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'massage_user'@'localhost' IDENTIFIED BY 'رمز-ع-بور-قوی-شما';
GRANT ALL PRIVILEGES ON massage_crm.* TO 'massage_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### مرحله ۶: آپلود فایل‌های پروژه

```bash
sudo mkdir -p /var/www/massage
sudo chown -R $USER:$USER /var/www/massage
```

فایل‌های پروژه را به مسیر `/var/www/massage` کپی کنید. اگر از Git استفاده می‌کنید:
```bash
cd /var/www/massage
git clone <آدرس-مخزن> .
```

### مرحله ۷: تنظیم فایل محیطی (.env)

```bash
cd /var/www/massage
cp .env.example .env
nano .env
```

مقادیر زیر را ویرایش کنید:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=massage_crm
DB_USERNAME=massage_user
DB_PASSWORD=رمز-ع-بور-قوی-شما

SESSION_SECURE=true
```

### مرحله ۸: نصب سامانه

```bash
cd /var/www/massage
php bin/console install
```

### مرحله ۹: تنظیم مجوزهای فایل

```bash
sudo chown -R www-data:www-data /var/www/massage
sudo chmod -R 755 /var/www/massage
sudo chmod -R 775 /var/www/massage/public/uploads
sudo chmod -R 775 /var/www/massage/public/storage
```

### مرحله ۱۰: تنظیم Nginx

```bash
sudo nano /etc/nginx/sites-available/massage
```

محتوای زیر را وارد کنید:
```nginx
server {
    listen 80;
    server_name example.com www.example.com;
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

فعال‌سازی سایت:
```bash
sudo ln -s /etc/nginx/sites-available/massage /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### مرحله ۱۱: نصب SSL (اختصاری)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d example.com -d www.example.com
```

### مرحله ۱۲: تنظیم Cron Jobs

```bash
sudo crontab -e
```

خطوط زیر را اضافه کنید:
```cron
* * * * * cd /var/www/massage && php bin/console schedule:run >> storage-cron.log 2>&1
0 2 * * * cd /var/www/massage && php bin/console backup:create >> backup-cron.log 2>&1
```

### مرحله ۱۳: تنظیمات PHP-FPM

```bash
sudo nano /etc/php/8.2/fpm/php.ini
```

مقادیر زیر را پیدا و ویرایش کنید:
```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 120
session.cookie_httponly = 1
session.cookie_samesite = Lax
```

سپس PHP-FPM را ریستارت کنید:
```bash
sudo systemctl restart php8.2-fpm
```

---

## نصب روی Windows با XAMPP

### مرحله ۱: دانلود و نصب XAMPP

1. به سایت [Apache Friends](https://www.apachefriends.org/) بروید
2. نسخه با PHP 8.2 یا بالاتر را دانلود کنید
3. XAMPP را نصب کنید (مسیر پیش‌فرض: `C:\xampp`)

### مرحله ۲: شروع سرویس‌ها

1. XAMPP Control Panel را باز کنید
2. Apache و MySQL را Start کنید

### مرحله ۳: کپی فایل‌های پروژه

فایل‌های پروژه را در مسیر زیر کپی کنید:
```
C:\xampp\htdocs\massage
```

### مرحله ۴: ساخت دیتابیس

1. مرورگر را باز کنید و به آدرس `http://localhost/phpmyadmin` بروید
2. روی «Databases» کلیک کنید
3. نام دیتابیس را `massage_crm` وارد کنید
4. Collation را `utf8mb4_unicode_ci` انتخاب کنید
5. روی «Create» کلیک کنید

### مرحله ۵: تنظیم فایل محیطی

1. به مسیر `C:\xampp\htdocs\massage` بروید
2. فایل `.env.example` را کپی کنید و نام آن را به `.env` تغییر دهید
3. فایل `.env` را با Notepad باز کنید و مقادیر زیر را ویرایش کنید:

```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/massage

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=massage_crm
DB_USERNAME=root
DB_PASSWORD=

SESSION_SECURE=false
```

**نکته:** در XAMPP پیش‌فرض، رمز عبور MySQL خالی است.

### مرحله ۶: نصب سامانه

CMD (خط فرمان) را باز کنید:
```cmd
cd C:\xampp\htdocs\massage
php bin\console install
```

### مرحله ۷: تنظیم DocumentRoot (اختصاری)

برای اینکه سامانه با آدرس `http://localhost/massage` کار کند:

1. فایل `C:\xampp\apache\conf\extra\httpd-vhosts.conf` را باز کنید
2. بلوک زیر را اضافه کنید:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/massage/public"
    ServerName massage.local
    <Directory "C:/xampp/htdocs/massage/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

3. فایل `C:\Windows\System32\drivers\etc\hosts` را ویرایش کنید و خط زیر را اضافه کنید:
```
127.0.0.1 massage.local
```

4. Apache را ریستارت کنید

### مرحله ۸: دسترسی به سامانه

مرورگر را باز کنید:
```
http://localhost/massage
```
یا اگر VirtualHost تنظیم کردید:
```
http://massage.local
```

---

## نصب روی aaPanel (پنل هاستینگ)

aaPanel یک پنل مدیریت سرور رایگان است که نصب وب‌سرور و دیتابیس را بسیار آسان می‌کند.

### مرحله ۱: نصب aaPanel

```bash
URL=$(ifconfig eth0 | grep inet | grep -v inet6 | awk '{print $2}') && echo $URL
```

**برای Ubuntu/Debian:**
```bash
wget -O install.sh https://www.aapanel.com/script/install-ubuntu_6.0_en.sh && sudo bash install.sh aapanel
```

**برای CentOS:**
```bash
yum install -y wget && wget -O install.sh https://www.aapanel.com/script/install_6.0_en.sh && bash install.sh aapanel
```

پس از نصب، آدرس پنل و نام کاربری/رمز عبور نمایش داده می‌شود. آن‌ها را یادداشت کنید.

### مرحله ۲: ورود به پنل aaPanel

1. آدرس نمایش داده شده در مرورگر باز کنید (معمولاً `http://IP:8888`)
2. با نام کاربری و رمز عبور وارد شوید

### مرحله ۳: نصب پکیج‌های پایه

وقتی برای اولین بار وارد می‌شوید، aaPanel پکیج‌های پیشنهادی نصب را نشان می‌دهد:

1. **LNMP Stack** را انتخاب کنید (Nginx + MySQL + PHP)
2. مطمئن شوید این موارد نصب می‌شوند:
   - Nginx (هر نسخه‌ای)
   - MySQL 8.0 یا MariaDB 10.6
   - PHP 8.2
3. روی «One-Click Install» کلیک کنید و منتظر بمانید

### مرحله ۴: تنظیم PHP 8.2

1. از منوی سمت چپ روی «App Store» کلیک کنید
2. PHP 8.2 را پیدا کنید و روی «Settings» کلیک کنید
3. تب «Install extensions» را انتخاب کنید
4. این افزونه‌ها را نصب کنید:
   - `pdo_mysql`
   - `mbstring`
   - `json`
   - `curl`
   - `xml`
   - `zip`

### مرحله ۵: ساخت سایت

1. از منوی سمت چپ روی «Website» کلیک کنید
2. روی «Add site» کلیک کنید
3. اطلاعات را وارد کنید:
   - **Domain:** دامنه یا IP سرور شما
   - **PHP Version:** PHP-8.2 را انتخاب کنید
4. روی «Submit» کلیک کنید

### مرحله ۶: ساخت دیتابیس

1. از منوی سمت چپ روی «Database» کلیک کنید
2. روی «Add database» کلیک کنید
3. اطلاعات را وارد کنید:
   - **Database Name:** `massage_crm`
   - **Username:** `massage_user`
   - **Password:** یک رمز عبور قوی انتخاب کنید
   - **Permission:** `Local server`
4. روی «Submit» کلیک کنید

### مرحله ۷: آپلود فایل‌های پروژه

1. از منوی سمت چپ روی «Files» کلیک کنید
2. به مسیر سایت بروید (معمولاً `/www/wwwroot/your-domain.com`)
3. فایل‌های پروژه را آپلود کنید:
   - روی «Upload» کلیک کنید
   - فایل ZIP پروژه را انتخاب کنید
   - بعد از آپلود، روی فایل ZIP راست‌کلیک کنید و «Uncompress» را بزنید
4. فایل‌های پروژه باید مستقیماً در پوشه سایت قرار بگیرند (نه در یک زیرپوشه اضافی)

### مرحله ۸: تنظیم DocumentRoot

1. از منوی سمت چپ روی «Website» کلیک کنید
2. روی نام سایت خود کلیک کنید
3. در تب «Site directory»:
   - مسیر را به `/www/wwwroot/your-domain.com/public` تغییر دهید
4. روی «Save» کلیک کنید

### مرحله ۹: تنظیم فایل محیطی

از بخش «Files» یا SSH:
```bash
cd /www/wwwroot/your-domain.com
cp .env.example .env
```

فایل `.env` را ویرایش کنید:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=massage_crm
DB_USERNAME=massage_user
DB_PASSWORD=رمز-ع-بوری-که-در-مرحله-6-تنظیم-کردید

SESSION_SECURE=true
```

### مرحله ۱۰: نصب سامانه

از طریق SSH یا «Terminal» در aaPanel:
```bash
cd /www/wwwroot/your-domain.com
php bin/console install
```

### مرحله ۱۱: تنظیم مجوزهای فایل

```bash
chown -R www:www /www/wwwroot/your-domain.com
chmod -R 755 /www/wwwroot/your-domain.com
chmod -R 775 /www/wwwroot/your-domain.com/public/uploads
chmod -R 775 /www/wwwroot/your-domain.com/public/storage
```

### مرحله ۱۲: تنظیم SSL

1. از منوی سمت چپ روی «Website» کلیک کنید
2. روی نام سایت خود کلیک کنید
3. تب «SSL» را انتخاب کنید
4. روی «Let's Encrypt» کلیک کنید
5. دامنه خود را انتخاب کنید و روی «Apply» کلیک کنید
5. «Force HTTPS» را فعال کنید

### مرحله ۱۳: تنظیم Cron Jobs

1. از منوی سمت چپ روی «Cron» کلیک کنید
2. روی «Add cron» کلیک کنید
3. دو کرون جاب بسازید:

**کرون جاب اول (هر دقیقه):**
- **Type:** Shell Script
- **Name:** massage-scheduler
- **Period:** Every 1 minute
- **Script:**
```bash
cd /www/wwwroot/your-domain.com && php bin/console schedule:run >> storage-cron.log 2>&1
```

**کرون جاب دوم (هر روز ساعت ۲ صبح):**
- **Type:** Shell Script
- **Name:** massage-backup
- **Period:** Daily at 02:00
- **Script:**
```bash
cd /www/wwwroot/your-domain.com && php bin/console backup:create >> backup-cron.log 2>&1
```

### مرحله ۱۴: تنظیمات PHP

1. از «App Store» روی PHP 8.2 کلیک کنید و «Settings» را بزنید
2. تب «Performance tuning» را انتخاب کنید
3. مقادیر زیر را تنظیم کنید:
   - `upload_max_filesize`: 10M
   - `post_max_size`: 12M
   - `memory_limit`: 256M
   - `max_execution_time`: 120
4. روی «Save» کلیک کنید

---

## تنظیمات بعد از نصب

### ورود به سامانه

آدرس سامانه را در مرورگر باز کنید و با اطلاعات زیر وارد شوید:

| فیلد | مقدار |
|------|-------|
| ایمیل | `admin@example.com` |
| رمز عبور | `password` |

**⚠️ مهم:** بعد از اولین ورود حتماً رمز عبور را تغییر دهید!

### تغییر رمز عبور

اگر به هر دلیلی نتوانستید وارد شوید:

```bash
php bin/console seed
```

یا رمز دلخواه خود را تنظیم کنید:
```bash
php bin/console user:password admin@example.com رمز-جدید-شما
```

---

## عیب‌یابی

### مشکل: صفحه سفید نمایش داده می‌شود

**راه‌حل:**
1. بررسی کنید که PHP نصب است: `php -v`
2. فایل لاگ خطا را بررسی کنید:
   - Ubuntu: `/var/log/nginx/error.log`
   - XAMPP: `C:\xampp\apache\logs\error.log`
   - aaPanel: `/www/server/nginx/logs/error.log`
3. مطمئن شوید DocumentRoot به پوشه `public` اشاره می‌کند

### مشکل: خطای 500 Internal Server Error

**راه‌حل:**
1. مجوزهای فایل را بررسی کنید
2. فایل `.env` وجود دارد و تنظیمات دیتابیس صحیح است
3. دستور نصب را دوباره اجرا کنید: `php bin/console install`

### مشکل: اتصال به دیتابیس برقرار نمی‌شود

**راه‌حل:**
1. اطلاعات دیتابیس در فایل `.env` را بررسی کنید
2. MySQL در حال اجرا است: `sudo systemctl status mysql`
3. کاربر دیتابیس مجوز دسترسی دارد

### مشکل: خطای Permission Denied

**راه‌حل (Linux/Ubuntu/aaPanel):**
```bash
sudo chown -R www-data:www-data /var/www/massage  # Ubuntu
sudo chown -R www:www /www/wwwroot/your-domain.com  # aaPanel
sudo chmod -R 755 /var/www/massage
sudo chmod -R 775 /var/www/massage/public/uploads
sudo chmod -R 775 /var/www/massage/public/storage
sudo chmod -R 775 /var/www/massage/storage
```

### مشکل: بعد از وارد کردن ایمیل و رمز عبور، دوباره فرم ورود نمایش داده می‌شود (بدون هیچ خطایی)

این یعنی اطلاعات ورود صحیح بوده اما «نشست» (Session) بین صفحات حفظ نمی‌شود.

**راه‌حل:**
1. مطمئن شوید پوشه `storage/sessions` وجود دارد و توسط وب‌سرور قابل نوشتن است (سامانه به‌صورت خودکار از آن به‌عنوان مسیر ذخیره نشست استفاده می‌کند وقتی مسیر پیش‌فرض PHP خراب است):
   ```bash
   mkdir -p storage/sessions
   sudo chown -R www-data:www-data storage
   sudo chmod -R 775 storage
   ```
2. اگر سایت بدون HTTPS اجرا می‌شود، در فایل `.env` حتماً `SESSION_SECURE=false` باشد؛ وگرنه مرورگر کوکی نشست را برنمی‌گرداند.
3. کوکی‌های مرورگر را بررسی کنید (حالت ناشناس/افزونه‌های مسدودکننده را تست کنید یا با مرورگر دیگری امتحان کنید).
4. اگر با `php -S` یا وب‌سرور دیگری اجرا می‌کنید، صفحه را با همان آدرسی باز کنید که کوکی برای آن صادر شده است (مثلاً `localhost` با `127.0.0.1` متفاوت است).

### مشکل: رمز عبور مدیر را نمی‌دانم / فراموش کرده‌ام

**راه‌حل:** رمز را از خط فرمان بازنشانی کنید:
```bash
php bin/console user:password admin@example.com رمز_جدید
```
سپس با ایمیل و رمز جدید وارد شوید.

---

## امنیت و نکات مهم

### چک‌لیست امنیتی

- [ ] رمز عبور پیش‌فرض مدیر را تغییر دهید
- [ ] HTTPS را فعال کنید و `SESSION_SECURE=true` تنظیم کنید
- [ ] دسترسی مستقیم به فایل `.env` را مسدود کنید
- [ ] از کاربر دیتابیس اختصاصی با حداقل دسترسی استفاده کنید
- [ ] بکاپ روزانه تنظیم کنید
- [ ] PHP، Nginx/Apache و MySQL را به‌روز نگه دارید
- [ ] فایروال سرور را تنظیم کنید
- [ ] Fail2ban نصب کنید

### تنظیمات امنیتی Production

در فایل `.env`:
```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE=true
```

---

## بکاپ و بازیابی

### بکاپ دستی

از داخل سامانه، به بخش «پشتیبان‌گیری» بروید یا دستور زیر اجرا کنید:
```bash
php bin/console backup:create
```

فایل‌های بکاپ در مسیر `public/storage/backups` ذخیره می‌شوند.

### بازیابی بکاپ

```bash
mysql -u massage_user -p massage_crm < فایل-بکاپ.sql
```

### بکاپ خودکار

با تنظیم Cron Jobs که در بالا توضیح داده شد، بکاپ خودکار هر روز ساعت ۲ صبح انجام می‌شود.

---

## اطلاعات فنی

### ویژگی‌های سامانه

- مدیریت مشتریان با CRM پیشرفته
- نوبت‌دهی آنلاین
- مدیریت مالی و حقوق درمانگران
- گزارش‌های متنوع و نمودارها
- پشتیبانی از تاریخ شمسی
- رابط کاربری فارسی و RTL
- تم روشن و تاریک
- مدیریت شعب (آماده برای چند شعبه)

### نقش‌های کاربری

| نقش | دسترسی |
|------|--------|
| مدیر کل | دسترسی کامل به همه بخش‌ها |
| مدیر | مدیریت عملیاتی مرکز |
| پذیرش | نوبت‌دهی و پذیرش مشتری |
| درمانگر | مشاهده نوبت‌ها و جلسات خود |
| حسابدار | مدیریت مالی و گزارش‌ها |

---

## پشتیبانی

در صورت بروز مشکل:
1. فایل لاگ سرور را بررسی کنید
2. از دستورات عیب‌یابی بالا استفاده کنید
3. مطمئن شوید تمام پیش‌نیازها نصب شده‌اند

---

**موفق باشید! 🎉**
