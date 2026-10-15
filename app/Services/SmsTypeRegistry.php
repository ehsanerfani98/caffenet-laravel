<?php

namespace App\Services;

/**
 * Central registry that defines the fixed fields for each SMS type.
 *
 * Each SMS type has a FIXED set of fields. The system knows what data
 * each field needs. The admin only enters the IPPanel variable name
 * for each field.
 *
 * Example — "paid" (ثبت سفارش) has two fields:
 *   'order_number' => 'شماره سفارش'    → admin enters: "factor"
 *   'amount'       => 'قیمت (تومان)'   → admin enters: "amount"
 *
 * The system sends: params = {"factor": "CN-1234", "amount": "250000"}
 *
 * No "add variable" button, no dropdowns, no free-form data field.
 * The fields are fixed per SMS type; the admin only fills in the
 * IPPanel variable name for each one.
 */
class SmsTypeRegistry
{
    /**
     * The complete registry of SMS types.
     *
     * Each type has:
     *   - label: Persian title shown in the admin panel
     *   - setting_key: the settings key that holds the pattern code
     *   - fields: array of [field_id => Persian label]
     *     field_id is the INTERNAL identifier (the system knows how to
     *     produce its value). The admin enters the IPPanel variable name
     *     for each field, stored in settings as: {setting_key}_{field_id}
     *
     * @return array<string, array{label: string, setting_key: string, fields: array<string, string>}>
     */
    public static function types(): array
    {
        return [
            'otp' => [
                'label' => 'کد تایید ورود (OTP)',
                'setting_key' => 'smsPatternOtp',
                'fields' => [
                    'code' => 'کد تایید',
                ],
            ],
            'order_started' => [
                'label' => 'شروع سفارش',
                'setting_key' => 'smsPatternStarted',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                    'service_title'   => 'عنوان خدمت',
                    'eta_minutes'     => 'زمان انتظار (دقیقه)',
                    'start_time'      => 'زمان شروع',
                    'tracking_url'    => 'لینک پیگیری',
                ],
            ],
            'order_ready' => [
                'label' => 'آماده شدن سفارش',
                'setting_key' => 'smsPatternReady',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                    'tracking_url'    => 'لینک پیگیری',
                ],
            ],
            'requeued' => [
                'label' => 'انتقال به انتهای صف',
                'setting_key' => 'smsPatternRequeued',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                    'queue_position'  => 'موقعیت جدید در صف',
                ],
            ],
            'cancelled' => [
                'label' => 'لغو سفارش',
                'setting_key' => 'smsPatternCancelled',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                ],
            ],
            'reminder' => [
                'label' => 'یادآوری نزدیک شروع',
                'setting_key' => 'smsPatternReminder',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                    'minutes_before'  => 'دقیقه تا شروع',
                    'start_time'      => 'زمان شروع',
                ],
            ],
            'paid' => [
                'label' => 'پرداخت سفارش (کیف پول)',
                'setting_key' => 'smsPatternPaid',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'amount'          => 'قیمت (تومان)',
                ],
            ],
            'status_change' => [
                'label' => 'تغییر وضعیت سفارش (دستی)',
                'setting_key' => 'smsPatternStatusChange',
                'fields' => [
                    'order_number'    => 'شماره سفارش',
                    'customer_name'   => 'نام مشتری',
                    'status_title'    => 'عنوان وضعیت جدید',
                ],
            ],
            'refund' => [
                'label' => 'عودت مبلغ به کیف پول',
                'setting_key' => 'smsPatternRefund',
                'fields' => [
                    'customer_name'   => 'نام مشتری',
                    'amount'          => 'مبلغ عودتی (تومان)',
                ],
            ],
            'broadcast' => [
                'label' => 'اطلاع‌رسانی همگانی',
                'setting_key' => 'smsPatternBroadcast',
                'fields' => [
                    'title'           => 'عنوان',
                    'body'            => 'متن',
                ],
            ],
        ];
    }

    public static function get(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    public static function settingKey(string $type): ?string
    {
        return self::types()[$type]['setting_key'] ?? null;
    }

    /**
     * Get the fixed fields for a type: [field_id => Persian label]
     */
    public static function fields(string $type): array
    {
        return self::types()[$type]['fields'] ?? [];
    }
}
