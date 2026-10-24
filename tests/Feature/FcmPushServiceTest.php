<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\FcmPushLog;
use App\Services\FirebasePushService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\SenderIdMismatch;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

/**
 * Behaviour tests for the rewritten FirebasePushService (kreait/firebase-php
 * SDK 8.x, FCM HTTP v1 API).
 *
 * The critical regression pinned here: a TRANSIENT delivery failure
 * (ServerUnavailable / thrown batch exception / quota) must NEVER
 * deactivate a device token — only permanent errors (NotFound =
 * UNREGISTERED, SenderIdMismatch) retire a token. The previous
 * implementation deactivated on ANY failure, so one temporary outage
 * killed every registered device and push notifications silently
 * stopped being delivered ("notifications stopped arriving" bug).
 */
class FcmPushServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FirebasePushService::setMessagingOverride(null);
        parent::tearDown();
        Mockery::close();
    }

    protected function enableFcm(array $overrides = []): void
    {
        SettingsService::set('fcmEnabled', $overrides['fcmEnabled'] ?? '1');
        SettingsService::set('fcmTestMode', $overrides['fcmTestMode'] ?? '0');
        SettingsService::set('fcmProjectId', $overrides['fcmProjectId'] ?? 'caffenet-test');
        if (! ($overrides['skipServiceAccount'] ?? false)) {
            SettingsService::set('fcmServiceAccount', json_encode([
                'client_email' => 'push@caffenet-test.iam.gserviceaccount.com',
                'private_key' => '-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----',
            ]));
        }
    }

    private function mockMessaging(): \Mockery\MockInterface
    {
        $m = Mockery::mock(Messaging::class);
        FirebasePushService::setMessagingOverride($m);

        return $m;
    }

    private function makeTokens(array $values): array
    {
        foreach ($values as $v) {
            FcmToken::create([
                'user_id' => 'usr-test',
                'token' => $v,
                'platform' => 'web',
                'is_active' => true,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        return $values;
    }

    private function payload(): array
    {
        return [
            'title' => 'سفارش شما شروع شد',
            'body' => 'کار روی سفارش CN-1 آغاز شد.',
            'data' => ['order_id' => 'ord-1', 'type' => 'order_started'],
            'url' => '/?order=1',
        ];
    }

    private function successReport(string $token): SendReport
    {
        return SendReport::success(MessageTarget::with('token', $token), [
            'name' => 'projects/caffenet-test/messages/1',
        ]);
    }

    // ─── Gates ────────────────────────────────────────────────────────

    public function test_disabled_fcm_sends_nothing_and_needs_no_client(): void
    {
        $this->enableFcm(['fcmEnabled' => '0']);
        // No override bound — if the service tried to build a real client
        // or send anything, this test would explode (no tokens either way).
        $this->assertSame(0, FirebasePushService::sendToUser('usr-x', $this->payload()));
    }

    public function test_no_active_tokens_returns_zero_without_messaging(): void
    {
        $this->enableFcm();
        $this->assertSame(0, FirebasePushService::sendToUser('usr-none', $this->payload()));
        $this->assertSame(0, FcmPushLog::count());
    }

    public function test_test_mode_logs_without_touching_messaging(): void
    {
        $this->enableFcm(['fcmTestMode' => '1']);
        $this->makeTokens(['tok-a', 'tok-b']);

        $this->assertSame(2, FirebasePushService::sendToUser('usr-test', $this->payload()));

        $row = FcmPushLog::where('channel', 'user')->where('target', 'usr-test')->first();
        $this->assertNotNull($row);
        $this->assertSame('sent', $row->status);
        $this->assertSame(0, (int) $row->sent_count);
    }

    // ─── The regression: transient errors keep tokens alive ──────────

    public function test_only_permanent_errors_deactivate_tokens(): void
    {
        $this->enableFcm();
        [$ok, $dead, $transient] = $this->makeTokens(['tok-ok', 'tok-dead', 'tok-transient']);

        $m = $this->mockMessaging();
        $m->shouldReceive('sendMulticast')->once()->andReturnUsing(
            function ($message, $tokens) use ($ok, $dead, $transient) {
                $items = [
                    $this->successReport($ok),
                    SendReport::failure(MessageTarget::with('token', $dead), NotFound::becauseTokenNotFound($dead)),
                    // Simulates a temporary Google-side outage.
                    SendReport::failure(MessageTarget::with('token', $transient), new ServerUnavailable('502 upstream')),
                ];

                return MulticastSendReport::withItems($items);
            }
        );

        $sent = FirebasePushService::sendToUser('usr-test', $this->payload());
        $this->assertSame(1, $sent);

        // Permanent UNREGISTERED → retired.
        $this->assertFalse(FcmToken::where('token', $dead)->value('is_active'), 'UNREGISTERED token must be deactivated');
        // Transient outage → must STAY active (the fixed bug).
        $this->assertTrue(FcmToken::where('token', $transient)->value('is_active'), 'transient failure must NOT deactivate the token');
        $this->assertTrue((bool) FcmToken::where('token', $ok)->value('is_active'));

        $row = FcmPushLog::where('channel', 'user')->where('target', 'usr-test')->first();
        $this->assertSame('partial', $row->status);
        $this->assertSame(1, (int) $row->sent_count);
        $this->assertSame(2, (int) $row->failed_count);
        // firstError = the FIRST failure encountered (the UNREGISTERED one)
        $this->assertStringContainsString('tok-dead', (string) $row->error);
    }

    public function test_sender_id_mismatch_deactivates_token(): void
    {
        $this->enableFcm();
        [$wrong] = $this->makeTokens(['tok-wrong-project']);

        $m = $this->mockMessaging();
        $m->shouldReceive('sendMulticast')->once()->andReturnUsing(
            fn ($message, $tokens) => MulticastSendReport::withItems([
                SendReport::failure(
                    MessageTarget::with('token', $wrong),
                    new SenderIdMismatch('Sender ID mismatch')
                ),
            ])
        );

        $this->assertSame(0, FirebasePushService::sendToUser('usr-test', $this->payload()));
        $this->assertFalse((bool) FcmToken::where('token', $wrong)->value('is_active'));
        $this->assertSame('failed', FcmPushLog::where('target', 'usr-test')->value('status'));
    }

    public function test_thrown_batch_exception_keeps_all_tokens_active(): void
    {
        $this->enableFcm();
        [$a, $b] = $this->makeTokens(['tok-a', 'tok-b']);

        $m = $this->mockMessaging();
        $m->shouldReceive('sendMulticast')->once()->andThrow(new QuotaExceeded('quota exceeded for messages:send'));

        $this->assertSame(0, FirebasePushService::sendToUser('usr-test', $this->payload()));

        // BOTH tokens must survive a batch-level quota failure.
        $this->assertTrue((bool) FcmToken::where('token', $a)->value('is_active'));
        $this->assertTrue((bool) FcmToken::where('token', $b)->value('is_active'));

        $row = FcmPushLog::where('target', 'usr-test')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(2, (int) $row->failed_count);
    }

    // ─── Topics ───────────────────────────────────────────────────────

    public function test_topic_send_success_and_failure_paths(): void
    {
        $this->enableFcm();

        $m = $this->mockMessaging();
        $m->shouldReceive('send')->once()->with(Mockery::on(
            fn (array $body) => ($body['topic'] ?? '') === 'all'
                && ($body['notification']['title'] ?? '') !== ''
        ))->andReturn(['name' => 'projects/caffenet-test/messages/2']);
        $this->assertTrue(FirebasePushService::sendToTopic('all', $this->payload(), 'usr-admin'));
        $this->assertSame('sent', FcmPushLog::where('channel', 'topic')->where('target', 'all')->value('status'));

        // A second call that FCM rejects → false + failed audit row, but
        // NO exception escapes.
        $m->shouldReceive('send')->once()->andThrow(new ServerUnavailable('503'));
        $this->assertFalse(FirebasePushService::sendToTopic('all', $this->payload(), 'usr-admin'));
        $this->assertSame('failed', FcmPushLog::where('channel', 'topic')->where('target', 'all')->orderByDesc('created_at')->first()->status);
    }

    public function test_topic_send_disabled_or_test_mode(): void
    {
        $this->enableFcm(['fcmEnabled' => '0']);
        $this->assertFalse(FirebasePushService::sendToTopic('all', $this->payload()));

        $this->enableFcm(['fcmTestMode' => '1']);
        $this->assertTrue(FirebasePushService::sendToTopic('all', $this->payload()));
        $this->assertSame('sent', FcmPushLog::where('channel', 'topic')->value('status'));
    }

    public function test_subscribe_to_topic_uses_sdk_contract(): void
    {
        $this->enableFcm();

        $m = $this->mockMessaging();
        // Kreait's per-token result shape: OK → true.
        $m->shouldReceive('subscribeToTopic')->once()->with('all', 'tok-1')->andReturn([
            'all' => ['tok-1' => 'OK'],
        ]);
        $this->assertTrue(FirebasePushService::subscribeToTopic('tok-1', 'all'));

        // The IID API reports per-token failures INSIDE a 200 response —
        // the wrapper must surface them as false, not exceptions.
        $m->shouldReceive('subscribeToTopic')->once()->with('all', 'tok-bad')->andReturn([
            'all' => ['tok-bad' => 'INVALID_ARGUMENT'],
        ]);
        $this->assertFalse(FirebasePushService::subscribeToTopic('tok-bad', 'all'));

        $m->shouldReceive('subscribeToTopic')->once()->andThrow(new ServerUnavailable('503'));
        $this->assertFalse(FirebasePushService::subscribeToTopic('tok-2', 'all'));

        // Disabled → false without touching the SDK.
        SettingsService::set('fcmEnabled', '0');
        $this->assertFalse(FirebasePushService::subscribeToTopic('tok-3', 'all'));
    }

    // ─── Single-token helper ──────────────────────────────────────────

    public function test_send_to_token_success_and_failure(): void
    {
        $this->enableFcm();

        $m = $this->mockMessaging();
        $m->shouldReceive('send')->once()->with(Mockery::on(
            fn (array $body) => ($body['token'] ?? '') === 'tok-ok'
        ))->andReturn(['name' => 'projects/caffenet-test/messages/3']);
        $this->assertTrue(FirebasePushService::sendToToken('tok-ok', $this->payload()));

        $m->shouldReceive('send')->once()->andThrow(new NotFound('UNREGISTERED'));
        $this->assertFalse(FirebasePushService::sendToToken('tok-gone', $this->payload()));
    }

    // ─── Manual push keeps audit attribution ──────────────────────────

    public function test_manual_push_tags_audit_row_with_admin(): void
    {
        $this->enableFcm();
        $this->makeTokens(['tok-m']);

        $m = $this->mockMessaging();
        $m->shouldReceive('sendMulticast')->once()->andReturnUsing(
            fn ($message, $tokens) => MulticastSendReport::withItems([
                $this->successReport('tok-m'),
            ])
        );

        $this->assertSame(1, FirebasePushService::sendManual('usr-test', 'سلام', 'پیام دستی', 'usr-admin'));
        $this->assertSame('usr-admin', FcmPushLog::where('target', 'usr-test')->value('sent_by'));
    }

    /**
     * Regression: FCM v1 requires "data" to be a MAP. An empty PHP array
     * json-encodes to the LIST [] and Google rejects the whole message
     * with 400 "Cannot bind a list to map for field 'data'". A payload
     * without data/url must therefore produce a message WITHOUT a data
     * key; a payload WITH data must keep it as a string map.
     */
    public function test_empty_data_is_omitted_not_encoded_as_list(): void
    {
        $this->enableFcm();
        $this->makeTokens(['tok-x']);

        $captured = null;
        $m = $this->mockMessaging();
        $m->shouldReceive('sendMulticast')->twice()->andReturnUsing(
            function ($message, $tokens) use (&$captured) {
                $captured = $message;

                return MulticastSendReport::withItems([$this->successReport('tok-x')]);
            }
        );

        // No data, no url → no 'data' key at all.
        FirebasePushService::sendToUser('usr-test', ['title' => 't', 'body' => 'b']);
        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('data', $captured, 'empty data must be omitted — [] is a JSON list and FCM v1 rejects it');
        $this->assertSame('t', $captured['notification']['title']);

        // With data → map of strings, plus url injected.
        $captured = null;
        FirebasePushService::sendToUser('usr-test', [
            'title' => 't', 'body' => 'b',
            'data' => ['order_id' => 42], 'url' => '/?order=42',
        ]);
        $this->assertSame(['order_id' => '42', 'url' => '/?order=42'], $captured['data']);
    }

    // ─── Self-check diagnostics ───────────────────────────────────────

    public function test_self_check_lists_actionable_steps(): void
    {
        // Nothing configured → all steps report false but never throw.
        $steps = FirebasePushService::selfCheck();
        $byKey = collect($steps)->keyBy('key');

        $this->assertFalse($byKey['enabled']['ok']);
        $this->assertFalse($byKey['projectId']['ok']);
        $this->assertFalse($byKey['serviceAccount']['ok']);
        $this->assertArrayHasKey('tokens', $byKey);

        // Fully configured (client mocked) → flags flip green.
        $this->enableFcm();
        $this->mockMessaging();
        $steps = FirebasePushService::selfCheck();
        $byKey = collect($steps)->keyBy('key');

        $this->assertTrue($byKey['enabled']['ok']);
        $this->assertTrue($byKey['projectId']['ok']);
        $this->assertTrue($byKey['serviceAccount']['ok']);
        $this->assertTrue($byKey['messaging']['ok']);
    }

    public function test_register_token_reactivates_and_refreshes(): void
    {
        $this->enableFcm();
        $this->makeTokens(['tok-r']);
        FcmToken::where('token', 'tok-r')->update(['is_active' => false]);

        FirebasePushService::registerToken('usr-test', 'tok-r', 'UA', 'web');

        $row = FcmToken::where('token', 'tok-r')->first();
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame('UA', $row->user_agent);
    }

    /**
     * The same browser re-registering a NEW token (delivery worker changed,
     * FCM rotated the token) must have its previous token deactivated —
     * otherwise every push is delivered twice to that device, once per
     * still-active token. Tokens of OTHER browsers (different user_agent)
     * and OTHER users must stay untouched.
     */
    public function test_register_token_deactivates_previous_token_of_same_browser(): void
    {
        $this->enableFcm();

        // Old token of THIS browser (same user + same UA), still active.
        $this->makeTokens(['tok-old']);
        FcmToken::where('token', 'tok-old')->update(['user_agent' => 'Mozilla/5.0 SameBrowser']);

        // Token of the SAME USER but a DIFFERENT browser — must stay active.
        $this->makeTokens(['tok-other-browser']);
        FcmToken::where('token', 'tok-other-browser')->update(['user_agent' => 'Mozilla/5.0 OtherBrowser']);

        // Token of a DIFFERENT user with the SAME UA — must stay active.
        FcmToken::create([
            'user_id' => 'usr-someone-else',
            'token' => 'tok-other-user',
            'user_agent' => 'Mozilla/5.0 SameBrowser',
            'platform' => 'web',
            'is_active' => true,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        FirebasePushService::registerToken('usr-test', 'tok-new', 'Mozilla/5.0 SameBrowser', 'web');

        $this->assertFalse((bool) FcmToken::where('token', 'tok-old')->first()->is_active, 'same browser old token deactivated');
        $this->assertTrue((bool) FcmToken::where('token', 'tok-new')->first()->is_active, 'new token active');
        $this->assertTrue((bool) FcmToken::where('token', 'tok-other-browser')->first()->is_active, 'other browser of same user untouched');
        $this->assertTrue((bool) FcmToken::where('token', 'tok-other-user')->first()->is_active, 'other user with same UA untouched');
    }
}
