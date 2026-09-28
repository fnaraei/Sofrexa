# نصب Sofrexa روی کامپیوتر صندوق (ویندوز)

کامپیوتر صندوق «سرور محلی» رستوران است. گوشی گارسون‌ها، TV آشپزخانه و خود صندوق از طریق شبکهٔ داخلی به آن وصل می‌شوند.
اگر اینترنت قطع شود، همه‌چیز در رستوران کار می‌کند. فقط سفارش QR و آنلاین تا وصل شدن دوباره بسته می‌ماند.

## نصب اول

1. PHP 8.3 نسخهٔ **VS16 x64 Non Thread Safe** را به‌صورت zip از <https://windows.php.net/download/> بگیرید.
2. پوشهٔ این مخزن را روی کامپیوتر صندوق بگذارید.
3. PowerShell را با **Run as administrator** باز کنید و این دستور را اجرا کنید:

```powershell
powershell -ExecutionPolicy Bypass -File install\windows\install.ps1 -PhpZip C:\Users\...\Downloads\php-8.3.x-nts-Win32-vs16-x64.zip
```

4. اولین مدیر را بسازید:

```powershell
C:\Sofrexa\php\php.exe C:\Sofrexa\app\bin\sofrexa user:add "نام مدیر" manager 1234 email@example.com
```

   بعد از ورود، PIN را از بخش «Personel › Kullanıcılar» عوض کنید و بقیهٔ کارکنان را اضافه کنید.

5. منوی سایت را وارد کنید (اختیاری):

```powershell
C:\Sofrexa\php\php.exe C:\Sofrexa\app\bin\sofrexa import:website <website.sqlite> <website uploads folder>
```

## نصب چه کارهایی می‌کند

- برنامه را در `C:\Sofrexa\app` و داده‌ها را در `C:\Sofrexa\storage` می‌گذارد.
- اجرای دوباره برای به‌روزرسانی امن است: داده‌ها و `config.php` دست نمی‌خورند.
- دو کار زمان‌بندی‌شده می‌سازد که با روشن شدن ویندوز بالا می‌آیند و اگر بسته شوند دوباره اجرا می‌شوند:
  - **Sofrexa Web**: سرور محلی روی پورت 80
  - **Sofrexa Worker**: چاپ فیش‌ها، همگام‌سازی با نسخهٔ وب، پشتیبان شبانه
- پورت را فقط برای شبکهٔ خصوصی رستوران در فایروال باز می‌کند.
- میان‌بر «Sofrexa Kasa» را روی دسکتاپ می‌گذارد (Edge در حالت برنامه، تمام‌صفحه).
- ورود با PIN را به شبکهٔ داخلی همین کامپیوتر محدود می‌کند.

## پرینترها

در «Ayarlar › Yazıcılar» برای هر پرینتر یکی از این اتصال‌ها را انتخاب کنید و دکمهٔ **Test** را بزنید:

| اتصال | مقدار |
|---|---|
| پرینتر شبکه (IP) | `192.168.1.60:9100` |
| پرینتر USB روی همین PC | پرینتر را در ویندوز Share کنید و نام اشتراک را بنویسید، مثل `POS80` |
| پرینتر روی کامپیوتر دیگر | `\\MUTFAK-PC\Thermal80` |

## وصل کردن به نسخهٔ وب

بعد از راه‌اندازی نسخهٔ وب ([docs/deploy-web.md](../../docs/deploy-web.md)) در `C:\Sofrexa\app\config.php` این را پر کنید. کلید در هر دو طرف یکی است:

```php
'sync' => ['remote_url' => 'https://pos.example.com', 'key' => '...'],
```

اولین همگام‌سازی یک کپی کامل و رمزگذاری‌شده می‌فرستد. بعد از آن فقط تغییرات، هر چند ثانیه یک بار، فرستاده می‌شوند.

## حذف

```powershell
powershell -ExecutionPolicy Bypass -File install\windows\uninstall.ps1
```

کارها و قانون فایروال حذف می‌شوند. داده‌ها می‌مانند.
