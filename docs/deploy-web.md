# نسخهٔ وب (web copy) روی هاست

نسخهٔ وب همان برنامه است با `role = web`. سه کار دارد:

- سفارش‌های QR سر میز و سفارش‌های آنلاین را می‌گیرد. کامپیوتر صندوق آن‌ها را چند ثانیه بعد برمی‌دارد.
- مدیر از بیرون رستوران با ایمیل و رمز وارد می‌شود و گزارش‌ها را می‌بیند یا تنظیمات را عوض می‌کند.
- یک کپی رمزگذاری‌شده از پشتیبان شبانهٔ کامپیوتر صندوق را نگه می‌دارد.

اگر کامپیوتر صندوق بیش از ۶۰ ثانیه تماس نگیرد (اینترنت رستوران قطع است)، سفارش QR و آنلاین خودکار بسته می‌شود و منو همچنان دیده می‌شود.

## پیش‌نیاز

- PHP 8.1 یا بالاتر با افزونه‌های `pdo_sqlite`، `sqlite3`، `sodium`، `zip`، `curl`، `mbstring`، `gd`، `intl`
- HTTPS
- یک زیردامنه، مثلاً `pos.basiliccaferestaurant.com`

## مراحل (OVH، هاست اشتراکی چندسایتی)

1. در پنل OVH، در بخش **Multisite**، زیردامنهٔ جدید را اضافه کنید:
   - پوشهٔ ریشه: `sofrexa/app/public`
   - SSL: فعال
   > **به فایل `.ovhconfig` در ریشهٔ هاست دست نزنید.** همان نسخهٔ PHP سایت فعلی استفاده می‌شود.
2. در Cloudflare یک رکورد DNS برای `pos` بسازید (Proxied) و SSL را روی **Full** بگذارید.
3. با WinSCP این پوشه‌ها را بفرستید:
   - `app/` از مخزن ← `sofrexa/app/`، بدون `config.php`
   - یک پوشهٔ خالی `sofrexa/storage/` بسازید. این پوشه بیرون از ریشهٔ وب است و از اینترنت دیده نمی‌شود.
4. فایل `sofrexa/app/config.php` را روی هاست بسازید:

```php
<?php
return [
    'role' => 'web',
    'device_name' => 'web',
    'base_url' => 'https://pos.basiliccaferestaurant.com',
    'storage' => __DIR__ . '/../storage',
    'db' => __DIR__ . '/../storage/db/sofrexa.sqlite',
    'sync' => ['key' => 'SAME-LONG-RANDOM-KEY-AS-ON-THE-PC'],   // at least 24 characters
    'turnstile' => ['site_key' => '', 'secret' => ''],          // optional, for remote sign-in
    'mail' => ['driver' => 'smtp', 'from' => 'noreply@basiliccaferestaurant.com',
               'smtp' => ['host' => 'ssl0.ovh.net', 'port' => 465, 'secure' => 'ssl', 'user' => '...', 'pass' => '...']],
    'pin_networks' => ['127.0.0.1'],   // PIN sign-in is not used on the web copy
];
```

5. روی کامپیوتر صندوق، در `C:\Sofrexa\app\config.php`:
   - `sync.remote_url` را روی `https://pos.basiliccaferestaurant.com` بگذارید.
   - `sync.key` را برابر همان کلید بگذارید.

   کلید را یک بار بسازید، مثلاً با `php -r "echo bin2hex(random_bytes(24));"`، و فقط در این دو فایل نگه دارید.
6. کار **Sofrexa Worker** روی کامپیوتر صندوق چند ثانیه بعد:
   - یک کپی کامل و رمزگذاری‌شده (پایگاه داده و عکس‌ها) را تکه‌تکه می‌فرستد.
   - بعد از آن فقط تغییرات را می‌فرستد.
7. وضعیت اتصال را بررسی کنید:
   - «Ayarlar › Senkron ve yedek» باید «Senkronize» نشان دهد.
   - «son eşitleme» باید چند ثانیه پیش باشد.

## منوی سایت از Sofrexa

بعد از فعال شدن، منو فقط در Sofrexa ویرایش می‌شود. نسخهٔ وب هر تغییر منو را چند ثانیه بعد در جدول‌های خود سایت می‌نویسد: قیمت، نام به چهار زبان، عکس، «تمام شد» و پنهان/نمایان. به همین دلیل کد نمایش سایت تغییری نمی‌خواهد.

در `sofrexa/app/config.php` روی هاست:

```php
'website_menu' => [
    'dsn' => 'mysql:host=...;dbname=...;charset=utf8mb4',   // the website's database (same values as its own config)
    'user' => '...', 'pass' => '...',
    'uploads' => '/home/.../public_html/uploads',             // the website's uploads folder
],
```

- برای همگام‌سازی کامل با دست: `php app/bin/sofrexa website:sync`
- هیچ ردیفی در سایت حذف نمی‌شود. آیتم پنهان یا حذف‌شده فقط `is_active = 0` می‌شود.
- بعد از فعال شدن، منو را از پنل ادمین سایت ویرایش نکنید. تغییرات آن‌جا با همگام‌سازی بعدی جایگزین می‌شوند.

## کدهای QR فعلی میزها

جدول‌های `areas` و `dining_tables` سایت اصلی (روی هاست) را وارد کنید:

1. از پایگاه دادهٔ سایت یک کپی SQLite یا خروجی بگیرید.
2. `php app/bin/sofrexa import:website <copy.sqlite> <uploads>` را اجرا کنید.

کارت‌های چاپ‌شده بدون تغییر کار می‌کنند، چون لینک همان `/menu?masa=<کد>` است.

## امنیت

- همهٔ درخواست‌های همگام‌سازی با کلید مشترک و HMAC-SHA256 امضا می‌شوند. درخواستی که بیش از ۵ دقیقه قدیمی باشد رد می‌شود.
- snapshot و پشتیبان‌ها با libsodium (XChaCha20-Poly1305) رمزگذاری می‌شوند.
- پایگاه داده، فایل‌های بارگذاری‌شده، لاگ‌ها و پشتیبان‌ها در `storage/`، بیرون از ریشهٔ وب، هستند.
- ورود کارکنان روی نسخهٔ وب فقط با ایمیل و رمز است و فقط برای کاربرانی که «Uzaktan giriş» دارند.
- آدرس IP واقعی بازدیدکننده فقط وقتی از هدر `CF-Connecting-IP` خوانده می‌شود که درخواست واقعاً از Cloudflare آمده باشد (بازه‌های رسمی Cloudflare). اگر جلوی سرور پروکسی دیگری هست، بازهٔ آن را در `config.php` اضافه کنید: `'trusted_proxies' => ['cloudflare', '10.0.0.0/8']`.
