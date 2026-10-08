# caffenet/bazaar-iap

کتابخانهٔ **پرداخت و ورود درون‌برنامه‌ای کافه‌بازار** برای اپلیکیشن‌های وب تحت لاراول
(PWA یا وبه‌ویو داخل اپ کافه‌بازار).

این بسته از دیپ‌لینک‌های کافه‌بازار (`bazaar://inapplogin` و `bazaar://in_app`) برای
ورود و شروع پرداخت استفاده می‌کند و فراخوانی **مصرف خرید (consume)** را — برخلاف نمونهٔ
مرجع PWA — کاملاً **سمت سرور** انجام می‌دهد؛ بنابراین کلید `API_SECRET` هرگز به مرورگر
نمی‌رسد.

## ویژگی‌ها

- ساخت دیپ‌لینک ورود و پرداخت + ساخت `redirectUrl` بازگشت با پارامترهای `visit/status/sku`.
- فراخوانی REST کافه‌بازار برای **مصرف خرید** و **اعتبارسنجی خرید** با هدر
  `CAFEBAZAAR-PISHKHAN-API-SECRET` (فقط روی سرور).
- مدیریت «ورود و سپس پرداخت» (login-first) به‌صورت سرور‌محور با صفحهٔ resume.
- مصرف idempotent (تکرار callback، خرید را دو بار مصرف نمی‌کند).
- جدول و مدل `bazaar_iap_transactions` برای ردیابی هر بازدید.
- رویدادهای `PurchaseConsumed` / `PurchaseFailed` برای اتصال به منطق دامنهٔ میزبان.
- فایل سمت مرورگر `window.BazaarIap` برای خواندن `accountId` و باز کردن دیپ‌لینک‌ها.

## نصب

بسته به‌صورت **path repository** در ریشهٔ پروژه ثبت شده است:

```json
"repositories": [
    { "type": "path", "url": "packages/caffenet/bazaar-iap", "options": { "symlink": true } }
],
"require": {
    "caffenet/bazaar-iap": "@dev"
}
```

سپس:

```bash
composer update caffenet/bazaar-iap
php artisan migrate
php artisan vendor:publish --tag=bazaar-iap-assets   # در صورت نیاز به فایل JS
```

سرویس‌پروایدر به‌صورت خودکار (package discovery) کشف می‌شود.

## پیکربندی

```bash
php artisan vendor:publish --tag=bazaar-iap-config
```

سپس در `.env`:

```dotenv
BAZAAR_IAP_ENABLED=true
BAZAAR_IAP_PACKAGE_NAME=ir.example.app
BAZAAR_IAP_API_SECRET=xxxxxxxxxxxxxxxx
BAZAAR_IAP_DEFAULT_SKU=sku_gold
BAZAAR_IAP_DEALER_PACKAGE_NAME=ir.example.app
```

برای میزبان‌هایی که اعتبارنامه را در دیتابیس نگه می‌دارند، کافی است یک پیاده‌سازی از
`Caffenet\BazaarIap\Contracts\BazaarIapSettings` بنویسید و در کانتینر bind کنید؛ bind شما
بر پیش‌فرض بسته اولویت دارد.

## استفاده

```php
use Caffenet\BazaarIap\Facades\BazaarIap;

// دیپ‌لینک پرداخت
$redirect = BazaarIap::redirectUrl(['visit' => $visitId, 'status' => 'payment', 'sku' => $sku]);
$deepLink = BazaarIap::paymentDeepLink($redirect, $sku);

// مصرف خرید (سمت سرور)
$result = BazaarIap::consume($purchaseToken);           // DTO: ConsumeResult
$result = BazaarIap::consumeOrFail($purchaseToken);     // خطا در صورت شکست

// اعتبارسنجی بدون مصرف
$purchase = BazaarIap::validate($sku, $purchaseToken);  // DTO: PurchaseResult
```

### مسیرهای HTTP

| متد | مسیر | توضیح |
| --- | --- | --- |
| `GET` | `/bazaar-iap/callback` | بازگشت از کافه‌بازار؛ مصرف سرور‌محور + رویداد |
| `GET` | `/bazaar-iap/status` | وضعیت تراکنش با `visit` |
| `GET` | `/bazaar-iap/login` | دریافت دیپ‌لینک ورود |
| `POST` | `/bazaar-iap/start` | ایجاد بازدید و دریافت دیپ‌لینک |

## رویدادها

```php
use Caffenet\BazaarIap\Events\PurchaseConsumed;
use Caffenet\BazaarIap\Events\PurchaseFailed;

Event::listen(PurchaseConsumed::class, function (PurchaseConsumed $e) {
    // $e->transaction->visit_id  ← شناسهٔ بازدید
    // $e->purchaseToken          ← توکن خرید
});
```

## SKU و قیمت‌گذاری متغیر

کافه‌بازار یک **فروشگاه محصول با قیمت ثابت** است و **درگاه مبلغ آزاد نیست**: هر SKU در پنل
توسعه‌دهندگان یک **قیمت ثابت** دارد و دیپ‌لینک `bazaar://in_app?…&sku=…` هیچ پارامتر مبلغی
نمی‌پذیرد.

- برای هر **محصول/قیمت ثابت** یک SKU بسازید.
- برای **قیمت‌های متغیر** (محاسبهٔ پویا per سفارش) نمی‌توان برای هر مبلغ SKU ساخت؛ از الگوی
  **کیف‌پول‌محور** (شارژ با بسته‌های ثابت + پرداخت سفارش از کیف پول) یا **تطبیق SKU↔مبلغ** (نگاشت
  SKU→قیمت و بررسی برابری با مبلغ سفارش) استفاده کنید.
- مقدار `resolveSku()` تنها یک پیش‌فرض است؛ برای چند قیمت، SKU متناظر را از پارامتر `sku` بدهید.

> هشدار: اگر برای همهٔ پرداخت‌ها از یک SKU ثابت استفاده کنید، مبلغی که کاربر می‌پردازد همان
> **قیمت SKU** است و نه لزوماً مبلغ محاسبه‌شدهٔ سفارش. جزئیات در
> [`docs/BAZAAR_IAP_LIBRARY.md`](../../docs/BAZAAR_IAP_LIBRARY.md:1) بخش ۱۴.

> **در این برنامه** الگوی «کیف‌پول‌محور» پیاده‌سازی شده است: پرداخت خدمات فقط از **کیف پول**
> انجام می‌شود و کافه‌بازار تنها برای **شارژ کیف پول** به کار می‌رود؛ داخل وبه‌ویو کافه‌بازار
> مبلغ شارژ باید یکی از بسته‌های پیشنهادی (هم‌تراز با SKUهای پنل) باشد.

## امنیت

- `API_SECRET` فقط روی سرور استفاده می‌شود و در هیچ پاسخ/فایل سمت مرورگر نیست.
- `redirectUrl` توسط Bazaar فراخوانی می‌شود و مصرف در همان مسیر سرور انجام می‌گیرد.
- پارامتر `return` در callback فقط به همان دامنه (یا مسیر نسبی) محدود می‌شود.

## تست

```bash
php artisan test --filter=BazaarIap
```
