<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database-first settings service — a faithful port of settingsService.ts.
 *
 * Core rules preserved from the original architecture:
 *  - `.env` holds ONLY the five boot-only values (NODE_ENV/PORT/DATABASE_PATH/
 *    UPLOADS_DIR/MEDIA_DIR equivalents); everything else lives exclusively in
 *    the `settings` table.
 *  - NO environment fallback: a missing key resolves to its compiled default.
 *  - NO in-memory cache: every read is a fresh SELECT so panel changes apply
 *    immediately without restart.
 *  - Secrets are masked in API responses; writing the mask or an empty value
 *    keeps the stored value untouched.
 */
class SettingsService
{
    public const SECRET_MASK = '********';

    /**
     * Default copy of the «شرایط استفاده از خدمات و حریم خصوصی» (Terms of
     * Service & Privacy Policy) shipped with the app. It is a business setting
     * (`termsText`) so an admin can edit it from the business-settings panel;
     * this value merely seeds the default shown before any edit.
     */
    public const TERMS_DEFAULT_TEXT = <<<'TEXT'
شرایط استفاده از خدمات و حریم خصوصی

۱) پذیرش شرایط
با ثبت شماره همراه و ورود به سامانه «کافی‌نت احسان»، تأیید می‌کنید که این شرایط و سیاست حریم خصوصی را خوانده و پذیرفته‌اید. اگر با این شرایط موافق نیستید، لطفاً از سامانه استفاده نکنید.

۲) اطلاعاتی که از شما دریافت می‌کنیم
• شماره همراه: برای ثبت‌نام، ورود با کد یکبارمصرف و اطلاع‌رسانی وضعیت سفارش.
• نام و نام خانوادگی: برای ثبت و پیگیری سفارش.
• کد ملی و اطلاعات هویتی: تنها در خدماتی که به‌حکم قانون به آن نیاز است (مانند خدمات اداری، مالیاتی و دانشجویی).
• فایل‌ها و مدارک ارسالی شما: برای انجام سفارش درخواستی.
• اطلاعات سفارش و تراکنش‌ها: برای پردازش پرداخت، مدیریت کیف پول و پشتیبانی.
• نشانی و اطلاعات تماس: در صورت نیاز به تحویل یا هماهنگی.

۳) علت و هدف از دریافت اطلاعات
از اطلاعات شما فقط برای اهداف زیر استفاده می‌شود و به موارد دیگر تعمیم داده نمی‌شود: ایجاد و مدیریت حساب کاربری، انجام سفارش و ارائه خدمات، پردازش پرداخت و کیف پول، اطلاع‌رسانی وضعیت سفارش از طریق پیامک، پشتیبانی و پاسخگویی، و رعایت الزامات قانونی.

۴) نحوه حفاظت از اطلاعات
• انتقال اطلاعات روی بستر امن و با رمزنگاری مدرن (HTTPS) انجام می‌شود.
• دسترسی به اطلاعات تنها برای کاربران مجاز سامانه (مدیر و اپراتور) و بر اساس سطح دسترسی تعریف‌شده امکان‌پذیر است.
• اطلاعات شخصی و حساس شما فروخته نمی‌شود و در اختیار اشخاص غیرمجاز قرار نمی‌گیرد.

۵) اشتراک‌گذاری با اشخاص ثالث
تنها در حد لازم و برای ارائهٔ همان خدمت، بخشی از اطلاعات با ارائه‌دهندگان زیر به اشتراک گذاشته می‌شود: درگاه پرداخت (برای انجام تراکنش مالی) و سامانه پیامک (برای ارسال کد ورود و اطلاعیه‌ها). این اشخاص فقط مجاز به استفاده از اطلاعات در چارچوب همان خدمت هستند.

۶) داده‌های ذخیره‌شده روی دستگاه شما
برنامه تنها یک نشانهٔ فنی برای یادآوری پذیرش این شرایط را در حافظهٔ محلی مرورگر نگه می‌دارد. هیچ دادهٔ شخصی یا حساسی در حافظهٔ محلی دستگاه ذخیره نمی‌شود.

