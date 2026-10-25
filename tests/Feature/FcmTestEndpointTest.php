<?php

namespace Tests\Feature;

use App\Models\FcmPushLog;
use App\Models\FcmToken;
use App\Models\User;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

/**
 * The admin «تست ارسال پوش» endpoint (and the manual send-user push) used
 * to answer EVERY zero-send with «هیچ توکن فعالی برای کاربر شما ثبت نشده» —
 * even when an active token existed and the real cause was a broken FCM
 * config or Google rejecting the send. That misleading diagnosis is
 * user-reported ("پوش ارسال نمی‌شه، می‌گه توکنی ثبت نشده!") and cost a
 * full false trail. Each zero-path must now be reported TRUTHFULLY:
 *  1. no token rows at all → register-first message;
 *  2. only INACTIVE rows → "token deactivated, re-enable notifications";
 *  3. active token + messaging client unavailable → config-gap message
 *     (read from the last fcm_push_log row);
 *  4. active token + Google rejected the send → Google's own error text.
 */
class FcmTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FirebasePushService::setMessagingOverride(null);
        parent::tearDown();
        Mockery::close();
    }

    private function enableFcm(): void
    {
        SettingsService::set('fcmEnabled', '1');
        SettingsService::set('fcmTestMode', '0');
        SettingsService::set('fcmProjectId', 'caffenet-test');
    }

    private function admin(): User
    {
        static $admin = null;
        if ($admin) {
            return $admin;
        }

        $admin = User::create([
            'id' => 'usr-fcmtest-admin',
            'phone' => '09120000011',
            'name' => 'مدیر پوش',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeToken(bool $active): void
    {
        FcmToken::create([
            'user_id' => $this->admin()->id,
            'token' => 'tok-fcmtest-'.($active ? 'active' : 'dead'),
            'platform' => 'web',
            'user_agent' => 'TestBrowser',
            'is_active' => $active,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function test_no_tokens_at_all_reports_register_first(): void
    {
        $this->enableFcm();

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) => str_contains($e, 'هیچ توکن فعالی'));
    }

    public function test_only_inactive_tokens_report_reenable_hint(): void
    {
        $this->enableFcm();
        $this->makeToken(false);

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) => str_contains($e, 'غیرفعال شده'));
    }

    /**
     * THE user-reported regression: an ACTIVE token with a broken server-
     * side config (no service account → messaging() null → sendToUser
     * returns 0) must NOT be diagnosed as «توکنی ثبت نشده».
     */
    public function test_active_token_with_missing_config_reports_config_gap(): void
    {
        $this->enableFcm();
        $this->makeToken(true);
        // Intentionally NO fcmServiceAccount → messaging() returns null.

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) => str_contains($e, 'کانفیگ FCM روی سرور ناقص است'));
        // The audit trail must still carry the raw cause.
        $this->assertDatabaseHas('fcm_push_logs', ['status' => 'failed']);
    }

    public function test_active_token_with_google_rejection_surfaces_google_error(): void
    {
        $this->enableFcm();
        $this->makeToken(true);

        // Transient per-token failure → token stays active, log carries
        // Google's message, and the endpoint must quote it.
        $m = Mockery::mock(\Kreait\Firebase\Contract\Messaging::class);
        $m->shouldReceive('sendMulticast')->once()->andReturnUsing(
            fn ($message, $tokens) => MulticastSendReport::withItems([
                SendReport::failure(
                    MessageTarget::with('token', 'tok-fcmtest-active'),
                    new ServerUnavailable('502 upstream')
                ),
            ])
        );
        FirebasePushService::setMessagingOverride($m);

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) => str_contains($e, 'گوگل ارسال را نپذیرفت') && str_contains($e, '502 upstream'));
        $this->assertTrue((bool) FcmToken::where('token', 'tok-fcmtest-active')->value('is_active'), 'transient failure must not deactivate the token');
    }

    /**
     * THE user-reported production failure: Google's OAuth2 endpoint
     * rejects the service account with 400 invalid_grant. Kreait throws
     * InvalidMessage whose getMessage() is JUST "invalid_grant" — the real
     * reason (error_description) only survives in the Guzzle ClientException
     * attached as previous. The endpoint must surface BOTH the full reason
     * AND an actionable Persian fix hint.
     */
    public function test_google_oauth_rejection_exposes_real_cause_and_hint(): void
    {
        $this->enableFcm();
        $this->makeToken(true);

        $body = '{"error":"invalid_grant","error_description":"Invalid grant: account not found"}';
        $guzzle = new ClientException(
            'Client error: `POST https://oauth2.googleapis.com/token` resulted in a `400 Bad Request` response: '.$body,
            new Request('POST', 'https://oauth2.googleapis.com/token'),
            new Response(400, ['Content-Type' => 'application/json'], $body)
        );
        $kreait = new InvalidMessage('invalid_grant', 0, $guzzle);

        $m = Mockery::mock(\Kreait\Firebase\Contract\Messaging::class);
        $m->shouldReceive('sendMulticast')->once()->andThrow($kreait);
        FirebasePushService::setMessagingOverride($m);

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) =>
            str_contains($e, 'گوگل ارسال را نپذیرفت')
            && str_contains($e, 'Invalid grant: account not found')
            && str_contains($e, 'حساب سرویس'));

        // The log row must carry the FULL description for future diagnosis
        // — not Guzzle's 120-char body summary, not the bare code.
        $log = FcmPushLog::orderByDesc('id')->first();
        $this->assertStringContainsString('account not found', (string) $log->error);
        $this->assertStringContainsString('[HTTP 400]', (string) $log->error);
    }

    /**
     * Old log rows (like the ones already sitting in the user's production
     * DB) only contain the bare "invalid_grant" code. Even those must get
     * a general-but-actionable hint instead of a dead end.
     */
    public function test_bare_invalid_grant_from_old_log_rows_still_gets_hint(): void
    {
        $this->enableFcm();
        $this->makeToken(true);

        FcmPushLog::create([
            'id' => 'fcmpush-old-grant',
            'channel' => 'user',
            'target' => $this->admin()->id,
            'title' => 'تست',
            'body' => 'تست',
            'status' => 'failed',
            'sent_count' => 0,
            'failed_count' => 1,
            'error' => 'invalid_grant',
            'created_at' => now()->toIso8601String(),
        ]);

        $resp = $this->actingAs($this->admin())->postJson('/api/fcm/test');

        $resp->assertStatus(400);
        $resp->assertJsonPath('error', fn (string $e) =>
            str_contains($e, 'invalid_grant')
            && str_contains($e, 'کلید خصوصی تازه'));
    }
}
