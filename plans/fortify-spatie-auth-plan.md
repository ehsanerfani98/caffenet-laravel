# پلن مهاجرت احراز هویت و مجوزها به پکیج‌های رسمی لاراول

**فایل مرجع:** `plans/fortify-spatie-auth-plan.md`
**وضعیت:** ✅ اجرا شد — همه فازها سبز (E2E + RBAC + backfill validation)
**پیش‌نیاز:** بازنویسی کامل Laravel + jQuery (پلن `laravel-jquery-rewrite-plan.md`) — انجام شده
**شاخه:** `laravel-jquery`

---

## ۱. هدف

در نسخه فعلی، احراز هویت به‌صورت دستی با JWT (`firebase/php-jwt` + کوکی `cn_auth`) و مجوزها با سرویس داخلی + ستون `permissions_json` پیاده‌سازی شده است. بر اساس تصمیم پروژه، این دو بخش باید **فقط با پکیج‌های رسمی** انجام شود:

| حوزه | قبلاً | از این پس |
|---|---|---|
| احراز هویت | JWT دستی (firebase/php-jwt) در کوکی `cn_auth` | **Laravel Fortify** (نشست (session) بومی لاراول + پایپ‌لاین ورود) |
| نقش‌ها و مجوزها | ستون `role` + `permissions_json` + `PermissionService` داخلی | **spatie/laravel-permission** (جداول `roles`/`permissions`/پivot) |
| ورود کاربر | OTP دو مرحله‌ای (سفارشی) | همان OTP دو مرحله‌ای — اما از طریق پایپ‌لاین ورود Fortify |

**اصل حاکم:** قرارداد API (§13 مستندات)، رفتار کسب‌وکار و تجربه کاربری (UX) فرانت‌اند بدون تغییر حفظ می‌شود. فقط زیرساخت احراز هویت و مجوزدهی جایگزین می‌شود.

---

## ۲. معماری جدید

```
مرورگر (jQuery SPA)
  │  کوکی نشست بومی لاراول (caffenet_lite_session) + هدر X-CSRF-TOKEN
  ▼
routes/api.php  ←  گروه middleware «web» (نشست + CSRF + کد 300/min) + OriginGuard
  ▼
AuthController
  ├── POST /api/auth/send-otp    → تولید و ارسال OTP (سرویس داخلی + محدودیت نرخ یا Rate limit)
  ├── POST /api/auth/verify-otp  → پایپ‌لاین ورود Fortify:
  │       EnsureLoginIsNotThrottled → AttemptToAuthenticate → PrepareAuthenticatedSession
  │       (در AttemptToAuthenticate، با Fortify::authenticateUsing، کد OTP تایید و کاربر بازیابی می‌شود)
  ├── POST /api/auth/logout      → خروج به روش Fortify (پاک‌سازی نشست + بازتولید توکن CSRF)
  └── GET  /api/auth/me          → کاربر نشست + مجوزهای مؤثر

EnsureAuth / OptionalAuth  →  کاربر نگهبان پیش‌فرض (session guard)
permission:  →  Spatie\Permission\Middleware\PermissionMiddleware (hasAnyPermission)
```

### ۲.۱ نقش‌ها و مجوزها با Spatie

* **کاتالوگ مجوزها** (۳۰ مجوز با عنوان/توضیح فارسی، گروه‌بندی) بدون تغییر در `PermissionService` باقی می‌ماند — منبع نمایش ماتریس مجوزها در فرانت و ترجمه (translation) سرور است.
* **منطق «چترِ مدیریت» (manage-umbrella) و ارث‌بری ضمنیِ مشاهده (implicit-view inheritance)** در زمان **اختصاص** اعمال می‌شود (`PermissionService::expand()`): هر نقش/کاربر مجموعه **موّرجع (concrete)** مجوزها را دریافت می‌کند. بنابراین چک سرور تبدیل به `hasAnyPermission` ساده روی نام‌های دقیق می‌شود — همان معناشناسی که Spatie ارائه می‌دهد.
* **نقش‌ها:** `admin` (مدیر سیستم)، `operator` (اپراتور)، `customer` (مشتری) — با Seed اولیه:
  * `admin` → تمام ۳۰ مجوز + دورزدن (bypass) در `Gate::before` (مدیر هرگز قفل نمی‌شود)
  * `operator` → گسترش‌یافته از `dashboard.view, orders.manage, sms.view` → یعنی `dashboard.view`, `orders.view|create|edit|delete|manage`, `sms.view`
  * `customer` → بدون مجوز مدیریتی
* **Override در سطح کاربر:** قبلاً JSON؛ اکنون `model_has_permissions` (متدهای `givePermissionTo`/`syncPermissions`). ستون `permissions_json` پس از Backfill حذف می‌شود.
* **ستون `role`:** به‌عنوان فیلد نمایشی و سازگاری API حفظ می‌شود اما همیشه با `syncRoles` همگام می‌شود؛ منبع حقیقت (source of truth) نقش‌ها = Spatie.

---

## ۳. فازها

### فاز ۱ — نصب و تنظیم Fortify (احراز هویت)

