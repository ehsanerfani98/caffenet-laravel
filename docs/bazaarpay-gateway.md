# درگاه پرداخت بازارپی (BazaarPay)

پیاده‌سازی سمت سرور درگاه کافه‌بازار برای نسخه وب‌ویو برنامه — مطابق مستندات رسمی
[BazaarPay/Docs](https://github.com/BazaarPay/Docs/tree/main/fa).

دو جریان پرداخت پیاده شده است:

| جریان | مستند | کاربرد | وضعیت کاربر |
|---|---|---|---|
| **شارژ کیف پول** (Checkout) | [payment.md](https://github.com/BazaarPay/Docs/blob/main/fa/payment.md) | شارژ کیف پول با هر مبلغی | انتقال به درگاه بازارپی و پرداخت با کیف پول/کارت بانکی |
| **پرداخت مستقیم** (Direct Pay / دایرکت‌پی) | [direct-pay.md](https://github.com/BazaarPay/Docs/blob/main/fa/direct-pay.md) | پرداخت خودکار سفارش‌ها بدون شارژ کیف پول | فقط بار اول: امضای قرارداد؛ دفعات بعد کاملاً خودکار |

## ۱. شارژ کیف پول (Checkout)

```
کلاینت                    سرور (Laravel)                    بازارپی
  │  POST /api/payments/request   │                          │
  │  {purpose:'wallet',           │── POST /checkout/init/ ─▶│
  │   gateway:'bazaarpay',        │◀─ checkout_token +       │
  │   amount}                     │   payment_url            │
  │◀─ redirectUrl ────────────────│                          │
  │  ریدایرکت به payment_url                                  │
  │  (+ redirect_url و phone)     │                          │
  │        کاربر پرداخت می‌کند ──────────────────────────────▶│
  │◀─── بازگشت به {appUrl}/api/payments/verify?authority=… ───│
  │                               │── POST /trace/ ────────▶│
  │                               │◀─ paid_not_committed ────│
  │                               │── POST /commit/ ───────▶│ (204)
  │                               │  کیف پول شارژ می‌شود      │
```

نکات کلیدی:

- توکن چک‌اوت در `payments.authority` ذخیره می‌شود و **داخل `redirect_url`** هم
  قرار می‌گیرد؛ بنابراین تشخیص پرداختِ بازگشتی به نام پارامترهای بازارپی وابسته نیست.
- تصمیم موفق/ناموفق **همیشه** از اندپوینت `trace` گرفته می‌شود (پارامترهای
  بازگشتی درگاه هرگز معیار نیستند).
- اگر callback از دست برود، endpoint وضعیت (`GET /api/payments/status`) در هر
  poll دوباره `trace` می‌زند و در صورت `paid_not_committed` خودش `commit` می‌کند —
  سیستمی خودترمیم.
- مبالغ سامانه تومان است و بازارپی ریال؛ تبدیل با `bazaarPayRialRate` (پیش‌فرض ۱۰).

## ۲. پرداخت مستقیم سفارش (Direct Pay)

- کاربر در فرم سفارش یا رهگیر سفارش، «پرداخت مستقیم با بازارپی» را انتخاب می‌کند.
- سرور قرارداد فعال کاربر را چک می‌کند:
  - **قرارداد فعال موجود:** `checkout/init` + `POST /direct-pay` + `commit` در یک
    درخواست اجرا و سفارش ثبت می‌شود — بدون هیچ ریدایرکتی.
  - **قرارداد ندارد:** قرارداد با `direct-pay/contract/init` ساخته می‌شود و کاربر
    به صفحه امضای بازارپی می‌رود؛ پس از امضا، callback
    (`GET /api/payments/bazaarpay/contract?contract_token=…`) وضعیت را با
    `contract/trace` تازه‌سازی می‌کند و در صورت `active` پرداخت را به‌صورت خودکار
    ادامه می‌دهد.
- مشخصات قرارداد (نوع، دوره، سقف) از تنظیمات سیستمی خوانده می‌شود:
  - نوع: `wallet` (کیف پول کافه‌بازار) یا `direct_debit` (دایرکت دبیت بانکی)
  - دوره: daily / weekly / monthly / quarterly / semiannual / yearly
  - سقف هر دوره به تومان (روی سیم به ریال تبدیل می‌شود)
- ردپای قراردادها در جدول `bazaarpay_contracts` نگهداری می‌شود و وضعیت آن با هر
  trace به‌روزرسانی می‌گردد.

## ۳. تنظیمات (پنل مدیریت ← تنظیمات سیستمی ← پرداخت)

| کلید | توضیح |
|---|---|
| `bazaarPayEnabled` | فعال بودن درگاه بازارپی |
| `bazaarPayToken` | توکن مرچنت (تستی/اصلی) — secret ماسک‌شده |
| `bazaarPayDestination` | نام مقصد (برای توکن تستی: `developers`) |
| `bazaarPayDirectEnabled` | فعال بودن پرداخت مستقیم سفارش |
| `bazaarPayDirectType` | نوع قرارداد: wallet / direct_debit |
| `bazaarPayDirectPeriod` | دوره ریست سقف قرارداد |
| `bazaarPayDirectAmountLimit` | سقف پرداخت هر دوره (تومان) |
| `bazaarPayBaseUrl` | پیش‌فرض `https://api.bazaar-pay.ir` |
| `bazaarPayCallbackUrl` | خالی = ساخت خودکار از «نشانی پایه سایت» |
| `bazaarPayRialRate` | ضریب تومان→ریال (پیش‌فرض ۱۰) |

**حالت «تست آفلاین»** (`paymentTestMode`): همه اندپوینت‌های بازارپی شبیه‌سازی
می‌شوند؛ شارژ کیف پول از صفحه `/test-gateway` داخلی می‌گذرد و پرداخت مستقیم بلافاصله
موفق می‌شود — بدون نیاز به توکن.

### راه‌اندازی با توکن تستی (طبق راهنمای پشتیبانی بازارپی)

1. درگاه «بازارپی» را فعال و «گزینه درگاه» را روی «واقعی» بگذارید.
2. توکن تستی و Destination=`developers` را وارد کنید.
3. «نشانی پایه سایت» را در تنظیمات عمومی وارد کنید (برای ساخت redirect_url).
4. جریان شارژ کیف پول و پرداخت مستقیم را تست کنید.
5. پس از تست موفق به پشتیبانی کافه‌بازار اطلاع دهید تا توکن و Destination اصلی
   (مختص PackageName برنامه) را صادر کنند؛ سپس مقادیر اصلی را جایگزین کنید.

## ۴. نقشه فایل‌ها

| فایل | نقش |
|---|---|
| `app/Services/BazaarPayService.php` | کلاینت API بازارپی (checkout/trace/commit/refund + contract/trace/pay/payable) |
| `app/Http/Controllers/Api/PaymentController.php` | `request` (شارژ)، `verify`، `status`، `requestDirectOrder`، `contractCallback` |
| `app/Models/BazaarPayContract.php` | مدل قرارداد دایرکت‌پی |
| `database/migrations/2026_10_16_000001_create_bazaarpay_contracts_table.php` | جدول قراردادها |
| `database/migrations/2026_10_16_000002_add_contract_token_to_payments_table.php` | ستون `payments.contract_token` |
| `public/js/screens/wallet.js` | UI شارژ کیف پول (بازارپی = مبلغ دلخواه مجاز) |
| `public/js/screens/order-form.js` | انتخاب روش پرداخت سفارش |
| `public/js/screens/order-tracker.js` | پرداخت مجدد (کیف پول / مستقیم) |
| `public/js/screens/admin-manage.js` | تب «بازارپی» در تنظیمات سیستمی |

## ۵. API های جدید

| متد | مسیر | توضیح |
|---|---|---|
| POST | `/api/orders/{id}/pay-bazaarpay` | پرداخت مستقیم سفارش (اتنتیک‌شده) |
| GET | `/api/payments/bazaarpay/contract` | بازگشت از صفحه امضای قرارداد (عمومی، توکن در URL) |
| GET | `/api/settings` | شامل `bazaarPay` (بدون توکن مرچنت) |

## ۶. نکات امنیتی

- توکن مرچنت هرگز به کلاینت فرستاده نمی‌شود (در snapshot عمومی حذف شده است).
- مبالغ فقط سمت سرور تعیین می‌شوند؛ ورودی کلاینت اعتبارسنجی می‌شود.
- تمام گذارهای وضعیت پرداخت idempotent هستند (کال‌بک تکراری اثر مضاعف ندارد).
- تصمیم موفقیت فقط از `trace` سرور بازارپی گرفته می‌شود، نه از پارامترهای ریدایرکت.
