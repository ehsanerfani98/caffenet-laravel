<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\FcmPushLog;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\SenderIdMismatch;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications via Firebase Cloud Messaging using the
 * kreait/firebase-php Admin SDK (8.x) — the actively maintained PHP SDK
 * that speaks the CURRENT FCM HTTP v1 API exclusively (the legacy
 * fcm.googleapis.com/fcm/send API was shut down by Google in June 2024).
 *
 * Why the SDK instead of hand-rolled HTTP:
 *  - OAuth2 access-token minting/refresh is handled (and cached) by the SDK.
 *  - Typed exceptions map 1:1 to FCM error codes: NotFound (UNREGISTERED),
 *    SenderIdMismatch, QuotaExceeded, ServerUnavailable, ServerError,
 *    InvalidMessage, AuthenticationError — so we can tell a DEAD token from
 *    a TRANSIENT outage.
 *  - sendMulticast fans a user's devices out with per-token reports.
 *  - When Google changes endpoints again, a composer update keeps us current.
 *
 * ⚠ Token lifecycle rule (fixes the "notifications stopped arriving" bug):
 *  A token is deactivated ONLY for permanent errors (NotFound /
 *  SenderIdMismatch / invalid-argument). Transient failures (network,
 *  5xx, quota) NEVER deactivate a token. The previous implementation
 *  deactivated on ANY failure, so a single temporary outage silently
 *  killed every registered device and pushes stopped going out.
 *
 * Topic subscriptions go through the SDK. NOTE: the SDK still uses the
 * legacy Instance ID API (iid.googleapis.com) under the hood for topic
 * management — deprecated by Google but functional; it will be retired on
 * 2027-09-29 and the SDK authors will migrate it. Message SENDING (the
 * part that actually delivers notifications) is fully on the v1 API.
 *
 * Configuration is read from the settings table (the admin pastes the
 * service-account JSON + web config in the system settings panel):
 *  - fcmEnabled        : master toggle
 *  - fcmProjectId      : e.g. "caffenet-53608"
 *  - fcmServiceAccount : the full JSON downloaded from Firebase Console
 *                        → Project Settings → Service Accounts → Generate
 *                        New Private Key
 *  - fcmTestMode       : when true, the message is logged but NOT sent
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
    /**
     * sendMulticast fans out one HTTP request per token; cap the batch so
     * a pathological token list can't blow up memory / connection pool.
     */
    private const MULTICAST_CHUNK = 500;

    private static ?Messaging $messaging = null;

    /**
     * Hash of the settings the cached Messaging instance was built from —
     * changing the service account or project id in the admin panel
     * transparently rebuilds it on the next send.
     */
    private static ?string $messagingKey = null;

    /**
     * Test seam: injects a Messaging contract implementation so unit tests
     * can drive the service without touching Google. Null = production
     * behaviour (build from settings).
     */
    private static ?Messaging $messagingOverride = null;

    /** @internal Used by tests only. */
    public static function setMessagingOverride(?Messaging $messaging): void
    {
        self::$messagingOverride = $messaging;
        self::$messaging = null;
        self::$messagingKey = null;
    }

    /**
     * Resolve the FCM Messaging client, or null when push is not usable
     * (disabled config / malformed service account). The instance is
     * cached per (serviceAccount, projectId) pair; the SDK caches the
     * underlying OAuth2 access token internally (≈1h validity).
     */
    private static function messaging(): ?Messaging
    {
        if (self::$messagingOverride instanceof Messaging) {
            return self::$messagingOverride;
        }

        $serviceAccountRaw = (string) SettingsService::raw('fcmServiceAccount');
        $projectId = (string) SettingsService::get('fcmProjectId', '');
        $key = md5($serviceAccountRaw.'|'.$projectId);

        if (self::$messaging instanceof Messaging && self::$messagingKey === $key) {
            return self::$messaging;
        }

        $serviceAccount = self::parseServiceAccount();
        if ($serviceAccount === null) {
            return null;
        }

        try {
            $factory = (new Factory)->withServiceAccount($serviceAccount);
            if ($projectId !== '') {
                $factory = $factory->withProjectId($projectId);
            }
            self::$messaging = $factory->createMessaging();
            self::$messagingKey = $key;

            return self::$messaging;
        } catch (\Throwable $e) {
            Log::error('FirebasePushService: could not create Messaging client', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a push notification to ALL active tokens belonging to a user.
     * Logs the attempt to fcm_push_logs.
     *
     * @param  string  $userId  The recipient's user id (orders.customer_id)
     * @param  array   $payload {title, body, data?, url?}
     * @return int Number of tokens the message was successfully sent to
     *            (0 when disabled, misconfigured or no active tokens)
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

        $testMode = SettingsService::bool('fcmTestMode');
        if ($testMode) {
            Log::info('FirebasePushService (test mode): would send user push', [
                'user_id' => $userId,
                'tokens' => $tokens->count(),
                'payload' => $payload,
            ]);
            self::logPush([
                'channel' => 'user',
                'target' => $userId,
                'title' => $payload['title'] ?? '',
                'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'sent',
                'sent_count' => 0,
                'failed_count' => 0,
            ]);

            return $tokens->count();
        }

        $messaging = self::messaging();
        if ($messaging === null) {
            self::logPush([
                'channel' => 'user',
                'target' => $userId,
                'title' => $payload['title'] ?? '',
                'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'failed',
                'sent_count' => 0,
                'failed_count' => $tokens->count(),
                'error' => 'Messaging client unavailable (missing/malformed fcmServiceAccount or fcmProjectId)',
            ]);

            return 0;
        }

        $message = self::buildMessage($payload);
        $sent = 0;
        $failed = 0;
        /** @var array<int, string> $deadTokens tokens FCM says are gone */
        $deadTokens = [];
        $firstError = null;

        foreach (array_chunk($tokens->pluck('token')->all(), self::MULTICAST_CHUNK) as $chunk) {
            try {
                $report = $messaging->sendMulticast($message, $chunk);
            } catch (MessagingException $e) {
                // Whole batch rejected (auth/quota/server) — TRANSIENT or
                // config-level: none of the tokens may be deactivated.
                $failed += count($chunk);
                $firstError ??= $e->getMessage();
                Log::error('FirebasePushService: multicast batch failed', [
                    'user_id' => $userId,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
                continue;
            } catch (\Throwable $e) {
                // Connection-level failure — transient, keep tokens alive.
                $failed += count($chunk);
                $firstError ??= $e->getMessage();
                Log::error('FirebasePushService: multicast request failed', [
                    'user_id' => $userId,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
                continue;
            }

            foreach ($report->getItems() as $item) {
                $tokenValue = (string) $item->target()->value();

                if ($item->isSuccess()) {
                    $sent++;
                    continue;
                }

                $failed++;
                $error = $item->error();
                $firstError ??= $error?->getMessage();

                // Permanent, per-token errors only → retire the token.
                if ($item->messageWasSentToUnknownToken()
                    || $error instanceof NotFound
                    || $error instanceof SenderIdMismatch) {
                    $deadTokens[] = $tokenValue;
                    continue;
                }

                // Anything else (ServerUnavailable, ServerError,
                // QuotaExceeded, ApiConnectionFailed, InvalidMessage …)
                // must NOT kill the token.
                Log::warning('FirebasePushService: per-token send failed (token kept active)', [
                    'user_id' => $userId,
                    'token' => substr($tokenValue, 0, 20).'…',
                    'error' => mb_substr((string) $error?->getMessage(), 0, 300),
                ]);
            }
        }

        if ($deadTokens !== []) {
            FcmToken::whereIn('token', $deadTokens)
                ->update([
                    'is_active' => false,
                    'updated_at' => now()->toIso8601String(),
                ]);
            Log::info('FirebasePushService: deactivated unregistered tokens', [
                'user_id' => $userId,
                'count' => count($deadTokens),
            ]);
        }

        self::logPush([
            'channel' => 'user',
            'target' => $userId,
            'title' => $payload['title'] ?? '',
            'body' => $payload['body'] ?? '',
            'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
            'status' => $failed === 0 ? 'sent' : ($sent === 0 ? 'failed' : 'partial'),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'error' => $firstError !== null ? mb_substr($firstError, 0, 500) : null,
        ]);

        return $sent;
    }

    /**
     * Send a push notification to a Firebase TOPIC (broadcast to every
     * device subscribed to that topic). Used for system-wide announcements.
     *
     * The frontend subscribes each user's device to the topic "all" on
     * token registration (see fcm.js → _registerToken), so sending to
     * "all" reaches every user.
     *
     * @param  string  $topic   The FCM topic name (e.g. "all", "admins")
     * @param  array   $payload {title, body, data?, url?}
     * @param  ?string $sentBy  Admin user id (for the audit log)
     * @return bool True on success
     */
    public static function sendToTopic(string $topic, array $payload, ?string $sentBy = null): bool
    {
        if (! SettingsService::bool('fcmEnabled')) {
            return false;
        }

        if (SettingsService::bool('fcmTestMode')) {
            Log::info('FirebasePushService (test mode): would send topic push', [
                'topic' => $topic,
                'payload' => $payload,
            ]);
            self::logPush([
                'channel' => 'topic', 'target' => $topic,
                'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'sent', 'sent_count' => 0, 'failed_count' => 0,
                'sent_by' => $sentBy,
            ]);

            return true;
        }

        $messaging = self::messaging();
        if ($messaging === null) {
            self::logPush([
                'channel' => 'topic', 'target' => $topic,
                'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'failed', 'sent_count' => 0, 'failed_count' => 1,
                'error' => 'Messaging client unavailable (missing/malformed fcmServiceAccount or fcmProjectId)',
                'sent_by' => $sentBy,
            ]);

            return false;
        }

        $body = self::buildMessage($payload);
        $body['topic'] = $topic;

        try {
            $messaging->send($body);
            self::logPush([
                'channel' => 'topic', 'target' => $topic,
                'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'sent', 'sent_count' => 1, 'failed_count' => 0,
                'sent_by' => $sentBy,
            ]);

            return true;
        } catch (\Throwable $e) {
            self::logPush([
                'channel' => 'topic', 'target' => $topic,
                'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? '',
                'data_json' => json_encode($payload['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'failed', 'sent_count' => 0, 'failed_count' => 1,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'sent_by' => $sentBy,
            ]);

            return false;
        }
    }

    /**
     * Send a manual push to a specific user. Called by the admin "ارسال
     * پوش دستی" panel. Returns the send count.
     */
    public static function sendManual(string $userId, string $title, string $body, ?string $sentBy = null): int
    {
        $sent = self::sendToUser($userId, [
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'manual', 'sent_by' => $sentBy ?? 'admin'],
            'url' => '/',
        ]);

        // Tag the most recent log row with the admin's id so the audit
        // trail shows who sent the manual push.
        if ($sentBy) {
            $latest = FcmPushLog::where('channel', 'user')
                ->where('target', $userId)
                ->orderByDesc('created_at')
                ->first();
            if ($latest) {
                $latest->sent_by = $sentBy;
                $latest->save();
            }
        }

        return $sent;
    }

    /**
     * Return the most recent N push log rows for the admin panel.
     */
    public static function recentLogs(int $limit = 50): array
    {
        return FcmPushLog::orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'channel' => $r->channel,
                'target' => $r->target,
                'title' => $r->title,
                'body' => $r->body,
                'status' => $r->status,
                'sent_count' => $r->sent_count,
                'failed_count' => $r->failed_count,
                'error' => $r->error,
                'sent_by' => $r->sent_by,
                'created_at' => $r->created_at,
            ])
            ->all();
    }

    /**
     * Insert a row into fcm_push_logs. Internal helper.
     */
    private static function logPush(array $attrs): void
    {
        try {
            FcmPushLog::create(array_merge($attrs, [
                'id' => 'fcmpush-'.\Illuminate\Support\Str::uuid(),
                'created_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('FirebasePushService: failed to write push log', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a push notification to a single FCM token. Returns true on
     * success, false on failure (permanent OR transient — callers that
     * need to distinguish them should use sendToUser / the typed SDK API).
     */
    public static function sendToToken(string $fcmToken, array $payload): bool
    {
        $messaging = self::messaging();
        if ($messaging === null) {
            Log::warning('FirebasePushService: Messaging client unavailable — skipping send.');

            return false;
        }

        $body = self::buildMessage($payload);
        $body['token'] = $fcmToken;

        try {
            $messaging->send($body);

            return true;
        } catch (\Throwable $e) {
            Log::error('FirebasePushService: send failed', [
                'token' => substr($fcmToken, 0, 20).'…',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    /**
     * Register (upsert) a device token for a user. If the token already
     * exists (same browser visiting again), it's reactivated + its
     * user_agent/platform fields are refreshed. Reactivation matters:
     * a token that FCM once reported unregistered can come back (the
     * browser mints a fresh one) — the old row must not stay inactive.
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

    /**
     * Subscribe an FCM token to a topic (server-side). Called
     * automatically when a token is registered so the admin can broadcast
     * to the "all" topic without per-device work.
     *
     * Uses the SDK (which currently talks to the legacy Instance ID API —
     * deprecated by Google but supported until 2027-09-29; the SDK will
     * migrate it, we pick that up with a composer update).
     */
    public static function subscribeToTopic(string $fcmToken, string $topic = 'all'): bool
    {
        if (! SettingsService::bool('fcmEnabled')) {
            return false;
        }

        $messaging = self::messaging();
        if ($messaging === null) {
            return false;
        }

        try {
            // Kreait returns ['<topic>' => ['<token>' => 'OK'|<error-string>]]
            // — and the IID API reports PER-TOKEN failures inside a 200
            // response, so the wrapper must inspect the per-token outcome
            // instead of relying on exceptions alone.
            $result = $messaging->subscribeToTopic($topic, $fcmToken);
            $status = $result[$topic] ?? null;
            if (is_array($status)) {
                $status = $status[$fcmToken] ?? reset($status);
            }
            if ($status === 'OK') {
                return true;
            }
            Log::warning('FirebasePushService: topic subscribe rejected by FCM', [
                'topic' => $topic,
                'token' => substr($fcmToken, 0, 20).'…',
                'error' => is_string($status) ? $status : 'unknown',
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('FirebasePushService: topic subscribe failed', [
                'topic' => $topic,
                'token' => substr($fcmToken, 0, 20).'…',
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return false;
        }
    }

    /**
     * Step-by-step health report for the admin "push diagnostics" panel:
     * settings presence, service-account validity, SDK client creation,
     * and registered-token stats. Never sends anything.
     */
    public static function selfCheck(): array
    {
        $steps = [];

        $enabled = SettingsService::bool('fcmEnabled');
        $steps[] = [
            'key' => 'enabled',
            'label' => 'پوش نوتیفیکیشن فعال است؟',
            'ok' => $enabled,
            'detail' => $enabled ? 'فعال' : 'در تنظیمات سیستم خاموش است (fcmEnabled).',
        ];

        $testMode = SettingsService::bool('fcmTestMode');
        $steps[] = [
            'key' => 'testMode',
            'label' => 'حالت آزمایشی خاموش است؟',
            'ok' => ! $testMode,
            'detail' => $testMode
                ? 'fcmTestMode روشن است — پیام‌ها فقط لاگ می‌شوند و به دستگاه‌ها ارسال نمی‌شوند!'
                : 'خاموش — ارسال واقعی انجام می‌شود.',
        ];

        $projectId = trim((string) SettingsService::get('fcmProjectId', ''));
        $steps[] = [
            'key' => 'projectId',
            'label' => 'Project ID تنظیم شده؟',
            'ok' => $projectId !== '',
            'detail' => $projectId !== '' ? $projectId : 'فقدان fcmProjectId — ارسال ممکن نیست.',
        ];

        $saRaw = (string) SettingsService::raw('fcmServiceAccount');
        $sa = $saRaw !== '' ? json_decode($saRaw, true) : null;
        $saValid = is_array($sa)
            && ! empty($sa['client_email'])
            && ! empty($sa['private_key'])
            && str_contains((string) $sa['private_key'], 'PRIVATE KEY');
        $steps[] = [
            'key' => 'serviceAccount',
            'label' => 'حساب سرویس (Service Account) معتبر است؟',
            'ok' => $saValid,
            'detail' => $saValid
                ? 'معتبر ('.($sa['client_email'] ?? '').')'
                : ($saRaw === '' ? 'fcmServiceAccount خالی است — از Firebase Console کلید بسازید و در تنظیمات بچسبانید.' : 'JSON نامعتبر یا فاقد client_email/private_key است.'),
        ];

        $clientCfg = [];
        foreach (['fcmApiKey', 'fcmVapidKey', 'fcmMessagingSenderId', 'fcmAppId'] as $k) {
            $clientCfg[$k] = trim((string) SettingsService::get($k, '')) !== '';
        }
        $clientOk = $clientCfg['fcmApiKey'] && $clientCfg['fcmVapidKey'] && $clientCfg['fcmAppId'];
        $steps[] = [
            'key' => 'clientConfig',
            'label' => 'تنظیمات سمت مرورگر (apiKey/appId/vapidKey) کامل است؟',
            'ok' => $clientOk,
            'detail' => $clientOk
                ? 'کامل — مرورگر می‌تواند توکن دستگاه بسازد.'
                : 'ناقص: '.json_encode($clientCfg).' — بدون apiKey/appId/vapidKey مرورگر توکن نمی‌گیرد.',
        ];

        $messagingOk = false;
        $messagingError = null;
        if ($enabled && $saValid) {
            try {
                $messagingOk = self::messaging() instanceof Messaging;
            } catch (\Throwable $e) {
                $messagingError = $e->getMessage();
            }
        }
        $steps[] = [
            'key' => 'messaging',
            'label' => 'ساخت کلاینت FCM (OAuth با حساب سرویس) موفق است؟',
            'ok' => $messagingOk,
            'detail' => $messagingOk
                ? 'کلاینت آماده است.'
                : ($messagingError ?? 'به دلیل موارد بالا اجرا نشد.'),
        ];

        $steps[] = [
            'key' => 'tokens',
            'label' => 'توکن‌های ثبت‌شده دستگاه‌ها',
            'ok' => FcmToken::where('is_active', true)->exists(),
            'detail' => FcmToken::where('is_active', true)->count().' فعال / '
                .FcmToken::where('is_active', false)->count().' غیرفعال — بدون توکن فعال، ارسالی وجود ندارد.',
        ];

        return $steps;
    }

    // ─── Internals ────────────────────────────────────────────────────

    /**
     * Build the FCM v1 message body (without a target — the SDK injects
     * token/topic per request). All data values must be strings.
     *
     * ⚠ An EMPTY data array must be OMITTED entirely: json_encode([]) is
     * a JSON LIST, but FCM v1 requires "data" to be a MAP — an empty []
     * makes Google reject the whole message with 400
     * "Cannot bind a list to map for field 'data'". (Another hidden
     * contributor to the "notifications stopped arriving" bug.)
     */
    private static function buildMessage(array $payload): array
    {
        $message = [
            'notification' => [
                'title' => (string) ($payload['title'] ?? ''),
                'body' => (string) ($payload['body'] ?? ''),
            ],
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
        ];

        $data = self::normalizeData($payload['data'] ?? [], $payload['url'] ?? null);
        if ($data !== []) {
            $message['data'] = $data;
        }

        return $message;
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
