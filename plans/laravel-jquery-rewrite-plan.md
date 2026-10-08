# پلن بازنویسی کافی‌نت احسان — بک‌اند Laravel + فرانت‌اند jQuery

**وضعیت:** در حال اجرا
**هدف:** بازنویسی کامل سامانه «کافی‌نت احسان» با حفظ **۱۰۰٪ رفتار کسب‌وکار**، ولی با پشته فناوری جدید:
- **بک‌اند:** Laravel (PHP 8.4) + SQLite (فایل‌محور، بدون سرور دیتابیس)
- **فرانت‌اند:** jQuery + Blade Shell + CSS خالص (بدون React/Vite/Tailwind build)

> پلن‌های قبلی: `payment-failure-order-status-plan.md` و `production-readiness-plan.md`
> مستندات مرجع: `docs/PROJECT_DOCUMENTATION.md` — این پلن موئلفه‌به‌موئلفه همان رفتار را در معماری جدید نگاشت می‌کند.

---

## ۱. انگیزه و دامنه

| بند | توضیح |
| --- | --- |
| چه چیز تغییر می‌کند | پشته فناوری: React 19 + Express + node:sqlite → Laravel + Blade + jQuery |
| چه چیز تغییر نمی‌کند | رفتار کسب‌وکار: چرخه سفارش پرداخت‌محور، ماشین‌حساب قیمت، RBAC، کیف پول، پیامک، درگاه، تنظیمات دیتابیس‌محور |
| خروجی نهایی | یک پروژه Laravel قابل استقرار با همان API (قرارداد JSON camelCase) و همان تجربه کاربری (PWA راست‌چین فارسی) |

## ۲. پشته فناوری جدید

| لایه | قبلی | جدید |
| --- | --- | --- |
| رابط کاربری | React 19 + TS + Vite + Tailwind 4 | **jQuery 3.7 + قالب‌های JS سمت کلاینت + CSS خالص RTL** |
| پوسته HTML | index.html + React root | **Blade view** (`resources/views/app.blade.php`) |
| سرور | Express + tsx | **Laravel 12 (PHP ≥ 8.2)** سرو شده با `php artisan serve` / Nginx+php-fpm |
| دیتابیس | node:sqlite (DatabaseSync، WAL) | **SQLite با PDO + WAL** (`PRAGMA journal_mode=WAL`) — همان فایل‌محور بودن |
| احراز هویت | jsonwebtoken + کوکی `cn_auth` | **firebase/php-jwt + همان کوکی** (`cn_auth`, httpOnly, SameSite=Lax) |
| امنیت | helmet + express-rate-limit | **هدرهای امنیتی سفارشی + RateLimiter لاراول** |
| آپلود | multer | **Storage لاراول** (`Storage::disk`) با همان محدودیت‌ها |
| زمان‌بندی | setInterval هر ۶ ساعت | **Scheduler لاراول** (`$schedule->command('caffenet:cleanup')->everySixHours()`) |
| PWA | vite-plugin-pwa (Workbox) | **manifest.webmanifest دستی + Service Worker سبک دستی** |
| فونت | Peyda (self-hosted) | **همان فونت‌ها عیناً کپی می‌شوند** (`public/fonts`) |
| آیکون | lucide-react | **SVG inline (کپی pathهای lucide)** با هِلپر PHP/jQuery |

## ۳. نگاشت معماری (قدیم → جدید)

