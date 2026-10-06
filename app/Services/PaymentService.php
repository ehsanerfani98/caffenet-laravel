<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Zarinpal payment service (REST v4) + internal test gateway — a faithful
 * port of server/payment.ts.
 *
 *  - requestPayment: POST /pg/v4/payment/request.json
 *  - verifyPayment:  POST /pg/v4/payment/verify.json (codes 100 = ok,
 *    101 = already verified → idempotent double-callback safety)
 *  - Sandbox switches the base URL to sandbox.zarinpal.com.
 *  - Test mode (`paymentTestMode`) creates TEST- authorities redirecting to
 *    the internal /test-gateway page which drives the same verify pipeline.
 *  - Amounts are ALWAYS recomputed server-side; never trusted from client.
 */
class PaymentService
{
    private const TIMEOUT = 20;

    public const MIN_WALLET_TOPUP = 10000;

    public const MAX_WALLET_TOPUP = 50000000;

    /**
     * @return array{ok: bool, redirectUrl?: string, authority?: string, error?: string}
     */
    public static function requestPayment(int $amount, string $description, string $callbackUrl, string $mobile): array
    {
        $cfg = SettingsService::paymentSettings();

        if ($cfg['testMode']) {
            $authority = 'TEST-'.Str::upper(Str::random(24));
            $query = http_build_query(['authority' => $authority]);

            return ['ok' => true, 'authority' => $authority, 'redirectUrl' => '/test-gateway?'.$query];
        }

        if (! $cfg['enabled'] || trim($cfg['merchantId']) === '' || $callbackUrl === '') {
            return ['ok' => false, 'error' => 'درگاه پرداخت فعال نیست یا تنظیم نشده است.'];
        }

        try {
            $response = Http::withTimeout(self::TIMEOUT)->post($cfg['baseUrl'].'/pg/v4/payment/request.json', [
                'merchant_id' => $cfg['merchantId'],
                'amount' => $amount,
                'description' => $description,
                'callback_url' => $callbackUrl,
                'metadata' => ['mobile' => $mobile],
            ]);

            $json = $response->json();
            $data = $json['data'] ?? null;

            if ($response->successful() && ($data['authority'] ?? null) && ($data['code'] ?? 0) === 100) {
                return [
                    'ok' => true,
                    'authority' => $data['authority'],
                    'redirectUrl' => $cfg['baseUrl'].'/StartPay/'.$data['authority'],
                ];
            }

            $msg = $json['errors']['message'] ?? null;

            return ['ok' => false, 'error' => $msg ? 'خطای درگاه: '.$msg : 'خطا در ایجاد تراکنش پرداخت.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با درگاه پرداخت.'];
        }
    }

    /**
     * Idempotent verify. @return array{ok: bool, already?: bool, refId?: string, cardPan?: string, error?: string}
     */
    public static function verifyPayment(string $authority, int $amount): array
    {
        $cfg = SettingsService::paymentSettings();

        if ($cfg['testMode'] || str_starts_with($authority, 'TEST-')) {
            return ['ok' => true, 'refId' => 'TEST-REF-'.random_int(100000, 999999)];
        }

        try {
            $response = Http::withTimeout(self::TIMEOUT)->post($cfg['baseUrl'].'/pg/v4/payment/verify.json', [
                'merchant_id' => $cfg['merchantId'],
                'amount' => $amount,
                'authority' => $authority,
            ]);

            $json = $response->json();
            $data = $json['data'] ?? null;
            $code = $data['code'] ?? 0;

            if ($response->successful() && in_array($code, [100, 101], true)) {
                return [
                    'ok' => true,
                    'already' => $code === 101,
                    'refId' => (string) ($data['ref_id'] ?? ''),
                    'cardPan' => (string) ($data['card_pan'] ?? ''),
                ];
            }

            return ['ok' => false, 'error' => 'تایید پرداخت ناموفق بود.'];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'خطا در تایید تراکنش.'];
        }
    }
}
