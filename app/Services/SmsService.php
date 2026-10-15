<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * ippanel SMS service — ALL messages are pattern-based via the Edge API.
 *
 * No plain-text SMS is ever sent. Every SMS requires:
 *  1. A pattern code (configured in the ippanel panel, pasted into the
 *     admin settings by the operator).
 *  2. A variable mapping (JSON, also configured by the admin) that maps
 *     pattern variable names → field names from the available data pool.
 *
 * The data pool (PATTERN_DATA_FIELDS) is the complete set of values the
 * system can pass to a pattern. The admin picks which ones to use for
 * each pattern by writing a mapping like:
 *   {"order_number": "order_number", "name": "customer_name"}
 * where the KEY is the variable name declared in ippanel and the VALUE
 * is a key from PATTERN_DATA_FIELDS.
 */
class SmsService
{
    private const TIMEOUT = 15;
    private const EDGE_API_URL = 'https://edge.ippanel.com/v1/api/send';

    /**
     * The complete pool of data fields that can be passed to patterns.
     * The admin references these by name in the variable mapping JSON.
     */
    public const PATTERN_DATA_FIELDS = [
        'order_number'       => 'شماره سفارش (مثلاً CN-1234)',
        'customer_name'      => 'نام مشتری',
        'customer_phone'     => 'شماره موبایل مشتری',
        'service_title'      => 'عنوان خدمت',
        'status'             => 'کد وضعیت فعلی سفارش',
        'status_title'       => 'عنوان فارسی وضعیت',
        'eta_minutes'        => 'زمان انتظار تقریبی (دقیقه)',
        'duration_minutes'   => 'مدت انجام تقریبی (دقیقه)',
        'start_time'         => 'زمان تقریبی شروع (ISO)',
        'end_time'           => 'زمان تقریبی تکمیل (ISO)',
        'queue_position'     => 'موقعیت در صف',
        'tracking_url'       => 'لینک پیگیری عمومی',
        'amount'             => 'مبلغ (تومان — برای عودت/پرداخت)',
        'payment_method'     => 'روش پرداخت (wallet/online)',
        'minutes_before'     => 'دقیقه تا شروع (برای یادآوری)',
        'code'               => 'کد تایید OTP',
        'title'              => 'عنوان (برای اطلاع‌رسانی همگانی)',
        'body'               => 'متن (برای اطلاع‌رسانی همگانی)',
        'site_title'         => 'عنوان سایت',
    ];

    /** Logical gate: enabled && !testMode && apiKey && sender present. */
    public static function canSend(): bool
    {
        return SettingsService::smsSettings()['canSend'];
    }

    /**
     * Send the OTP pattern message.
     *
     * @param  string  $phone  Recipient phone.
     * @param  string  $code   The 5-digit OTP code.
     */
    public static function sendOtp(string $phone, string $code): void
    {
        $patternCode = (string) SettingsService::raw('smsPatternOtp');
        $varsJson = (string) SettingsService::raw('smsPatternOtpVars');
        $data = ['code' => $code];

        self::sendPatternFromConfig(
            phone: $phone,
            patternCode: $patternCode,
            varsJson: $varsJson,
            data: $data,
            type: 'otp',
            logText: 'کد تایید ورود'
        );
    }

    /**
     * Send an order-status notification SMS — ALL pattern-based.
     *
     * @param  string  $phone       Customer phone.
     * @param  string  $eventType   One of the EVENT_TYPE_* keys below.
     * @param  array   $data        The data pool (order fields + extras).
     */
    public static function sendOrderNotification(string $phone, string $eventType, array $data = []): void
    {
        if (! SettingsService::bool('smsNotifyEnabled')) {
            return;
        }

        // Map event type → (pattern code setting key, variable mapping setting key).
        $config = self::EVENT_PATTERN_MAP[$eventType] ?? null;
        if (! $config) {
            return;
        }

        self::sendPatternFromConfig(
            phone: $phone,
            patternCode: (string) SettingsService::raw($config[0]),
            varsJson: (string) SettingsService::raw($config[1]),
            data: $data,
            type: $eventType,
            logText: 'پیامک '.$eventType
        );
    }

    /**
     * Event type → [pattern_code_setting_key, vars_mapping_setting_key].
     */
    private const EVENT_PATTERN_MAP = [
        'order_started'  => ['smsPatternStarted',        'smsPatternStartedVars'],
        'order_ready'    => ['smsPatternReady',           'smsPatternReadyVars'],
        'requeued'       => ['smsPatternRequeued',        'smsPatternRequeuedVars'],
        'cancelled'      => ['smsPatternCancelled',       'smsPatternCancelledVars'],
        'reminder'       => ['smsPatternReminder',        'smsPatternReminderVars'],
        'paid'           => ['smsPatternPaid',            'smsPatternPaidVars'],
        'status_change'  => ['smsPatternStatusChange',    'smsPatternStatusChangeVars'],
        'refund'         => ['smsPatternRefund',           'smsPatternRefundVars'],
        'broadcast'      => ['smsPatternBroadcast',       'smsPatternBroadcastVars'],
    ];