```
server.ts                     → bootstrap/app.php + routes/* (بوت، مایگریشن خودکار، سیـدر)
src/server/app.ts             → routes/api.php + routes/web.php (استاتیک، test-gateway، SPA)
src/server/config.ts          → app/Services/SettingsService.php (پیش‌فرض‌های کامپایل‌شده + boot-only از .env)
src/server/routes.ts          → app/Http/Controllers/Api/* (یک کنترلر به‌ازای هر دامنه)
src/server/auth.ts            → app/Services/AuthService.php + app/Http/Middleware/EnsureAuth.php
src/server/middleware.ts      → app/Http/Middleware/{SecurityHeaders,OriginGuard,ThrottleRequests}+bootstrap/app.php
src/server/settingsService.ts → app/Services/SettingsService.php (بدون کش، ماسک رازها)
src/server/sms.ts             → app/Services/SmsService.php (ippanel REST، هدر apikey، pattern)
src/server/payment.ts         → app/Services/PaymentService.php (زرین‌پال REST v4 + sandbox + test)
src/server/cleanup.ts         → app/Console/Commands/CaffenetCleanup.php + Schedule
src/db/migrations/*           → database/migrations/* (همان اسکیمای ۰۰۱ تا ۰۰۵، یک‌جا در یک مایگریشن پایه)
src/db/seeders/*              → database/seeders/* (SettingsSeeder تکرارشونده + AdminUsersSeeder)
src/utils/permissions.ts      → app/Services/PermissionService.php + public/js/permissions.js (دو سمت، همان کاتالوگ)
src/utils/pricing.ts          → app/Services/PricingService.php + public/js/pricing.js (فرمول واحد مشترک)
src/utils/persianUtils.ts     → public/js/persian-utils.js (شمسی، ارقام فارسی، نرمال‌سازی موبایل)
src/services/storageService.ts→ public/js/api.js (لایه داده کلاینت، همگام‌سازی ۱۵ثانیه‌ای، ApiError)
src/App.tsx                   → public/js/app.js (روتر hash-based + مدیریت state سراسری)
src/components/common/*       → public/js/ui.js + public/js/screens/*.js (Sheet, Toast, AppBar, BottomNav…)
src/components/customer/*     → public/js/screens/customer-*.js
src/components/operator/*     → public/js/screens/operator.js
src/components/admin/*        → public/js/screens/admin-*.js
src/components/auth/*         → public/js/screens/auth.js (AuthModal)
src/components/payment/*      → public/js/screens/payment.js (PaymentModal)
src/components/pwa/*          → public/js/pwa.js (نصب، آفلاین)
```

## ۴. دیتابیس و مدل‌ها

### ۴.۱ اسکیما — عیناً همان جداول (سازگاری داده)

جداول: `users`, `categories`, `services`, `orders`, `order_logs`, `ticket_messages`, `coupons`, `sms_logs`, `otp_codes`, `payments`, `settings`, `slider_images` (+ `migrations` خود لاراول).

- کلیدها همان الگوی `usr-…/ord-…/log-…/pay-…` تولید می‌شوند (UUID با پیشوند) تا شماره‌سفارش‌ها و ارجاع‌ها یکسان بمانند.
- ستون‌های JSON (`permissions_json`, `fields_json`, `form_data_json`, `customer_files_json`, `result_files_json`) حفظ می‌شوند؛ در مدل‌ها با `casts` به آرایه تبدیل می‌شوند.
- همان ایندکس‌های ۰۰۱ و `orders(status)` از ۰۰۵.

### ۴.۲ مدل‌های Eloquent

`User, Category, Service, Order, OrderLog, TicketMessage, Coupon, SmsLog, OtpCode, Payment, Setting, SliderImage`

- نگاشت snake_case → camelCase خروجی با **Formatters** (`app/Http/Support/`) انجام می‌شود تا پاسخ API دقیقاً مثل قبل باشد (`walletBalance`, `formData`, `ticketMessages`, …).
- `Setting` بدون کش خوانده می‌شود (هر بار `SELECT`) — قاعده «بدون fallback محیطی» حفظ می‌شود.

### ۴.۳ Seederها

| Seeder | رفتار |
| --- | --- |
| `SettingsSeeder` (repeatable/idempotent) | برای هر کلید `SYSTEM_SETTING_DEFS` با `INSERT OR IGNORE` معادل `firstOrCreate` ردیف می‌سازد |
| `AdminUsersSeeder` | تبدیل `adminPhones` به کاربران نقش admin |
| `CatalogSeeder` (جدید، اختیاری) | همان دسته‌بندی‌ها/خدمات نمونه (پرینت، ثبت‌نام، اداری، مالیاتی) تا تجربه اولیه خالی نباشد |

