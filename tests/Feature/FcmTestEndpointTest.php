<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
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
}
