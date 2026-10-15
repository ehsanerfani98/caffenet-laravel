<?php

namespace App\Services;

/**
 * Central registry that defines the "data contract" for each SMS type.
 *
 * Each SMS type declares which INTERNAL data identifiers it needs.
 * The system produces the values for these identifiers; the admin maps
 * them to IPPanel variable names in the settings panel.
 *
 * INTERNAL IDENTIFIERS (declared here, in code):
 *   These are the keys the system uses to look up values in the data pool.
 *   They are NOT the IPPanel variable names — the admin can name IPPanel
 *   variables anything they want and map them to these identifiers.
 *
 * Example:
 *   Registry says: 'otp' needs ['code']
 *   Admin maps:    var_name='otp' → data_field='code'
 *   System sends:  params = {'otp': '12345'}
 *
 *   Admin could also map: var_name='verification-code' → data_field='code'
 *   System would send:    params = {'verification-code': '12345'}
 *
 * The admin NEVER sees or changes the internal identifiers. They only
 * enter the IPPanel variable name + pick which internal identifier it
 * maps to (from the list declared here).
 */
class SmsTypeRegistry
{
    /**
     * The complete registry of SMS types and their data contracts.
     *
     * @return array<string, array{label: string, setting_key: string, data_keys: array<int, string>}>
     */
    public static function types(): array
    {
        return [
            'otp' => [
                'label' => 'کد تایید ورود (OTP)',
                'setting_key' => 'smsPatternOtp',
                'data_keys' => ['code'],
            ],
            'order_started' => [
                'label' => 'شروع سفارش',
                'setting_key' => 'smsPatternStarted',
                'data_keys' => [
                    'order_number', 'customer_name', 'customer_phone', 'service_title',
                    'status', 'status_title', 'eta_minutes', 'duration_minutes',
                    'start_time', 'end_time', 'queue_position', 'tracking_url',
                    'site_title',
                ],
            ],
            'order_ready' => [
                'label' => 'آماده شدن سفارش',
                'setting_key' => 'smsPatternReady',
                'data_keys' => [
                    'order_number', 'customer_name', 'service_title',
                    'tracking_url', 'site_title',
                ],
            ],
            'requeued' => [
                'label' => 'انتقال به انتهای صف',
                'setting_key' => 'smsPatternRequeued',
                'data_keys' => [
                    'order_number', 'customer_name', 'queue_position', 'site_title',
                ],
            ],
            'cancelled' => [
                'label' => 'لغو سفارش',
                'setting_key' => 'smsPatternCancelled',
                'data_keys' => [
                    'order_number', 'customer_name', 'site_title',
                ],
            ],
            'reminder' => [
                'label' => 'یادآوری نزدیک شروع',
                'setting_key' => 'smsPatternReminder',
                'data_keys' => [
                    'order_number', 'customer_name', 'minutes_before',
                    'start_time', 'site_title',
                ],
            ],
            'paid' => [
                'label' => 'پرداخت سفارش (کیف پول)',
                'setting_key' => 'smsPatternPaid',
                'data_keys' => [
                    'order_number', 'customer_name', 'amount', 'payment_method',
                    'site_title',
                ],
            ],
            'status_change' => [
                'label' => 'تغییر وضعیت سفارش (دستی)',
                'setting_key' => 'smsPatternStatusChange',
                'data_keys' => [
                    'order_number', 'customer_name', 'status', 'status_title',
                    'site_title',
                ],
            ],
            'refund' => [
                'label' => 'عودت مبلغ به کیف پول',
                'setting_key' => 'smsPatternRefund',
                'data_keys' => [
                    'customer_name', 'amount', 'site_title',
                ],
            ],
            'broadcast' => [
                'label' => 'اطلاع‌رسانی همگانی',
                'setting_key' => 'smsPatternBroadcast',
                'data_keys' => ['title', 'body', 'site_title'],
            ],
        ];
    }

    /**
     * Get the registry entry for a single SMS type.
     */
    public static function get(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    /**
     * Get the settings key that holds the pattern code for a type.
     */
    public static function settingKey(string $type): ?string
    {
        return self::types()[$type]['setting_key'] ?? null;
    }

    /**
     * Get the list of internal data identifiers a type needs.
     * These are the keys the caller must provide in the data pool.
     */
    public static function dataKeys(string $type): array
    {
        return self::types()[$type]['data_keys'] ?? [];
    }

    /**
     * Human-readable label for each internal data identifier.
     * Shown in the admin panel as a reference.
     */
    public const DATA_LABELS = [
        'code'              => 'کد تایید OTP',
        'order_number'      => 'شماره سفارش (CN-1234)',
        'customer_name'     => 'نام مشتری',
        'customer_phone'    => 'شماره موبایل مشتری',
        'service_title'     => 'عنوان خدمت',
        'status'            => 'کد وضعیت سفارش',
        'status_title'      => 'عنوان فارسی وضعیت',
        'eta_minutes'       => 'زمان انتظار تقریبی (دقیقه)',
        'duration_minutes'  => 'مدت انجام تقریبی (دقیقه)',
        'start_time'        => 'زمان تقریبی شروع',
        'end_time'          => 'زمان تقریبی تکمیل',
        'queue_position'    => 'موقعیت در صف',
        'tracking_url'      => 'لینک پیگیری عمومی',
        'amount'            => 'مبلغ (تومان)',
        'payment_method'    => 'روش پرداخت',
        'minutes_before'    => 'دقیقه تا شروع (یادآوری)',
        'title'             => 'عنوان (همگانی)',
        'body'              => 'متن (همگانی)',
        'site_title'        => 'عنوان سایت',
    ];
}