## ۵. احراز هویت و امنیت — قواعد حفظ‌شده

1. **OTP**: ۵ رقم (`random_int(10000,99999)`)، انقضای ۲ دقیقه، حداکثر ۵ تلاش، هش **HMAC-SHA256** روی `phone:code` با کلید jwtSecret، مصرف یک‌بار، سقف ۳ کد/شماره/۱۰دقیقه.
2. **JWT**: امضا با `firebase/php-jwt`، payload شامل `phone`، `role`، `sub=user.id`، `exp` از کلید `jwtExpiresIn`؛ کوکی `cn_auth` (httpOnly, SameSite=Lax, secure در production، path=/). پذیرش هدر `Authorization: Bearer` هم مثل قبل.
3. **میان‌افزار**:
   - `EnsureAuth` (معادل requireAuth): تایید توکن → بارگذاری کاربر → بررسی `is_blocked` → resolve مجوزها → `request->attributes`.
   - `EnsurePermission`: «حداقل یکی از مجوزها»، توارث `<resource>.manage` و view ضمنی — همان قواعد `permissionSatisfies`.
   - `OriginGuard`: برای متدهای تغییردهنده، تطابق Origin/Referer با `appUrl` یا هاست جاری، وگرنه ۴۰۳.
   - Rate limit: API عمومی 300/60s، send-otp 6/60s، verify-otp 15/60s.
4. **هدرهای امنیتی**: معادل Helmet (X-Content-Type-Options، X-Frame-Options DENY، Referrer-Policy، CSP سخت‌گیرانه با مجوز self + data + همان فونت‌ها).
5. **فایل‌ها**: فهرست سفید MIME/پسوند، سقف ۳۰MB سفارش و ۵MB اسلایدر؛ دانلود `/uploads/:file` فقط با ورود + بررسی مالکیت (مالک سفارش یا staff).
6. **خطاها**: هرگز جزئیات داخلی به کلاینت نمی‌رود؛ ۵۰۰ با پیام فارسی ثابت. استثناها در `report()` لاگ می‌شوند.

## ۶. نقشه API — قرارداد ثابت می‌ماند

همه مسیرهای `docs/PROJECT_DOCUMENTATION.md §13` عیناً بازسازی می‌شوند (متد، مسیر، شرط دسترسی، ساختار پاسخ camelCase، کدهای خطای فارسی):

- Auth: `send-otp`, `verify-otp`, `logout`, `me`
- کاتالوگ: `GET/POST/DELETE categories`, `GET/POST/DELETE services`
- آپلود: `POST/DELETE /api/upload`
- سفارش: `GET/POST /api/orders`, `GET/PATCH/DELETE /api/orders/:id`, `/:id/pay-wallet`, `/:id/status`, `/:id/result`, `/:id/ticket`
- پرداخت: `POST /api/payments/request`, `GET /api/payments/verify` (callback زرین‌پال), `GET /api/payments/status`
- کاربران/مجوزها: `GET/POST/PATCH/DELETE /api/users`, `GET /api/permissions`
- داشبورد: `GET /api/dashboard/stats` (مشتری/کارکنان)
- کد تخفیف: CRUD + `POST /api/coupons/validate`
- تنظیمات: `GET/PUT /api/settings` (کسب‌وکار)، `GET/PUT /api/settings/system` (ماسک‌شده، boot-only فقط‌خواندنی)
- پیامک: `GET /api/sms`
- اسلایدر: `GET /api/slider`, `POST /api/slider/upload`, `POST /api/slider`, `PUT /api/slider/reorder`, `DELETE /api/slider/:id`
- ایستا: `/uploads/:file`، `/media/*`، `/test-gateway` (درگاه آزمایشی داخلی با دکمه موفق/انصراف)

### قواعد حیاتی حفظ‌شده در پیاده‌سازی