    /**
     * Core method: send a pattern SMS using the Edge API.
     *
     * Reads the pattern code + variable mapping from the settings, resolves
     * the variables from the data pool, and POSTs to the Edge API.
     *
     * @param  string  $phone        Recipient phone.
     * @param  string  $patternCode  The pattern code from ippanel.
     * @param  string  $varsJson     JSON mapping: {pattern_var: data_field}.
     * @param  array   $data         The data pool (key→value).
     * @param  string  $type         sms_logs.type value.
     * @param  string  $logText      Short description for sms_logs.
     */
    public static function sendPatternFromConfig(
        string $phone,
        string $patternCode,
        string $varsJson,
        array $data,
        string $type,
        string $logText
    ): void {
        try {
            $s = SettingsService::smsSettings();

            if (! $s['enabled']) {
                self::recordLog($phone, $logText.' — ارسال نشد (پیامک غیرفعال)', $type, 'failed', 'sms disabled');
                return;
            }

            if ($s['testMode']) {
                $params = self::resolveParams($varsJson, $data);
                $desc = $logText.' (آزمایشی) — pattern: '.$patternCode.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE);
                self::recordLog($phone, $desc, $type, 'test');
                return;
            }

            if (! $s['canSend']) {
                self::recordLog($phone, $logText.' — ارسال نشد (اعتبارنامه ناقص)', $type, 'failed', 'missing credentials');
                return;
            }

            if ($patternCode === '') {
                self::recordLog($phone, $logText.' — ارسال نشد (کد پترن خالی)', $type, 'failed', 'empty pattern code');
                return;
            }

            $params = self::resolveParams($varsJson, $data);

            $body = [
                'sending_type' => 'pattern',
                'from_number'  => self::toE164($s['sender']),
                'code'         => $patternCode,
                'recipients'   => [self::toE164($phone)],
                'params'       => $params,
            ];

            $response = Http::withTimeout(self::TIMEOUT)
                ->withHeaders([
                    'Authorization' => $s['apiKey'],
                    'Content-Type'  => 'application/json',
                ])
                ->post(self::EDGE_API_URL, $body);

            $respJson = $response->json();
            $ok = $response->successful() && (bool) ($respJson['meta']['status'] ?? false);
            $providerId = isset($respJson['data']['message_outbox_ids'][0])
                ? (string) $respJson['data']['message_outbox_ids'][0]
                : null;

            if ($ok) {
                self::recordLog($phone, $logText.' — ارسال شد', $type, 'sent', null, $providerId);
            } else {
                $err = 'HTTP '.$response->status();
                if (isset($respJson['meta']['message'])) {
                    $err .= ' — '.$respJson['meta']['message'];
                }
                self::recordLog($phone, $logText.' — ناموفق', $type, 'failed', $err, $providerId);
            }
        } catch (\Throwable $e) {
            self::recordLog($phone, $logText.' — ناموفق', $type, 'failed', mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Resolve the pattern variables from the data pool using the admin's
     * variable mapping.
     *
     * The mapping JSON is: {"pattern_var_name": "data_field_name", ...}
     * For example: {"order_number": "order_number", "name": "customer_name"}
     * This maps the ippanel pattern variable "name" to the system field "customer_name".
     *
     * @return array<string,string> The resolved params for the Edge API.
     */
    private static function resolveParams(string $varsJson, array $data): array
    {
        $mapping = json_decode($varsJson, true);
        if (! is_array($mapping)) {
            $mapping = [];
        }

        $params = [];
        foreach ($mapping as $patternVar => $dataField) {
            $value = $data[$dataField] ?? '';
            $params[$patternVar] = is_scalar($value) ? (string) $value : '';
        }
        return $params;
    }

    public static function recordLog(string $phone, string $text, string $type, string $status, ?string $error = null, ?string $providerId = null): void
    {
        \App\Models\SmsLog::create([
            'phone' => $phone,
            'text' => $text,
            'type' => $type,
            'status' => $status,
            'provider_message_id' => $providerId,
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /** Normalize to international 98… format. */
    public static function normalizePhone(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) {
            return '98'.substr($p, 3);
        }
        if (str_starts_with($p, '0') && strlen($p) === 11) {
            return '98'.substr($p, 1);
        }
        return $p;
    }

    /** Normalize to E.164 (+98…) format used by the ippanel Edge API. */
    public static function toE164(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) {
            return $p;
        }
        if (str_starts_with($p, '0') && strlen($p) === 11) {
            return '+98'.substr($p, 1);
        }
        if (str_starts_with($p, '98')) {
            return '+'.$p;
        }
        return $p;
    }
}
