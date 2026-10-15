<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ippanel SMS service — ALL messages are pattern-based via the Edge API.
 *
 * Each SMS type has FIXED fields (defined in SmsTypeRegistry). For each
 * field, the admin enters the IPPanel variable name in the settings panel.
 * The system knows what data each field needs and fills it automatically.
 *
 * Example — "paid" has fields: order_number, amount
 *   Admin enters: smsPatternPaid = "96ttx5xosutvyp7"
 *                 smsPatternPaid_order_number = "factor"
 *                 smsPatternPaid_amount = "amount"
 *   System sends: params = {"factor": "CN-1234", "amount": "250000"}
 */
class SmsService
{
    private const TIMEOUT = 15;
    private const DEFAULT_EDGE_BASE_URL = 'https://edge.ippanel.com/v1';

    private static function edgeApiUrl(): string
    {
        $base = rtrim((string) SettingsService::raw('smsBaseUrl'), '/');
        if ($base === '') {
            $base = self::DEFAULT_EDGE_BASE_URL;
        }
        return $base.'/api/send';
    }

    public static function canSend(): bool
    {
        return SettingsService::smsSettings()['canSend'];
    }

    public static function sendOtp(string $phone, string $code): void
    {
        self::send($phone, 'otp', ['code' => $code], 'کد تایید ورود');
    }

    public static function sendOrderNotification(string $phone, string $type, array $data = []): void
    {
        if (! SettingsService::bool('smsNotifyEnabled')) {
            return;
        }
        self::send($phone, $type, $data, 'پیامک '.$type);
    }

    /**
     * Core: send a pattern SMS.
     *
     * 1. Read the pattern code from settings.
     * 2. Read the fixed fields from the registry.
     * 3. For each field, read the admin's IPPanel var name from settings
     *    (key = setting_key + '_' + field_id), look up the value in $data.
     * 4. Build params: {admin_var_name: value}
     * 5. POST to Edge API.
     */
    public static function send(string $phone, string $type, array $data, string $logText): void
    {
        try {
            $s = SettingsService::smsSettings();

            if (! $s['enabled']) {
                self::recordLog($phone, $logText.' — ارسال نشد (پیامک غیرفعال)', $type, 'failed', 'sms disabled');
                return;
            }

            $typeDef = SmsTypeRegistry::get($type);
            if (! $typeDef) {
                self::recordLog($phone, $logText.' — نوع نامعتبر', $type, 'failed', 'unknown type');
                return;
            }

            $patternCode = (string) SettingsService::raw($typeDef['setting_key']);
            $params = self::buildParams($typeDef, $data);

            if ($s['testMode']) {
                $desc = $logText.' (آزمایشی) — pattern: '.$patternCode.' params: '.json_encode($params, JSON_UNESCAPED_UNICODE);
                self::recordLog($phone, $desc, $type, 'test');
                return;
            }

            if (! $s['canSend']) {
                self::recordLog($phone, $logText.' — اعتبارنامه ناقص', $type, 'failed', 'missing credentials');
                return;
            }
            if ($patternCode === '') {
                self::recordLog($phone, $logText.' — کد پترن خالی', $type, 'failed', 'empty pattern code');
                return;
            }

            $body = [
                'sending_type' => 'pattern',
                'from_number'  => self::toE164($s['sender']),
                'code'         => $patternCode,
                'recipients'   => [self::toE164($phone)],
                'params'       => $params,
            ];

            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders([
                    'Authorization' => $s['apiKey'],
                    'Content-Type'  => 'application/json',
                ])
                ->post(self::edgeApiUrl(), $body);

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
     * Build params from the registry's fixed fields + the admin's var names.
     *
     * For each field in the registry:
     *   setting_key = typeDef['setting_key'] + '_' + field_id
     *   var_name = SettingsService::raw(setting_key)  ← admin entered this
     *   value = data[field_id]                         ← system produces this
     *   params[var_name] = value
     *
     * Fields with empty var_name are skipped (admin didn't configure them).
     */
    private static function buildParams(array $typeDef, array $data): array
    {
        $params = [];
        foreach ($typeDef['fields'] as $fieldId => $label) {
            $settingKey = $typeDef['setting_key'].'_'.$fieldId;
            $varName = (string) SettingsService::raw($settingKey);
            if ($varName === '') {
                continue; // admin didn't configure this field
            }
            $value = $data[$fieldId] ?? '';
            $params[$varName] = is_scalar($value) ? (string) $value : '';
        }
        return $params;
    }

    public static function recordLog(string $phone, string $text, string $type, string $status, ?string $error = null, ?string $providerId = null): void
    {
        \App\Models\SmsLog::create([
            'phone' => $phone, 'text' => $text, 'type' => $type, 'status' => $status,
            'provider_message_id' => $providerId, 'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public static function normalizePhone(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) return '98'.substr($p, 3);
        if (str_starts_with($p, '0') && strlen($p) === 11) return '98'.substr($p, 1);
        return $p;
    }

    public static function toE164(string $raw): string
    {
        $p = AuthService::normalizePhone($raw);
        if (str_starts_with($p, '+98')) return $p;
        if (str_starts_with($p, '0') && strlen($p) === 11) return '+98'.substr($p, 1);
        if (str_starts_with($p, '98')) return '+'.$p;
        return $p;
    }
}