۷) دسترسی‌های موردنیاز
• پیامک: تنها برای ارسال کد ورود یکبارمصرف.
• دوربین/گالری: تنها زمانی که خودتان بخواهید فایلی برای سفارش بارگذاری کنید.
• اعلان: برای اطلاع از وضعیت سفارش.

۸) حقوق شما
• مشاهده و اصلاح اطلاعات حساب کاربری.
• درخواست حذف حساب و اطلاعات مرتبط.
• لغو رضایت از دریافت پیامک‌های اطلاع‌رسانی (به‌جز پیامک‌های ضروری مانند کد ورود).

۹) نگهداری و حذف اطلاعات
اطلاعات شما تا زمانی که حساب کاربری فعال است نگهداری می‌شود. فایل‌های بارگذاری‌شدهٔ سفارش نیز پس از مدت تعیین‌شده در سیاست نگهداری سامانه حذف می‌شوند.

۱۰) تغییرات
ممکن است این متن به‌روزرسانی شود. نسخهٔ معتبر همان متنی است که در همین برنامه نمایش داده می‌شود.

۱۱) تماس با ما
برای هر پرسش دربارهٔ حریم خصوصی می‌توانید از اطلاعات تماس نمایش‌داده‌شده در پایین همین صفحه استفاده کنید.
TEXT;

    /** Panel that owns a setting — a key must never live in both panels. */
    public const SCOPE_BUSINESS = 'business';

    public const SCOPE_SYSTEM = 'system';

    /**
     * The full definition list. Categories: general | auth | sms | payment | test | runtime.
     * `bootOnly` keys are read from env and surfaced read-only in the panel.
     *
     * Every definition also carries a `scope`:
     *  - `business`: owned by the business-settings panel and the public
     *    GET /api/settings + PUT /api/settings endpoints.
     *  - `system`  : owned by the system-settings panel (credentials, test
     *    modes, runtime). Business keys are excluded from it so the two panels
     *    never edit the same value.
     */
    public const DEFS = [
        // ---- boot-only (runtime) — system ----
        ['key' => 'nodeEnv', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true, 'scope' => self::SCOPE_SYSTEM,
            'label' => 'محیط اجرا', 'default' => 'development'],
        ['key' => 'port', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true, 'scope' => self::SCOPE_SYSTEM,
            'label' => 'پورت سرور', 'default' => '8000'],
        ['key' => 'databasePath', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true, 'scope' => self::SCOPE_SYSTEM,
            'label' => 'مسیر فایل دیتابیس', 'default' => 'database/caffenet.sqlite'],
        ['key' => 'uploadsDir', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true, 'scope' => self::SCOPE_SYSTEM,
            'label' => 'پوشه فایل‌های خصوصی', 'default' => 'storage/app/uploads'],
        ['key' => 'mediaDir', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true, 'scope' => self::SCOPE_SYSTEM,
            'label' => 'پوشه عمومی رسانه', 'default' => 'public/media'],

        // ---- general — system ----
        ['key' => 'appUrl', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نشانی پایه سایت', 'default' => ''],
        ['key' => 'adminPhones', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'شماره‌های مدیران (با کاما)', 'default' => ''],

        // ---- business ----
        ['key' => 'siteTitle', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'عنوان سایت', 'default' => 'کافی‌نت احسان'],
        ['key' => 'coffeenetAddress', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'آدرس دفتر', 'default' => ''],
        ['key' => 'coffeenetPhone', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'تلفن دفتر', 'default' => ''],
        ['key' => 'workingHours', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'ساعت کاری', 'default' => ''],
        ['key' => 'fileRetentionDays', 'category' => 'general', 'type' => 'number', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'روز نگهداری فایل', 'default' => '14'],
        ['key' => 'urgentPriceMultiplier', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'ضریب سفارش فوری', 'default' => '1.3'],
        ['key' => 'immediatePriceMultiplier', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'ضریب سفارش آنی', 'default' => '1.6'],
        ['key' => 'courierCost', 'category' => 'general', 'type' => 'number', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'هزینه پیک (تومان)', 'default' => '35000'],
        ['key' => 'termsText', 'category' => 'general', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'متن قوانین و حریم خصوصی', 'default' => self::TERMS_DEFAULT_TEXT],
        ['key' => 'enableWalletPayment', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'فعال بودن پرداخت کیف پول', 'default' => '1'],
        ['key' => 'enableCoupons', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'فعال بودن کدهای تخفیف', 'default' => '1'],
        ['key' => 'walletTopUpPresets', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_BUSINESS,
            'label' => 'مبالغ آماده شارژ کیف پول و SKU کافه‌بازار',
            'default' => '[{"amount":50000,"sku":""},{"amount":100000,"sku":""},{"amount":200000,"sku":""},{"amount":500000,"sku":""}]'],

        // ---- sms — system ----
        ['key' => 'smsEnabled', 'category' => 'sms', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن سامانه پیامک', 'default' => '0'],
        ['key' => 'smsApiKey', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'کلید دسترسی ippanel', 'default' => ''],
        ['key' => 'smsSender', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'شماره فرستنده', 'default' => ''],

        // ---- SMS patterns (ippanel Edge API) ----
        // ALL SMS messages are pattern-based. For each SMS type, the admin
        // enters: 1) the pattern code, 2) the IPPanel variable name for
        // each fixed field. The system knows what data each field needs.
        //
        // Settings keys follow the pattern:
        //   smsPattern{Type}               = pattern code
        //   smsPattern{Type}_{fieldId}     = IPPanel variable name for that field
        //
        // Example for "paid" (ثبت سفارش):
        //   smsPatternPaid              = "96ttx5xosutvyp7"
        //   smsPatternPaid_order_number = "factor"      (admin's IPPanel var name)
        //   smsPatternPaid_amount       = "amount"      (admin's IPPanel var name)

        // OTP
        ['key' => 'smsPatternOtp', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن OTP', 'default' => ''],
        ['key' => 'smsPatternOtp_code', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'OTP — نام متغیر کد تایید', 'default' => ''],

        // order_started
        ['key' => 'smsPatternStarted', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن شروع سفارش', 'default' => ''],
        ['key' => 'smsPatternStarted_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternStarted_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternStarted_service_title', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر عنوان خدمت', 'default' => ''],
        ['key' => 'smsPatternStarted_eta_minutes', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر زمان انتظار', 'default' => ''],
        ['key' => 'smsPatternStarted_start_time', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر زمان شروع', 'default' => ''],
        ['key' => 'smsPatternStarted_tracking_url', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'شروع — نام متغیر لینک پیگیری', 'default' => ''],

        // order_ready
        ['key' => 'smsPatternReady', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن آماده شدن', 'default' => ''],
        ['key' => 'smsPatternReady_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'آماده — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternReady_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'آماده — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternReady_tracking_url', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'آماده — نام متغیر لینک پیگیری', 'default' => ''],

        // requeued
        ['key' => 'smsPatternRequeued', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن انتقال صف', 'default' => ''],
        ['key' => 'smsPatternRequeued_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'انتقال — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternRequeued_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'انتقال — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternRequeued_queue_position', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'انتقال — نام متغیر موقعیت در صف', 'default' => ''],

        // cancelled
        ['key' => 'smsPatternCancelled', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن لغو', 'default' => ''],
        ['key' => 'smsPatternCancelled_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'لغو — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternCancelled_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'لغو — نام متغیر نام مشتری', 'default' => ''],

        // reminder
        ['key' => 'smsPatternReminder', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن یادآوری', 'default' => ''],
        ['key' => 'smsPatternReminder_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'یادآوری — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternReminder_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'یادآوری — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternReminder_minutes_before', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'یادآوری — نام متغیر دقیقه تا شروع', 'default' => ''],
        ['key' => 'smsPatternReminder_start_time', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'یادآوری — نام متغیر زمان شروع', 'default' => ''],

        // paid
        ['key' => 'smsPatternPaid', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن پرداخت', 'default' => ''],
        ['key' => 'smsPatternPaid_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'پرداخت — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternPaid_amount', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'پرداخت — نام متغیر قیمت', 'default' => ''],

        // status_change
        ['key' => 'smsPatternStatusChange', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن تغییر وضعیت', 'default' => ''],
        ['key' => 'smsPatternStatusChange_order_number', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'تغییر وضعیت — نام متغیر شماره سفارش', 'default' => ''],
        ['key' => 'smsPatternStatusChange_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'تغییر وضعیت — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternStatusChange_status_title', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'تغییر وضعیت — نام متغیر عنوان وضعیت', 'default' => ''],

        // refund
        ['key' => 'smsPatternRefund', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن عودت', 'default' => ''],
        ['key' => 'smsPatternRefund_customer_name', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'عودت — نام متغیر نام مشتری', 'default' => ''],
        ['key' => 'smsPatternRefund_amount', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'عودت — نام متغیر مبلغ', 'default' => ''],

        // broadcast
        ['key' => 'smsPatternBroadcast', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'کد پترن همگانی', 'default' => ''],
        ['key' => 'smsPatternBroadcast_title', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'همگانی — نام متغیر عنوان', 'default' => ''],
        ['key' => 'smsPatternBroadcast_body', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'همگانی — نام متغیر متن', 'default' => ''],

        ['key' => 'smsNotifyEnabled', 'category' => 'sms', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM, 'label' => 'ارسال پیامک اطلاع‌رسانی سفارش', 'default' => '0'],
        ['key' => 'smsBaseUrl', 'category' => 'sms', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM, 'label' => 'آدرس پایه ippanel Edge API', 'default' => 'https://edge.ippanel.com/v1'],

        // ---- payment — system ----
        ['key' => 'paymentEnabled', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن درگاه پرداخت', 'default' => '0'],
        ['key' => 'paymentMerchantId', 'category' => 'payment', 'type' => 'secret', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'کد پذیرنده زرین‌پال', 'default' => ''],
        ['key' => 'paymentSandbox', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'محیط sandbox زرین‌پال', 'default' => '0'],
        ['key' => 'paymentBaseUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'آدرس پایه زرین‌پال', 'default' => 'https://payment.zarinpal.com'],
        ['key' => 'paymentCallbackUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نشانی بازگشت درگاه', 'default' => ''],
        ['key' => 'paymentCurrency', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'واحد پولی زرین‌پال (IRT تومان / IRR ریال)', 'default' => 'IRT'],
        ['key' => 'paymentAutoVerify', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'اعتبارسنجی خودکار تراکنش (auto_verify)', 'default' => '1'],
        ['key' => 'paymentSandboxMerchantId', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'کد پذیرندهٔ موقت سندباکس (خودکار)', 'default' => ''],

        // ---- payment (Cafe Bazaar in-app gateway) — system ----
        ['key' => 'bazaarEnabled', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن درگاه درون‌برنامه‌ای کافه‌بازار', 'default' => '0'],
        ['key' => 'bazaarPackageName', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نام پکیج اپلیکیشن در کافه‌بازار', 'default' => ''],
        ['key' => 'bazaarApiSecret', 'category' => 'payment', 'type' => 'secret', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'کلید API کافه‌بازار (سرور)‌', 'default' => ''],
        ['key' => 'bazaarSku', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'شناسه محصول (SKU) کافه‌بازار', 'default' => ''],
        ['key' => 'bazaarDealerPackageName', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'پکیج نام فروشنده (خالی = همان پکیج اپ)', 'default' => ''],
        ['key' => 'bazaarPermissionScope', 'category' => 'payment', 'type' => 'number', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'سطح دسترسی لاگین درون‌برنامه‌ای', 'default' => '1'],
        ['key' => 'bazaarRedirectUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نشانی بازگشت کافه‌بازار (خالی = مسیر پیش‌فرض کتابخانه)', 'default' => ''],
        ['key' => 'bazaarConsumeUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'آدرس پایه مصرف خرید کافه‌بازار', 'default' => 'https://pardakht.cafebazaar.ir/devapi/v2/api/consume'],
        ['key' => 'bazaarValidateUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'آدرس پایه اعتبارسنجی خرید کافه‌بازار', 'default' => 'https://pardakht.cafebazaar.ir/devapi/v2/api/validate'],
        ['key' => 'bazaarTimeout', 'category' => 'payment', 'type' => 'number', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'مهلت ارتباط با کافه‌بازار (ثانیه)', 'default' => '20'],

        // ---- payment (BazaarPay / بازارپی web gateway) — system ----
        // Server-side gateway for the WebView build of the app (see
        // docs/bazaarpay-gateway.md). `bazaarPayDestination` is the merchant
        // destination name Cafe Bazaar issues per package name; the test
        // token works with any destination, the production token is bound to
        // the destination created by the BazaarPay team.
        ['key' => 'bazaarPayEnabled', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن درگاه بازارپی', 'default' => '0'],
        ['key' => 'bazaarPayToken', 'category' => 'payment', 'type' => 'secret', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'توکن مرچنت بازارپی', 'default' => ''],
        ['key' => 'bazaarPayDestination', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نام مقصد (Destination Name) بازارپی', 'default' => ''],
        ['key' => 'bazaarPayDirectEnabled', 'category' => 'payment', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن پرداخت مستقیم (دایرکت‌پی)', 'default' => '0'],
        ['key' => 'bazaarPayDirectType', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نوع قرارداد دایرکت‌پی (wallet کیف پول / direct_debit دایرکت دبیت)', 'default' => 'wallet'],
        ['key' => 'bazaarPayDirectPeriod', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'دوره محدودیت قرارداد دایرکت‌پی', 'default' => 'monthly'],
        ['key' => 'bazaarPayDirectAmountLimit', 'category' => 'payment', 'type' => 'number', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'سقف پرداخت هر دوره قرارداد (تومان)', 'default' => '2000000'],
        ['key' => 'bazaarPayBaseUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'آدرس پایه بازارپی', 'default' => 'https://api.bazaar-pay.ir'],
        ['key' => 'bazaarPayCallbackUrl', 'category' => 'payment', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'نشانی پایه بازگشت بازارپی (خالی = ساخت خودکار از نشانی سایت)', 'default' => ''],
        ['key' => 'bazaarPayRialRate', 'category' => 'payment', 'type' => 'number', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'ضریب تبدیل تومان به ریال بازارپی', 'default' => '10'],

        // ---- test modes — system ----
        ['key' => 'smsTestMode', 'category' => 'test', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'حالت آزمایشی پیامک', 'default' => '0'],
        ['key' => 'paymentTestMode', 'category' => 'test', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'حالت آزمایشی درگاه', 'default' => '0'],
        ['key' => 'bazaarTestMode', 'category' => 'test', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'حالت آزمایشی درگاه کافه‌بازار', 'default' => '0'],

        // ---- Firebase Cloud Messaging (push notifications) — system ----
        // The admin pastes these values from the Firebase Console. The app
        // uses them to (a) request a browser/device token on the client side
        // and (b) send push notifications from the server via the FCM HTTP v1
        // API. No third-party PHP package is required — we call Google's
        // oauth2 token endpoint + fcm.googleapis.com directly with the
        // service-account JSON credentials.
        ['key' => 'fcmEnabled', 'category' => 'firebase', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'فعال بودن پوش نوتیفیکیشن (Firebase)', 'default' => '0'],
        ['key' => 'fcmProjectId', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'Firebase Project ID', 'default' => ''],
        ['key' => 'fcmApiKey', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'Firebase Web API Key (apiKey در firebaseConfig)', 'default' => ''],
        ['key' => 'fcmAuthDomain', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'Firebase Auth Domain (authDomain در firebaseConfig)', 'default' => ''],
        ['key' => 'fcmMessagingSenderId', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'Firebase Sender ID (messagingSenderId)', 'default' => ''],
        ['key' => 'fcmAppId', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'Firebase App ID (appId در firebaseConfig)', 'default' => ''],
        ['key' => 'fcmVapidKey', 'category' => 'firebase', 'type' => 'string', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'VAPID Key (برای Web Push)', 'default' => ''],
        ['key' => 'fcmServiceAccount', 'category' => 'firebase', 'type' => 'secret', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'JSON کلید حساب سرویس Firebase (Service Account)', 'default' => ''],
        ['key' => 'fcmTestMode', 'category' => 'test', 'type' => 'boolean', 'scope' => self::SCOPE_SYSTEM,
            'label' => 'حالت آزمایشی پوش (فقط لاگ، ارسال واقعی نمی‌شود)', 'default' => '0'],
    ];

    /**
     * Business-facing keys (public GET /api/settings and business write),
     * derived from the `business` scope so the list can never drift out of
     * sync with the definitions above.
     */
    public static function businessKeys(): array
    {
        return array_values(array_map(
            static fn (array $def): string => $def['key'],
            array_filter(
                self::DEFS,
                static fn (array $def): bool => ($def['scope'] ?? self::SCOPE_SYSTEM) === self::SCOPE_BUSINESS
            )
        ));
    }

    /**
     * Wallet top-up presets, each carrying the Cafe Bazaar SKU whose fixed
     * panel price equals the amount. The value is stored as JSON and may be
     * either the current object shape
     *   [{"amount":50000,"sku":"topup_50k"}, …]
     * or the legacy plain-number shape ([50000, …]); both are normalised here so
     * older rows keep working without a data migration.
     *
     * @return array<int, array{amount:int, sku:string}>
     */
    public static function walletTopUpPresets(): array
    {
        return self::normalizeWalletTopUpPresets(self::raw('walletTopUpPresets'));
    }

    /**
     * @param  array<int|string, mixed>|string|null  $input
     * @return array<int, array{amount:int, sku:string}>
     */
    public static function normalizeWalletTopUpPresets($input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            $input = [];
        }

        $presets = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $amount = (int) ($item['amount'] ?? 0);
                $sku = trim((string) ($item['sku'] ?? ''));
            } else {
                $amount = (int) $item;
                $sku = '';
            }
            if ($amount > 0) {
                $presets[] = ['amount' => $amount, 'sku' => $sku];
            }
        }

        if ($presets === []) {
            foreach ([50000, 100000, 200000, 500000] as $amount) {
                $presets[] = ['amount' => $amount, 'sku' => ''];
            }
        }

        return $presets;
    }

    /** The Cafe Bazaar SKU mapped to a wallet top-up amount ('' when none). */
    public static function walletTopUpSkuFor(int $amount): string
    {
        foreach (self::walletTopUpPresets() as $preset) {
            if ($preset['amount'] === $amount) {
                return $preset['sku'];
            }
        }

        return '';
    }

    /** The wallet amount a Cafe Bazaar SKU maps to (null when unknown). */
    public static function walletTopUpAmountForSku(string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        foreach (self::walletTopUpPresets() as $preset) {
            if ($preset['sku'] !== '' && $preset['sku'] === $sku) {
                return $preset['amount'];
            }
        }

        return null;
    }

    /** Whether an amount is one of the configured wallet top-up presets. */
    public static function isWalletTopUpPreset(int $amount): bool
    {
        foreach (self::walletTopUpPresets() as $preset) {
            if ($preset['amount'] === $amount) {
                return true;
            }
        }

        return false;
    }

    public static function raw(string $key): ?string
    {
        $row = DB::table('settings')->where('key', $key)->value('value');
        if ($row !== null) {
            return (string) $row;
        }
        foreach (self::DEFS as $def) {
            if ($def['key'] === $key) {
                return $def['default'];
            }
        }

        return null;
    }

    public static function get(string $key, ?string $fallback = null): ?string
    {
        $v = self::raw($key);

        return $v !== null ? $v : $fallback;
    }

    public static function bool(string $key): bool
    {
        $v = strtolower(trim((string) self::raw($key)));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function num(string $key, float $fallback = 0): float
    {
        $v = self::raw($key);

        return is_numeric($v) ? (float) $v : $fallback;
    }

    public static function set(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    public static function appUrl(): string
    {
        return rtrim((string) self::raw('appUrl'), '/');
    }

    public static function adminPhones(): array
    {
        $raw = (string) self::raw('adminPhones');

        return array_values(array_filter(array_map(
            fn ($p) => AuthService::normalizePhone(trim($p)),
            explode(',', $raw)
        )));
    }

    public static function smsSettings(): array
    {
        $apiKey = (string) self::raw('smsApiKey');
        $sender = (string) self::raw('smsSender');
        $enabled = self::bool('smsEnabled');
        $testMode = self::bool('smsTestMode');
        $canSend = $enabled && ! $testMode && $apiKey !== '' && $sender !== '';

        return compact('apiKey', 'sender', 'enabled', 'testMode', 'canSend');
    }

    public static function paymentSettings(): array
    {
        $sandbox = self::bool('paymentSandbox');
        $baseUrl = rtrim((string) self::raw('paymentBaseUrl'), '/');
        if ($sandbox) {
            // The official test service only swaps the domain (see docs §7).
            $baseUrl = 'https://sandbox.zarinpal.com';
        }
        if ($baseUrl === '') {
            $baseUrl = 'https://payment.zarinpal.com';
        }

        $callback = trim((string) self::raw('paymentCallbackUrl'));
        if ($callback === '') {
            $appUrl = self::appUrl();
            // Absolute when appUrl is configured; otherwise a relative path the
            // browser resolves against the current origin (test-mode friendly).
            // Real/sandbox calls are rejected later when this is not absolute.
            $callback = $appUrl !== '' ? $appUrl.'/api/payments/verify' : '/api/payments/verify';
        }

        // IRR (Rial) or IRT (Toman); anything else falls back to Toman because
        // every wallet amount in this application is stored in Toman.
        $currency = strtoupper(trim((string) self::raw('paymentCurrency')));
        if (! in_array($currency, ['IRR', 'IRT'], true)) {
            $currency = 'IRT';
        }

        return [
            'enabled' => self::bool('paymentEnabled'),
            'testMode' => self::bool('paymentTestMode'),
            'merchantId' => trim((string) self::raw('paymentMerchantId')),
            'sandbox' => $sandbox,
            'baseUrl' => $baseUrl,
            'callbackUrl' => $callback,
            'currency' => $currency,
            'autoVerify' => self::bool('paymentAutoVerify'),
        ];
    }

    /**
     * A stable Zarinpal merchant id for the sandbox. The docs allow any
     * arbitrary UUID in sandbox, but the SAME value must be sent to both
     * request.json and verify.json, so we generate it once and persist it
     * under `paymentSandboxMerchantId` for reuse across requests.
     */
    public static function sandboxMerchantId(): string
    {
        $existing = trim((string) self::raw('paymentSandboxMerchantId'));
        if ($existing !== '') {
            return $existing;
        }

        $uuid = (string) Str::uuid();
        try {
            self::set('paymentSandboxMerchantId', $uuid);
        } catch (\Throwable) {
            // Settings table may be unavailable during very early boot; the
            // request/verify pair still shares this value within the process.
        }

        return $uuid;
    }

    /**
     * Cafe Bazaar in-app gateway snapshot for the admin panel and the client
     * (only non-secret fields are ever exposed to the browser).
     *
     * @return array{enabled: bool, testMode: bool, available: bool, sku: string, packageName: string, dealerPackageName: string, permissionScope: int}
     */
    public static function bazaarIapSettings(): array
    {
        $packageName = trim((string) self::raw('bazaarPackageName'));
        $apiSecret = trim((string) self::raw('bazaarApiSecret'));
        $enabled = self::bool('bazaarEnabled');
        $testMode = self::bool('bazaarTestMode');

        return [
            'enabled' => $enabled,
            'testMode' => $testMode,
            // Usable only when enabled and fully configured (test mode still
            // needs the package name to build a valid deep link).
            'available' => $enabled && $packageName !== '' && ($testMode || $apiSecret !== ''),
            'sku' => trim((string) self::raw('bazaarSku')),
            'packageName' => $packageName,
            'dealerPackageName' => trim((string) self::raw('bazaarDealerPackageName')) ?: $packageName,
            'permissionScope' => (int) self::num('bazaarPermissionScope', 1),
        ];
    }

    /**
     * BazaarPay gateway snapshot — the public shape exposed to the SPA (no
     * secrets) plus the internal values used by BazaarPayService.
     */
    public static function bazaarPaySettings(): array
    {
        $enabled = self::bool('bazaarPayEnabled');
        $token = trim((string) self::raw('bazaarPayToken'));
        $testMode = self::bool('paymentTestMode');

        $callback = trim((string) self::raw('bazaarPayCallbackUrl'));
        if ($callback === '') {
            $appUrl = self::appUrl();
            // Base of every BazaarPay return URL: /verify (checkout) and
            // /bazaarpay/contract (direct-pay) are appended by the service.
            // Absolute when appUrl is configured, otherwise relative so the
            // test-mode flows still work against the current origin.
            $callback = $appUrl !== '' ? $appUrl.'/api/payments' : '/api/payments';
        }

        $directType = strtolower(trim((string) self::raw('bazaarPayDirectType')));
        if (! in_array($directType, ['wallet', 'direct_debit'], true)) {
            $directType = 'wallet';
        }

        $directPeriod = strtolower(trim((string) self::raw('bazaarPayDirectPeriod')));
        if (! in_array($directPeriod, ['daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'yearly'], true)) {
            $directPeriod = 'monthly';
        }

        $baseUrl = rtrim(trim((string) self::raw('bazaarPayBaseUrl')), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://api.bazaar-pay.ir';
        }

        return [
            'enabled' => $enabled,
            'testMode' => $testMode,
            // Usable when enabled; in test mode no credentials are required.
            'available' => $enabled && ($testMode || $token !== ''),
            'token' => $token,
            'destination' => trim((string) self::raw('bazaarPayDestination')),
            'callbackUrl' => rtrim($callback, '/'),
            'baseUrl' => $baseUrl,
            'rialRate' => max(1, (int) self::num('bazaarPayRialRate', 10)),
            // Direct Pay (automatic order payment through a signed contract).
            'directEnabled' => $enabled && self::bool('bazaarPayDirectEnabled'),
            'directType' => $directType,
            'directPeriod' => $directPeriod,
            'directAmountLimit' => max(10000, (int) self::num('bazaarPayDirectAmountLimit', 2000000)),
        ];
    }

    public static function urgencyMultipliers(): array
    {
        return [
            'normal' => 1.0,
            'urgent' => self::num('urgentPriceMultiplier', 1.3),
            'immediate' => self::num('immediatePriceMultiplier', 1.6),
        ];
    }

    /** Courier delivery cost in toman (OrderController::store passes it to PricingService). */
    public static function courierCost(): int
    {
        return max(0, (int) self::num('courierCost', 0));
    }

    public static function fileRetentionDays(): int
    {
        return max(1, (int) self::num('fileRetentionDays', 14));
    }

    /**
     * Update a settings value honouring the mask protocol: an empty value or
     * the mask itself leaves the stored secret untouched.
     */
    public static function applyUpdate(string $key, ?string $incoming): void
    {
        if ($incoming === null) {
            return;
        }
        $incoming = trim($incoming);
        if ($incoming === self::SECRET_MASK || $incoming === '') {
            return; // keep stored value
        }
        self::set($key, $incoming);
    }

    /**
     * System settings list for the admin panel (masked, boot-only read-only).
     * Business-scoped keys are excluded — they are owned by the business
     * settings panel so the same value is never edited in two places.
     */
    public static function systemSettingsView(): array
    {
        $items = [];
        foreach (self::DEFS as $def) {
            if (($def['scope'] ?? self::SCOPE_SYSTEM) !== self::SCOPE_SYSTEM) {
                continue;
            }
            $stored = DB::table('settings')->where('key', $def['key'])->value('value');
            $value = $stored !== null ? (string) $stored : (string) $def['default'];
            $isSecret = $def['type'] === 'secret';
            $items[] = [
                'key' => $def['key'],
                'category' => $def['category'],
                'type' => $isSecret ? 'secret' : $def['type'],
                'label' => $def['label'],
                'readOnly' => (bool) ($def['bootOnly'] ?? false),
                'value' => $isSecret && $value !== '' ? self::SECRET_MASK : $value,
                'hasValue' => $value !== '',
            ];
        }

        return $items;
    }

    /** Idempotent seeding of every non-boot key (INSERT OR IGNORE semantics). */
    public static function seedDefaults(): void
    {
        foreach (self::DEFS as $def) {
            if ($def['bootOnly'] ?? false) {
                continue; // boot-only values live in .env, never in the DB
            }
            $exists = DB::table('settings')->where('key', $def['key'])->exists();
            if (! $exists) {
                DB::table('settings')->insert([
                    'key' => $def['key'],
                    'value' => (string) $def['default'],
                ]);
            }
        }
    }
}
