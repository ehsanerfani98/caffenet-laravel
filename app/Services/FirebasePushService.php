<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications via Firebase Cloud Messaging (HTTP v1 API).
 *
 * No third-party PHP package is required — we sign a JWT with the
 * service-account private key, exchange it for a Google OAuth2 access
 * token, then POST to
 *   https://fcm.googleapis.com/v1/projects/<projectId>/messages:send
 *
 * Configuration is read from the settings table (the admin pastes the
 * service-account JSON + project ID in the system settings panel):
 *  - fcmEnabled        : master toggle
 *  - fcmProjectId      : e.g. "caffenet-12345"
 *  - fcmServiceAccount : the full JSON downloaded from Firebase Console
 *                        → Project Settings → Service Accounts → Generate
 *                        New Private Key
 *  - fcmTestMode       : when true, the message is logged but NOT sent
 *                        (useful for staging)
 *
 * Usage:
 *   FirebasePushService::sendToUser($user->id, [
 *       'title' => 'سفارش شما شروع شد',
 *       'body'  => 'کار روی سفارش CN-1234 آغاز شد.',
 *       'data'  => ['orderId' => $order->id, 'type' => 'order_started'],
 *       'url'   => '/orders/123/track',
 *   ]);
 */
class FirebasePushService
{
    private const GOOGLE_OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FCM_SEND_URL_TEMPLATE = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const CACHE_KEY_ACCESS_TOKEN = 'fcm:access_token';

