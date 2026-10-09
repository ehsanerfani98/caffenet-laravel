<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Zarinpal payment service (REST v4) + internal test gateway.
 *
 *  - requestPayment: POST /pg/v4/payment/request.json
 *  - verifyPayment:  POST /pg/v4/payment/verify.json (codes 100 = ok,
 *    101 = already verified → idempotent double-callback safety)
 *  - StartPay redirect: GET /pg/StartPay/{authority} (the `/pg` segment is
 *    required — see the official docs §3.2).
 *  - Sandbox switches the base URL to sandbox.zarinpal.com and accepts any
 *    UUID merchant id (docs §7); a stable one is generated and reused across
 *    request/verify because both calls must present the SAME terminal id.
 *  - Test mode (`paymentTestMode`) creates TEST- authorities redirecting to
 *    the internal /test-gateway page which drives the same verify pipeline.
 *  - Amounts are ALWAYS recomputed server-side; never trusted from client.
 *  - `currency` (IRT default) is sent explicitly so Toman amounts are not
 *    interpreted as Rial (docs §4).
 *  - `metadata.auto_verify` is sent when the paymentAutoVerify setting is on
 *    (docs §6) so a stale/never-delivered callback cannot silently drop the
 *    transaction; the app still verifies itself and treats code 101 as paid.
 */
class PaymentService
{
    private const TIMEOUT = 20;

    public const MIN_WALLET_TOPUP = 10000;

    public const MAX_WALLET_TOPUP = 50000000;

    /**
     * @return array{ok: bool, redirectUrl?: string, authority?: string, error?: string, code?: int}
     */
    public static function requestPayment(
        int $amount,
        string $description,
        string $callbackUrl,
        string $mobile,
        ?string $orderId = null
    ): array {
        $cfg = SettingsService::paymentSettings();

        if ($cfg['testMode']) {
            $authority = 'TEST-'.Str::upper(Str::random(24));
            $query = http_build_query(['authority' => $authority]);

            return ['ok' => true, 'authority' => $authority, 'redirectUrl' => '/test-gateway?'.$query];
        }

        if (! $cfg['enabled']) {
            return ['ok' => false, 'error' => 'درگاه پرداخت فعال نیست.'];
        }

        // The gateway posts the buyer back to this URL, so it must be absolute.
        if (! self::isAbsoluteUrl($callbackUrl)) {
            return ['ok' => false, 'error' => 'نشانی بازگشت درگاه معتبر نیست؛ «نشانی پایه سایت» یا «نشانی بازگشت درگاه» را در تنظیمات سیستمی وارد کنید.'];
        }

        $merchantId = self::resolveMerchantId($cfg);
        if ($merchantId === null) {
            return ['ok' => false, 'error' => 'کد پذیرنده زرین‌پال تنظیم نشده است.'];
        }

        $metadata = [];
        if (trim($mobile) !== '') {
            $metadata['mobile'] = trim($mobile);
        }
        if ($cfg['autoVerify']) {
            $metadata['auto_verify'] = true;
        }
        if ($orderId !== null && trim($orderId) !== '') {
            $metadata['order_id'] = (string) $orderId;
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($cfg['baseUrl'].'/pg/v4/payment/request.json', [
                    'merchant_id' => $merchantId,
                    'amount' => $amount,
                    'currency' => $cfg['currency'],
                    'description' => $description,
                    'callback_url' => $callbackUrl,
                    'metadata' => $metadata,
                ]);

            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $code = (int) ($data['code'] ?? 0);

            // Code-first: Zarinpal can return a non-2xx status with a valid
            // error body, and a 2xx status with an error code — trust `code`.
            if (($data['authority'] ?? null) && $code === 100) {
                return [
                    'ok' => true,
                    'authority' => $data['authority'],
                    'redirectUrl' => $cfg['baseUrl'].'/pg/StartPay/'.$data['authority'],
                ];
            }

            Log::warning('Zarinpal request rejected', [
                'status' => $response->status(),
                'code' => $code,
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'ایجاد تراکنش پرداخت ناموفق بود.'), 'code' => $code];
        } catch (\Throwable $e) {
            Log::warning('Zarinpal request failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در ارتباط با درگاه پرداخت.'];
        }
    }

    /**
     * Idempotent verify.
     *
     * @return array{ok: bool, already?: bool, refId?: string, cardPan?: string, error?: string, code?: int}
     */
    public static function verifyPayment(string $authority, int $amount): array
    {
        $cfg = SettingsService::paymentSettings();

        if ($cfg['testMode'] || str_starts_with($authority, 'TEST-')) {
            return ['ok' => true, 'refId' => 'TEST-REF-'.random_int(100000, 999999)];
        }

        $merchantId = self::resolveMerchantId($cfg);
        if ($merchantId === null) {
            return ['ok' => false, 'error' => 'کد پذیرنده زرین‌پال تنظیم نشده است.'];
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($cfg['baseUrl'].'/pg/v4/payment/verify.json', [
                    'merchant_id' => $merchantId,
                    'amount' => $amount,
                    'authority' => $authority,
                ]);

            $json = $response->json();
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $code = (int) ($data['code'] ?? 0);

            // 100 = verified now, 101 = already verified previously. Both mean
            // the payment succeeded (docs §3.4); 101 keeps double-callbacks safe.
            if (in_array($code, [100, 101], true)) {
                return [
                    'ok' => true,
                    'already' => $code === 101,
                    'refId' => (string) ($data['ref_id'] ?? ''),
                    'cardPan' => (string) ($data['card_pan'] ?? ''),
                ];
            }

            Log::warning('Zarinpal verify rejected', [
                'status' => $response->status(),
                'code' => $code,
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => self::errorMessage($json, 'تایید پرداخت ناموفق بود.'), 'code' => $code];
        } catch (\Throwable $e) {
            Log::warning('Zarinpal verify failed', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'خطا در تایید تراکنش.'];
        }
    }

    /**
     * The merchant id to send to both request.json and verify.json.
     *
     *  - Configured id wins (trimmed, if non-empty).
     *  - In sandbox an arbitrary UUID is allowed, so one is generated once and
     *    persisted to stay identical between the two calls.
     *  - In real mode a missing id is a hard configuration error (null).
     */
    private static function resolveMerchantId(array $cfg): ?string
    {
        if ($cfg['merchantId'] !== '') {
            return $cfg['merchantId'];
        }

        if (! empty($cfg['sandbox'])) {
            return SettingsService::sandboxMerchantId();
        }

        return null;
    }

    /** Build a Persian error message from either v4 error shape. */
    private static function errorMessage(array $json, string $fallback): string
    {
        $errors = $json['errors'] ?? null;
        if (is_array($errors)) {
            if (isset($errors['message'])) {
                // object shape: {code, message, validations}
                return self::formatError((int) ($errors['code'] ?? 0), (string) $errors['message']);
            }
            if (is_array($errors[0] ?? null) && isset($errors[0]['message'])) {
                // list shape: [{code, message}, …]
                return self::formatError((int) ($errors[0]['code'] ?? 0), (string) $errors[0]['message']);
            }
        }

        return $fallback;
    }

    private static function formatError(int $code, string $message): string
    {
        if ($message === '') {
            return 'خطای درگاه'.($code !== 0 ? ' (کد '.$code.')' : '');
        }

        return $code !== 0 ? 'کد '.$code.': '.$message : $message;
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', trim($url));
    }
}
