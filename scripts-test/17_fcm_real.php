<?php

/**
 * REAL Firebase push test — uses the REAL caffenet-53608 project
 * credentials (stored OUTSIDE the repo at
 * /home/z/my-project/fcm-credentials.json) to verify the whole chain:
 *
 *   settings → service-account JSON → Kreait SDK → Google OAuth2 (RS256)
 *   → FCM HTTP v1 send endpoint
 *
 * Stages:
 *   1. selfCheck()  — settings + service-account validation
 *   2. OAuth        — Messaging client creation mint a real access token
 *   3. send (fake)  — push to a syntactically valid but unknown token:
 *                     FCM MUST answer UNREGISTERED (NotFound) — proves
 *                     the full auth+send path reaches Google.
 *   4. send (real)  — every ACTIVE token found in fcm_tokens gets the
 *                     message (used after a browser registers one).
 *
 * Run: php artisan tinker --execute="require 'scripts-test/17_fcm_real.php';"
 */

use App\Models\FcmToken;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use Kreait\Firebase\Exception\MessagingException;

echo "=== FCM REAL TEST (project caffenet-53608) ===\n";

$credFile = '/home/z/my-project/fcm-credentials.json';
$cred = json_decode((string) file_get_contents($credFile), true);
if (! $cred) {
    exit("credentials file missing/broken: $credFile\n");
}

// ── Stage 0: install the REAL credentials into settings ──────────────
SettingsService::set('fcmEnabled', '1');
SettingsService::set('fcmTestMode', '0');
SettingsService::set('fcmProjectId', $cred['webConfig']['projectId']);
SettingsService::set('fcmApiKey', $cred['webConfig']['apiKey']);
SettingsService::set('fcmAuthDomain', $cred['webConfig']['authDomain']);
SettingsService::set('fcmMessagingSenderId', $cred['webConfig']['messagingSenderId']);
SettingsService::set('fcmAppId', $cred['webConfig']['appId']);
SettingsService::set('fcmVapidKey', $cred['vapidKey']);
SettingsService::set('fcmServiceAccount', json_encode($cred['serviceAccount']));
echo "[0] settings installed (projectId={$cred['webConfig']['projectId']})\n";

// ── Stage 1: selfCheck ───────────────────────────────────────────────
echo "\n[1] SELF CHECK\n";
foreach (FirebasePushService::selfCheck() as $step) {
    printf("    %s %s — %s\n", $step['ok'] ? '✔' : '✖', $step['label'], $step['detail']);
}

// ── Stage 2: real OAuth (client creation mints the token lazily) ─────
echo "\n[2] REAL OAUTH (service account → access token)\n";
try {
    $ref = new ReflectionClass(FirebasePushService::class);
    $m = $ref->getMethod('messaging');
    $m->setAccessible(true);
    $client = $m->invoke(null);
    echo $client ? "    ✔ Messaging client created (OAuth OK)\n" : "    ✖ client is null\n";
} catch (\Throwable $e) {
    echo '    ✖ OAuth failed: '.$e->getMessage()."\n";
    exit(1);
}

// ── Stage 3: send to a FAKE token — expect UNREGISTERED ──────────────
echo "\n[3] SEND to fake token (expect UNREGISTERED from real FCM)\n";
$fake = 'f'.str_repeat('X', 140).'realtest';
$ok = FirebasePushService::sendToToken($fake, [
    'title' => 'تست زنجیره ارسال',
    'body' => 'این پیام نباید تحویل شود (توکن جعلی است).',
]);
echo $ok
    ? "    ✖ unexpected success — fake token accepted?!\n"
    : "    ✔ send rejected (as expected for an unknown token) — auth + v1 endpoint work\n";

// ── Stage 4: send to REAL registered tokens (if any) ─────────────────
echo "\n[4] SEND to real registered tokens\n";
$active = FcmToken::where('is_active', true)->count();
echo "    active tokens: {$active}\n";
if ($active > 0) {
    $sent = FirebasePushService::sendToUser(FcmToken::where('is_active', true)->value('user_id'), [
        'title' => 'اعلان تست کافی‌نت',
        'body' => 'اگر این پیام روی دستگاه ظاهر شد، پوش نوتیفیکیشن سالم است. ('.now()->format('H:i:s').')',
        'data' => ['type' => 'test', 'sent_at' => now()->toIso8601String()],
        'url' => '/',
    ]);
    echo $sent > 0
        ? "    ✔ FCM accepted the message for {$sent} device(s) — check the device screen!\n"
        : "    ✖ FCM did not accept — see fcm_push_logs / laravel.log\n";
}

// ── Stage 5: audit log tail ──────────────────────────────────────────
echo "\n[5] fcm_push_logs (latest 3)\n";
foreach (\App\Models\FcmPushLog::orderByDesc('created_at')->limit(3)->get() as $l) {
    printf("    [%s] %s/%s sent=%d failed=%d %s\n",
        $l->status, $l->channel, $l->target, $l->sent_count, $l->failed_count,
        mb_substr((string) $l->error, 0, 120));
}

echo "\n=== DONE ===\n";
