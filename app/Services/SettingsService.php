<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

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
     * The full definition list. Categories: general | auth | sms | payment | test | runtime.
     * `bootOnly` keys are read from env and surfaced read-only in the panel.
     */
    public const DEFS = [
        // ---- boot-only (runtime) ----
        ['key' => 'nodeEnv', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true,
            'label' => 'محیط اجرا', 'default' => 'development'],
        ['key' => 'port', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true,
            'label' => 'پورت سرور', 'default' => '8000'],
        ['key' => 'databasePath', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true,
            'label' => 'مسیر فایل دیتابیس', 'default' => 'database/caffenet.sqlite'],
        ['key' => 'uploadsDir', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true,
            'label' => 'پوشه فایل‌های خصوصی', 'default' => 'storage/app/uploads'],
        ['key' => 'mediaDir', 'category' => 'runtime', 'type' => 'string', 'bootOnly' => true,
            'label' => 'پوشه عمومی رسانه', 'default' => 'public/media'],

        // ---- general ----
        ['key' => 'appUrl', 'category' => 'general', 'type' => 'string',
            'label' => 'نشانی پایه سایت', 'default' => ''],
        ['key' => 'adminPhones', 'category' => 'general', 'type' => 'string',
            'label' => 'شماره‌های مدیران (با کاما)', 'default' => ''],
        ['key' => 'siteTitle', 'category' => 'general', 'type' => 'string',
            'label' => 'عنوان سایت', 'default' => 'کافی‌نت آنلاین'],
        ['key' => 'coffeenetAddress', 'category' => 'general', 'type' => 'string',
            'label' => 'آدرس دفتر', 'default' => ''],
        ['key' => 'coffeenetPhone', 'category' => 'general', 'type' => 'string',
            'label' => 'تلفن دفتر', 'default' => ''],
        ['key' => 'workingHours', 'category' => 'general', 'type' => 'string',
            'label' => 'ساعت کاری', 'default' => ''],
        ['key' => 'fileRetentionDays', 'category' => 'general', 'type' => 'number',
            'label' => 'روز نگهداری فایل', 'default' => '14'],

        // ---- sms ----
        ['key' => 'smsEnabled', 'category' => 'sms', 'type' => 'boolean',
            'label' => 'فعال بودن سامانه پیامک', 'default' => '0'],
        ['key' => 'smsApiKey', 'category' => 'sms', 'type' => 'secret',
            'label' => 'کلید دسترسی ippanel', 'default' => ''],
        ['key' => 'smsSender', 'category' => 'sms', 'type' => 'string',
            'label' => 'شماره فرستنده', 'default' => ''],
        ['key' => 'smsOtpPattern', 'category' => 'sms', 'type' => 'string',
            'label' => 'کد پترن کد تایید', 'default' => ''],
        ['key' => 'smsBaseUrl', 'category' => 'sms', 'type' => 'string',
            'label' => 'آدرس پایه ippanel', 'default' => 'https://api2.ippanel.com/api/v1'],

        // ---- payment ----
        ['key' => 'paymentEnabled', 'category' => 'payment', 'type' => 'boolean',
            'label' => 'فعال بودن درگاه پرداخت', 'default' => '0'],
        ['key' => 'paymentMerchantId', 'category' => 'payment', 'type' => 'secret',
            'label' => 'کد پذیرنده زرین‌پال', 'default' => ''],
        ['key' => 'paymentSandbox', 'category' => 'payment', 'type' => 'boolean',
            'label' => 'محیط sandbox زرین‌پال', 'default' => '0'],
        ['key' => 'paymentBaseUrl', 'category' => 'payment', 'type' => 'string',
            'label' => 'آدرس پایه زرین‌پال', 'default' => 'https://payment.zarinpal.com'],
        ['key' => 'paymentCallbackUrl', 'category' => 'payment', 'type' => 'string',
            'label' => 'نشانی بازگشت درگاه', 'default' => ''],

        // ---- business pricing ----
        ['key' => 'urgentPriceMultiplier', 'category' => 'general', 'type' => 'string',
            'label' => 'ضریب سفارش فوری', 'default' => '1.3'],
        ['key' => 'immediatePriceMultiplier', 'category' => 'general', 'type' => 'string',
            'label' => 'ضریب سفارش آنی', 'default' => '1.6'],
        ['key' => 'courierCost', 'category' => 'general', 'type' => 'number',
            'label' => 'هزینه پیک (تومان)', 'default' => '35000'],
        ['key' => 'enableWalletPayment', 'category' => 'payment', 'type' => 'boolean',
            'label' => 'فعال بودن پرداخت کیف پول', 'default' => '1'],
        ['key' => 'enableCoupons', 'category' => 'payment', 'type' => 'boolean',
            'label' => 'فعال بودن کدهای تخفیف', 'default' => '1'],
        ['key' => 'walletTopUpPresets', 'category' => 'payment', 'type' => 'string',
            'label' => 'مبالغ پیشنهادی شارژ کیف پول', 'default' => '[50000,100000,200000,500000]'],

        // ---- test modes ----
        ['key' => 'otpTestMode', 'category' => 'test', 'type' => 'boolean',
            'label' => 'حالت آزمایشی کد تایید', 'default' => '0'],
        ['key' => 'smsTestMode', 'category' => 'test', 'type' => 'boolean',
            'label' => 'حالت آزمایشی پیامک', 'default' => '0'],
        ['key' => 'paymentTestMode', 'category' => 'test', 'type' => 'boolean',
            'label' => 'حالت آزمایشی درگاه', 'default' => '0'],
    ];

    /** Business-facing keys exposed via GET /api/settings (public). */
    public const BUSINESS_KEYS = [
        'siteTitle', 'coffeenetAddress', 'coffeenetPhone', 'workingHours',
        'fileRetentionDays', 'urgentPriceMultiplier', 'immediatePriceMultiplier',
        'courierCost', 'enableWalletPayment', 'enableCoupons', 'otpTestMode',
        'walletTopUpPresets',
    ];

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
        $baseUrl = rtrim((string) self::raw('paymentBaseUrl'), '/');
        if (self::bool('paymentSandbox')) {
            $baseUrl = 'https://sandbox.zarinpal.com';
        }
        $callback = (string) self::raw('paymentCallbackUrl');
        if (trim($callback) === '') {
            $appUrl = self::appUrl();
            // Absolute when appUrl is configured; otherwise a relative path the
            // browser resolves against the current origin (test-mode friendly).
            $callback = $appUrl !== '' ? $appUrl.'/api/payments/verify' : '/api/payments/verify';
        }

        return [
            'enabled' => self::bool('paymentEnabled'),
            'testMode' => self::bool('paymentTestMode'),
            'merchantId' => (string) self::raw('paymentMerchantId'),
            'sandbox' => self::bool('paymentSandbox'),
            'baseUrl' => $baseUrl,
            'callbackUrl' => $callback,
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

    public static function courierCost(): int
    {
        return (int) self::num('courierCost', 35000);
    }

    public static function fileRetentionDays(): int
    {
        return max(1, (int) self::num('fileRetentionDays', 14));
    }

    public static function otpTestMode(): bool
    {
        return self::bool('otpTestMode');
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

    /** System settings list for the admin panel (masked, boot-only read-only). */
    public static function systemSettingsView(): array
    {
        $items = [];
        foreach (self::DEFS as $def) {
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
