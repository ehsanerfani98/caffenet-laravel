<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Firebase Cloud Messaging — token registration + public config.
 *
 *  - GET  /api/fcm/config   → public Firebase web config (so the SPA can
 *                              initialize firebase-messaging without hard-
 *                              coding the project ID / API key / etc.)
 *  - POST /api/fcm/token    → register the device token returned by FCM
 *                              to the current user (upsert)
 *  - DELETE /api/fcm/token  → deactivate all tokens for the current user
 *                              (called on logout)
 *  - POST /api/fcm/test     → admin-only: send a test push to the current
 *                              user's registered tokens
 */
class FcmController extends Controller
{
    /**
     * Returns the public Firebase web SDK configuration so the SPA can
     * initialize firebase-messaging. The service-account JSON (the secret)
     * is NEVER exposed here — only the public web config values.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'enabled' => SettingsService::bool('fcmEnabled'),
            'projectId' => (string) SettingsService::get('fcmProjectId', ''),
            'apiKey' => (string) SettingsService::get('fcmApiKey', ''),
            'authDomain' => (string) SettingsService::get('fcmAuthDomain', ''),
            'messagingSenderId' => (string) SettingsService::get('fcmMessagingSenderId', ''),
            'appId' => (string) SettingsService::get('fcmAppId', ''),
            'vapidKey' => (string) SettingsService::get('fcmVapidKey', ''),
        ]);
    }

    /**
     * Register the device token returned by Firebase Messaging to the
     * current user. Idempotent — if the same token already exists, it's
     * reactivated and the user_agent is refreshed.
     */
    public function registerToken(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! $user) {
            return response()->json(['error' => 'ورود لازم است.'], 401);
        }

        $token = trim((string) $request->json('token', ''));
        if ($token === '' || strlen($token) < 32) {
            return response()->json(['error' => 'توکن نامعتبر است.'], 400);
        }

        $platform = (string) $request->json('platform', 'web');
        $userAgent = substr((string) $request->header('User-Agent'), 0, 500);

        FirebasePushService::registerToken($user->id, $token, $userAgent, $platform);

        // Subscribe the token to the "all" broadcast topic (best-effort;
        // failure here doesn't fail the registration — per-user pushes
        // still work).
        FirebasePushService::subscribeToTopic($token, 'all');

        return response()->json(['ok' => true, 'message' => 'توکن پوش ثبت شد.']);
    }

    /**
     * Deactivate all tokens for the current user (called on logout).
     */
    public function unregister(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! $user) {
            return response()->json(['error' => 'ورود لازم است.'], 401);
        }

        FirebasePushService::deactivateUserTokens($user->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Admin-only: send a test push notification to the current user's
     * registered tokens. Used by the "تست ارسال پوش" button in the
     * system settings panel.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! $user || ! in_array($user->role, ['admin'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        if (! SettingsService::bool('fcmEnabled')) {
            return response()->json(['error' => 'پوش نوتیفیکیشن فعال نیست. ابتدا آن را روشن کنید.'], 400);
        }

        $sent = FirebasePushService::sendToUser($user->id, [
            'title' => 'تست پوش نوتیفیکیشن',
            'body' => 'اگر این پیام را می‌بینید، پوش نوتیفیکیشن درست کار می‌کند. ✅',
            'data' => ['type' => 'test', 'sent_at' => now()->toIso8601String()],
            'url' => '/',
        ]);

        if ($sent === 0) {
            return response()->json([
                'error' => 'هیچ توکن فعالی برای کاربر شما ثبت نشده. ابتدا یک تب باز بگذارید تا توکن ثبت شود.',
            ], 400);
        }

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'message' => "پوش به {$sent} دستگاه ارسال شد.",
        ]);
    }

    /**
     * Admin-only: send a manual push to a specific user by id or phone.
     * Body: {userId?, phone?, title, body}
     */
    public function sendToUser(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('authUser');
        if (! $admin || ! in_array($admin->role, ['admin'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }
        if (! SettingsService::bool('fcmEnabled')) {
            return response()->json(['error' => 'پوش نوتیفیکیشن فعال نیست.'], 400);
        }

        $title = trim((string) $request->json('title', ''));
        $body = trim((string) $request->json('body', ''));
        $userId = trim((string) $request->json('userId', ''));
        $phone = trim((string) $request->json('phone', ''));

        if ($title === '' || $body === '') {
            return response()->json(['error' => 'عنوان و متن الزامی است.'], 400);
        }
        if ($userId === '' && $phone === '') {
            return response()->json(['error' => 'یکی از userId یا phone الزامی است.'], 400);
        }

        // Resolve the target user.
        $target = null;
        if ($userId !== '') {
            $target = \App\Models\User::find($userId);
        } elseif ($phone !== '') {
            $target = \App\Models\User::where('phone', $phone)->first();
        }
        if (! $target) {
            return response()->json(['error' => 'کاربر یافت نشد.'], 404);
        }

        $sent = FirebasePushService::sendManual($target->id, $title, $body, $admin->id);

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            'message' => $sent > 0
                ? "پوش به {$sent} دستگاه کاربر ارسال شد."
                : 'کاربر هیچ توکن فعال ثبت‌شده‌ای ندارد (شاید هنوز وارد نشده یا اجازه نوتیفیکیشن نداده).',
        ]);
    }

    /**
     * Admin-only: broadcast a push to a topic (default: "all" — every
     * subscribed device). Body: {topic?, title, body}
     */
    public function sendToTopic(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('authUser');
        if (! $admin || ! in_array($admin->role, ['admin'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }
        if (! SettingsService::bool('fcmEnabled')) {
            return response()->json(['error' => 'پوش نوتیفیکیشن فعال نیست.'], 400);
        }

        $title = trim((string) $request->json('title', ''));
        $body = trim((string) $request->json('body', ''));
        $topic = trim((string) $request->json('topic', 'all'));
        if ($topic === '') {
            $topic = 'all';
        }
        if ($title === '' || $body === '') {
            return response()->json(['error' => 'عنوان و متن الزامی است.'], 400);
        }

        $ok = FirebasePushService::sendToTopic($topic, [
            'title' => $title,
            'body' => $body,
            'data' => ['type' => 'broadcast', 'topic' => $topic],
            'url' => '/',
        ], $admin->id);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'پوش همگانی به همه کاربران ارسال شد.'
                : 'ارسال ناموفق بود — تنظیمات Firebase را بررسی کنید.',
        ]);
    }

    /**
     * Admin-only: recent push log entries (audit trail).
     */
    public function logs(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! $user || ! in_array($user->role, ['admin'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min(200, $limit));

        return response()->json([
            'logs' => FirebasePushService::recentLogs($limit),
        ]);
    }
}