1. **مبلغ همیشه سمت سرور**: `payable = total_price − discount_amount` از رکورد سفارش؛ ورودی مبلغ کلاینت هرگز پذیرفته نمی‌شود.
2. **چرخه پرداخت‌محور**: ایجاد سفارش = `pending_payment`؛ ورود به صف فقط با تایید پرداخت (آنلاین/کیف پول)؛ `pending_payment/failed` هرگز در صف اپراتور.
3. **تایید idempotent**: کدهای `100/101` زرین‌پال، قفل `paid_amount = 0` در UPDATE، مصرف یک‌بار کد تخفیف.
4. **کیف پول تراکنشی**: `DB::transaction` + گارد اتمیک ضد double-debit؛ محدوده شارژ ۱۰هزار تا ۵۰میلیون.
5. **رزرو کد تخفیف**: `coupon_id` هنگام ثبت سفارش؛ `usage_count` فقط در تایید پرداخت +۱.
6. **پاکسازی**: دستور `caffenet:cleanup` هر ۶ ساعت — حذف فایل‌های کهنه (`fileRetentionDays`) و انقضای پرداخت‌های رهاشده (۳۰ دقیقه → `failed` + بستن تراکنش‌های `pending`).

## ۷. فرانت‌اند jQuery — معماری

### ۷.۱ اصول

- **SPA با hash routing** (`#/home`, `#/catalog`, `#/orders`, `#/wallet`, `#/profile`, `#/operator`, `#/admin/…`) — بدون ری‌لود، همان قاب موبایل (max-w-480).
- **State سراسری** در `App.state` + رویداد `app:sync` (معادل observer پترن storageService)؛ داده فقط در حافظه صفحه؛ هیچ داده کاربری در localStorage.
- **همگام‌سازی**: هر ۱۵ ثانیه + `visibilitychange` + بعد از هر تغییر (مثل قبل).
- **رندر**: توابع قالب ES template literals + `$.fn.html` — هر صفحه یک ماژول با `render(container, ctx)` و bind رویدادها.

### ۷.۲ ساختار فایل‌های JS

```
public/js/
  api.js            لایه API + ApiError + همگام‌سازی (معادل storageService)
  permissions.js    کاتالوگ مجوزها + قواعد توارث (سمت کلاینت)
  pricing.js        ماشین‌حساب قیمت واحد (پیش‌نمایش زنده)
  persian-utils.js  شمسی/ارقام/نرمال‌سازی/فرمت تومان
  ui.js             Sheet, Toast, AppBar, BottomNav, StatusBadge, ImageSlider, helpers
  icons.js          آیکون‌های SVG (lucide paths)
  pwa.js            نصب + وضعیت آنلاین
  app.js            روتر، state، ناوبری نقش‌محور، بازگشت از درگاه
  screens/
    landing.js  home.js  catalog.js  profile.js  auth.js
    order-form.js  order-tracker.js  wallet.js  payment.js
    operator.js
    admin-dashboard.js  admin-services.js  admin-users.js  admin-more.js
    admin-coupons.js  admin-settings.js  admin-slider.js  admin-sms.js
public/css/app.css  ← همان ظاهر: قاب موبایل تیره، RTL، Peyda، Safe-Area، انیمیشن‌ها، ارقام فارسی
```

### ۷.۳ ناوبری پایین نقش‌محور (مثل قبل)

- مشتری: خانه/دسته‌بندی/سفارش‌ها(badge)/کیف پول/پروفایل
- اپراتور: صف + داشبورد/کاربران/بیشتر بر اساس مجوز
- مدیر: داشبورد/خدمات/کاربران/بیشتر

### ۷.۴ Sheetها و جریان‌ها

- `Sheet` پایه (bottom-sheet با handle، header، بدنه اسکرول، footer) → همه مودال‌ها از آن مشتق می‌شوند.
- جریان درگاه: `POST /api/payments/request` → PaymentModal (انتقال خودکار پس از ۱.۲ ثانیه) → بازگشت به `?payment=…&authority=…` → `GET /api/payments/status` → Toast + sync.
- OTP: ورودی شماره → کد ۵ رقمی با تایمر ۱۲۰ثانیه + ارسال مجدد؛ حالت آزمایشی کد را نشان می‌دهد.