    /**
     * Send a push notification to ALL active tokens belonging to a user.
     *
     * @param  string  $userId  The recipient's user id (orders.customer_id)
     * @param  array   $payload {title, body, data?, url?}
     * @return int Number of tokens the message was sent to (0 if disabled or no tokens)
     */
    public static function sendToUser(string $userId, array $payload): int
    {
        if (! SettingsService::bool('fcmEnabled')) {
            return 0;
        }

        $tokens = FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->get();
        if ($tokens->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach ($tokens as $token) {
            $ok = self::sendToToken($token->token, $payload);
            if ($ok) {
                $sent++;
            } else {
                // FCM returned an "invalid registration token" style error —
                // disable the token so we don't keep trying to send to it.
                $token->is_active = false;
                $token->updated_at = now()->toIso8601String();
                $token->save();
            }
        }

        return $sent;
    }

    /**
     * Send a push notification to a single FCM token. Returns true on
     * success, false on failure.
     */
    public static function sendToToken(string $fcmToken, array $payload): bool
    {
        $projectId = SettingsService::get('fcmProjectId', '');
        if ($projectId === '') {
            Log::warning('FirebasePushService: fcmProjectId is empty — skipping send.');
            return false;
        }

        // Test mode: log and pretend success.
        if (SettingsService::bool('fcmTestMode')) {
            Log::info('FirebasePushService (test mode): would send push', [
                'token' => substr($fcmToken, 0, 20).'...',
                'payload' => $payload,
            ]);
            return true;
        }

        $accessToken = self::getAccessToken();
        if ($accessToken === null) {
            Log::error('FirebasePushService: could not obtain access token.');
            return false;
        }

        $url = sprintf(self::FCM_SEND_URL_TEMPLATE, $projectId);
        $body = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $payload['title'] ?? '',
                    'body' => $payload['body'] ?? '',
                ],
                'data' => self::normalizeData($payload['data'] ?? [], $payload['url'] ?? null),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'caffenet_orders',
                        'icon' => 'icon',
                        'color' => '#1559de',
                    ],
                ],
                'webpush' => [
                    'fcm_options' => [
                        'link' => $payload['url'] ?? '/',
                    ],
                ],
            ],
        ];

        try {
            $resp = Http::withToken($accessToken)
                ->timeout(15)
                ->post($url, $body);

            if ($resp->successful()) {
                return true;
            }

            $errBody = $resp->body();
            Log::error('FirebasePushService: FCM returned error', [
                'status' => $resp->status(),
                'body' => mb_substr($errBody, 0, 500),
            ]);

            // FCM returns 404 + "UNREGISTERED" when the token is no longer valid.
            if ($resp->status() === 404 || str_contains($errBody, 'UNREGISTERED')) {
                return false; // caller will disable the token
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('FirebasePushService: HTTP request failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Register (upsert) a device token for a user. If the token already
     * exists (same browser visiting again), it's reactivated + its
     * user_agent/platform fields are refreshed.
     */
    public static function registerToken(string $userId, string $token, ?string $userAgent = null, ?string $platform = 'web'): void
    {
        $existing = FcmToken::where('token', $token)->first();
        $now = now()->toIso8601String();

        if ($existing) {
            $existing->user_id = $userId;
            $existing->user_agent = $userAgent;
            $existing->platform = $platform;
            $existing->is_active = true;
            $existing->updated_at = $now;
            $existing->save();
        } else {
            FcmToken::create([
                'user_id' => $userId,
                'token' => $token,
                'user_agent' => $userAgent,
                'platform' => $platform,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Deactivate all tokens for a user (e.g. on logout).
     */
    public static function deactivateUserTokens(string $userId): void
    {
        FcmToken::where('user_id', $userId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now()->toIso8601String(),
            ]);
    }

    // ─── Internals ────────────────────────────────────────────────────

    /**
     * Obtain a Google OAuth2 access token signed with the service-account
     * private key. Cached for ~50 minutes (tokens last 1 hour).
     */
    private static function getAccessToken(): ?string
    {
        $cached = Cache::store('database')->get(self::CACHE_KEY_ACCESS_TOKEN);
        if ($cached && is_string($cached) && $cached !== '') {
            return $cached;
        }

        $serviceAccount = self::parseServiceAccount();
        if ($serviceAccount === null) {
            return null;
        }

        $now = time();
        $jwtPayload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => self::GOOGLE_OAUTH_TOKEN_URL,
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        $jwt = self::encodeJwt($jwtPayload, $serviceAccount['private_key']);
        if ($jwt === null) {
            return null;
        }

        try {
            $resp = Http::asForm()->timeout(15)->post(self::GOOGLE_OAUTH_TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $resp->successful()) {
                Log::error('FirebasePushService: OAuth token request failed', [
                    'status' => $resp->status(),
                    'body' => mb_substr($resp->body(), 0, 300),
                ]);
                return null;
            }

            $data = $resp->json();
            $token = $data['access_token'] ?? null;
            $expiresIn = (int) ($data['expires_in'] ?? 3600);
            if ($token) {
                // Cache for slightly less than the actual expiry.
                Cache::store('database')->put(
                    self::CACHE_KEY_ACCESS_TOKEN,
                    $token,
                    max(60, $expiresIn - 120)
                );
            }

            return $token;
        } catch (\Throwable $e) {
            Log::error('FirebasePushService: OAuth request exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse the service-account JSON stored in the settings table.
     * Returns null if missing or invalid.
     */
    private static function parseServiceAccount(): ?array
    {
        // SettingsService masks secrets in API responses; the raw() method
        // bypasses the mask and reads the actual stored value.
        $raw = SettingsService::raw('fcmServiceAccount');
        if (! $raw) {
            Log::warning('FirebasePushService: fcmServiceAccount is empty.');
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            Log::error('FirebasePushService: service-account JSON is malformed.');
            return null;
        }

        return $data;
    }

    /**
     * Encode a JWT with the RS256 algorithm using the service-account
     * private key (PEM format).
     */
    private static function encodeJwt(array $payload, string $privateKeyPem): ?string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $segments[] = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            Log::error('FirebasePushService: openssl_sign failed.', [
                'openssl_error' => openssl_error_string(),
            ]);
            return null;
        }
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * FCM requires all data values to be strings. Booleans/ints are
     * cast automatically.
     */
    private static function normalizeData(array $data, ?string $url): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = is_string($v) ? $v : (string) (int) $v;
        }
        if ($url) {
            $out['url'] = $url;
        }
        return $out;
    }
}