1. `composer require laravel/fortify` و حذف `firebase/php-jwt`
2. ساخت `config/fortify.php` + `FortifyServiceProvider`؛ ثبت `Fortify::ignoreRoutes()` (نقاط پایانی یا endpointهای خودمان را نگه می‌داریم تا قرارداد API عوض نشود)
3. `Fortify::authenticateUsing()`: دریافت `phone` + `code`، نرمال‌سازی، تایید کد OTP و بازگرداندن کاربر (منطق OTP فعلی حفظ می‌شود؛ فقط جای آن در پایپ‌لاین ورود Fortify است)
4. بازنویسی `AuthController`:
   * `verifyOtp`: اجرای برنامه‌نویسی‌شده (برنامه‌نویسانه) پایپ‌لاین ورود Fortify با `LoginRequest` (الگوی رسمی سفارشی‌سازی کنترلر ورود)
   * `logout`: `Auth::guard('web')->logout()` + از بین بردن (invalidate) نشست + بازتولید توکن CSRF
5. بازنویسی `EnsureAuth`/`OptionalAuth` روی نگهبان نشست؛ افزودن چک `is_blocked`
6. انتقال مسیرهای `/api/*` به گروه middleware «web» (نشست + CSRF) با `throttle:300,1` و `OriginGuard` — SPA هم‌مبدأ است؛ بدون تغییر مسیر
7. هش OTP دیگر با راز JWT نیست — با `APP_KEY` انجام می‌شود (`hash_hmac('sha256', phone:code, app key)`)؛ چرخش `APP_KEY` کدها و نشست‌ها را باطل می‌کند (همان تضمین قبلی)
8. حذف تنظیمات `jwtSecret`/`jwtTtl` از `SettingsService`، `SettingsSeeder` و `.env.example`؛ حذف کوکی `cn_auth`

### فاز ۲ — نصب Spatie Permission (نقش‌ها و مجوزها)

1. `composer require spatie/laravel-permission` + انتشار migration و config + ثبت ServiceProvider
2. افزودن trait `HasRoles` به مدل `User`
3. بازنویسی `EnsurePermission` → جایگزینی با میان‌افزار (middleware) Spatie (alias `permission` به `Spatie\Permission\Middleware\PermissionMiddleware`)
4. `AuthServiceProvider`: `Gate::before` برای دورزدن (bypass) مدیر؛ `PermissionRegistrar` cache refresh در Seed اولیه (seeding)
5. مایگریشن (Migration) مهاجرت داده:
   * ساخت جداول Spatie (انتشاریافته)
   * Backfill: برای هر کاربر، `role` → `syncRoles([role])`؛ `permissions_json` → `expand()` → `syncPermissions()`
   * حذف ستون `permissions_json`
6. Seed اولیه نقش‌ها: `RoleSeeder` (admin/operator/customer با مجوزهای گسترش‌یافته)
7. به‌روزرسانی سرویس‌ها/کنترلرها:
   * `AuthService::findOrCreateUser` → `assignRole` + همگام‌سازی ستون `role`
   * `UserController` (ذخیره/ویرایش مجوز کاربر) → `syncPermissions(expand(...))` + `syncRoles`
   * `User::effectivePermissions()` → `$user->getAllPermissions()->pluck('name')`
   * `Formatter::user` → قرارداد همان (آرایه رشته‌ای مجوزهای موّرجع + فیلد role)

### فاز ۳ — فرانت‌اند (jQuery) و پوسته Blade

1. افزودن `<meta name="csrf-token">` به `app.blade.php`
2. `public/js/api.js`: ارسال `X-CSRF-TOKEN` در همه درخواست‌های غیر GET؛ 401 → صفحه ورود (بدون تغییر)؛ حذف هر اشاره‌گر به کوکی `cn_auth`
3. جریان ورود (send-otp → verify-otp) و صفحات دیگر بدون تغییر UX

### فاز ۴ — تست، مستندسازی و انتشار

1. `migrate:fresh --seed` از صفر + `admin:set` + `caffenet:cleanup`
2. دود آزمایشی E2E (Smoke test): ورود OTP آزمایشی → `/auth/me` با نشست → endpointهای دارای `permission:` (اپراتور/مدیر) → ویرایش مجوز کاربر از پنل → خروج
3. رفع عیوب + به‌روزرسانی `README.md` (بخش احراز هویت/مجوزها) و `.env.example`
4. Commit و push به شاخه `laravel-jquery`

---

## ۴. قرارداد API (بدون تغییر)

همه نقاط پایانی یا endpointها با همان مسیر، متد و شکل پاسخ قبلی حفظ می‌شوند؛ تنها تفاوت‌های داخلی:

* مکانیزم نشست: کوکی نشست لاراول به‌جای `cn_auth` (پاسخ `verify-otp` دیگر توکن/کوکی JWT نمی‌سازد)
* خطای 401 برای مهمان‌ها همان پیام قبلی؛ خطای 419 (CSRF) از این پس ممکن است — `api.js` با هدر CSRF از آن جلوگیری می‌کند
* `GET /api/permissions` از Spatie می‌خواند (کاتالوگ یکسان)

## ۵. معیار پذیرش

1. هیچ ارجاعی به JWT/cn_auth/firebase-php-jwt در کد نماند
2. ورود/خروج، احراز هویت و محدودیت نرخ (rate limit) فقط از طریق Fortify انجام شود
3. چک مجوز فقط با Spatie (`HasRoles`, `hasAnyPermission`, middleware رسمی) انجام شود؛ `PermissionService` فقط کاتالوگ/برچسب‌ها (labels)/گسترش را نگه دارد
4. Backfill کاربران موجود بدون از دست رفتن مجوز
5. E2E سبز: ورود OTP → سفارش → پنل اپراتور/مدیر → خروج