## ۸. فازبندی اجرا

| فاز | خروجی | وضعیت |
| --- | --- | --- |
| P0 | پلن + اسکلت Laravel + نصب پکیج‌ها | ⏳ |
| P1 | مایگریشن‌ها + مدل‌ها + Seederها + تنظیم WAL | ☐ |
| P2 | سرویس‌های پایه: Settings، Permission، Pricing، Auth/OTP | ☐ |
| P3 | سرویس‌های خارجی: Sms (ippanel)، Payment (زرین‌پال+test gateway) | ☐ |
| P4 | Middleware + کنترلرهای API + routes (کل §۱۳ مستندات) | ☐ |
| P5 | پوسته Blade + CSS + لایه‌های JS پایه (api/ui/icons/persian/pricing/permissions) | ☐ |
| P6 | صفحه‌های مشتری (خانه/کاتالوگ/فرم سفارش/پیگیری/کیف پول/پروفایل/ورود) | ☐ |
| P7 | صف اپراتور + پنل مدیر (داشبورد/خدمات/کاربران/کد تخفیف/تنظیمات/اسلایدر/پیامک) | ☐ |
| P8 | PWA + فونت‌ها + دستورات artisan (`admin:set`, `caffenet:cleanup`) + Scheduler | ☐ |
| P9 | تست دودی کامل (همه مسیرهای API با curl) + رفع اشکال | ☐ |
| P10 | بروزرسانی README/مستندات + Push به گیت‌هاب (برنچ `laravel-jquery`) | ☐ |

## ۹. معیارهای پذیرش

1. `php artisan migrate --seed` از صفر، دیتابیس کامل با تنظیمات پیش‌فرض می‌سازد.
2. ورود OTP در حالت شبیه‌ساز پیامک (`smsTestMode`) بدون پیامک کار می‌کند؛ کد نمایش داده می‌شود.
3. ثبت سفارش → پرداخت کیف پول/درگاه آزمایشی → صف اپراتور → تغییر وضعیت → تحویل: کل چرخه بدون خطا.
4. ماتریس مجوزها: operator فقط `dashboard.view, orders.manage, sms.view`؛ توارث `manage` برقرار.
5. پنل تنظیمات: رازها ماسک‌شده، boot-only فقط‌خواندنی، ذخیره مقدار ماسک = بی‌تغییر.
6. فایل: آپلود/دانلود با کنترل مالکیت؛ اسلایدر فقط از `/media/`.
7. UI: RTL کامل، فونت Peyda، bottom-sheetها، ارقام فارسی، safe-area، حالت آفلاین.
8. هیچ راز و اعتبارنامه‌ای در JS سمت کلاینت نیست؛ همان سیاست «فقط بوت» برقرار است.

## ۱۰. ریسک‌ها و تصمیم‌های مرزی

| مورد | تصمیم |
| --- | --- |
| سازگاری دیتابیس قدیم | اسکیما و قالب شناسه‌ها یکسان نگه داشته می‌شود؛ مهاجرت داده = کپی فایل sqlite + اجرای migrate (بدون تغییر اسکیما) |
| پرداخت تستی روی استقرار دمو | `paymentTestMode` + `/test-gateway` عیناً بازسازی می‌شود |
| Tailwind → CSS خالص | کلاس‌های کاربردی کلیدی (`sheet`, `card`, `chip`, `btn`, `pill`…) در `app.css` بازنویسی می‌شود؛ ظاهر معادل حفظ می‌شود |
| PWA آفلاین | SW دستی: cache-first برای استاتیک، network-first برای API (فقط گرم‌کردن پوسته؛ صف آفلاین در دستور کار نیست و مثل قبل نیست) |
| WebSocket/Realtime | وجود نداشت؛ polling همان فواصل قبلی (۵ ثانیه پیگیری، ۱۵ ثانیه sync) |
