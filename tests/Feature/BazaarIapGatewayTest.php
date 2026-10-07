<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Caffenet\BazaarIap\BazaarIap;
use Caffenet\BazaarIap\Events\PurchaseConsumed;
use Caffenet\BazaarIap\Models\BazaarIapTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exercises the Cafe Bazaar in-app gateway: deep-link building, the
 * server-side consume call, and callback idempotency + event dispatch.
 */
class BazaarIapGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const CONSUME_BASE = 'https://pardakht.cafebazaar.ir/devapi/v2/api/consume';

    private const VALIDATE_BASE = 'https://pardakht.cafebazaar.ir/devapi/v2/api/validate';

    protected function setUp(): void
    {
        parent::setUp();

        // Credentials live in the database-backed settings panel.
        SettingsService::set('bazaarEnabled', '1');
        SettingsService::set('bazaarTestMode', '0');
        SettingsService::set('bazaarPackageName', 'ir.example.app');
        SettingsService::set('bazaarApiSecret', 'SECRET123');
        SettingsService::set('bazaarSku', 'sku-1');
        SettingsService::set('bazaarDealerPackageName', 'ir.example.app');
        SettingsService::set('bazaarConsumeUrl', self::CONSUME_BASE);
        SettingsService::set('bazaarValidateUrl', self::VALIDATE_BASE);
    }

    public function test_payment_deep_link_carries_sku_dealer_and_redirect(): void
    {
        $link = app(BazaarIap::class)->paymentDeepLink(
            'https://app.test/bazaar-iap/callback?visit=v1',
            'sku-1'
        );

        $this->assertStringStartsWith('bazaar://in_app?', $link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame('sku-1', $query['sku']);
        $this->assertSame('ir.example.app', $query['dealerPackageName']);
        $this->assertSame('https://app.test/bazaar-iap/callback?visit=v1', $query['redirectUrl']);
    }

    public function test_login_deep_link_carries_package_and_scope(): void
    {
        $link = app(BazaarIap::class)->loginDeepLink('https://app.test/bazaar-iap/callback?status=login', 'sku-1');

        $this->assertStringStartsWith('bazaar://inapplogin?', $link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame('ir.example.app', $query['packageName']);
        $this->assertSame('1', (string) $query['permissionScope']);
    }

    public function test_consume_posts_token_with_secret_header(): void
    {
        Http::fake(['pardakht.cafebazaar.ir/*' => Http::response(['status' => 'ok'], 200)]);

        $result = app(BazaarIap::class)->consume('token-abc');

        $this->assertTrue($result->ok);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/ir.example.app/purchases/')
                && $request->hasHeader('CAFEBAZAAR-PISHKHAN-API-SECRET', 'SECRET123')
                && $request['token'] === 'token-abc';
        });
    }

    public function test_callback_consumes_once_and_dispatches_event(): void
    {
        Event::fake([PurchaseConsumed::class]);
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $transaction = BazaarIapTransaction::create([
            'package_name' => 'ir.example.app',
            'sku' => 'sku-1',
            'status' => BazaarIapTransaction::STATUS_PENDING,
            'meta' => ['return' => '/'],
        ]);

        $url = '/bazaar-iap/callback?visit='.$transaction->visit_id
            .'&status=payment&sku=sku-1&purchaseToken=tok-1&response=ok&return=/';

        $this->get($url)->assertRedirect();
        Event::assertDispatched(PurchaseConsumed::class);
        $this->assertSame(BazaarIapTransaction::STATUS_CONSUMED, $transaction->fresh()->status);

        // A duplicate callback (Bazaar retry / user refresh) must not consume again.
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->get($url)->assertRedirect();
        Http::assertSentCount(0);
    }

    public function test_start_returns_503_when_gateway_disabled(): void
    {
        SettingsService::set('bazaarEnabled', '0');

        $this->postJson('/bazaar-iap/start', [])->assertStatus(503);
    }

    public function test_start_creates_a_visit_and_returns_deep_link(): void
    {
        $response = $this->postJson('/bazaar-iap/start', [
            'sku' => 'sku-1',
            'amount' => 50000,
            'purpose' => 'wallet',
        ]);

        $response->assertOk()
            ->assertJsonPath('gateway', 'bazaar')
            ->assertJsonStructure(['visitId', 'deepLink', 'redirectUrl']);

        $this->assertDatabaseHas('bazaar_iap_transactions', [
            'sku' => 'sku-1',
            'purpose' => 'wallet',
            'amount' => 50000,
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'phone' => '09120000009',
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function test_wallet_topup_uses_the_sku_mapped_to_the_amount(): void
    {
        SettingsService::set('walletTopUpPresets', json_encode([
            ['amount' => 50000, 'sku' => 'topup_50k'],
            ['amount' => 100000, 'sku' => 'topup_100k'],
        ]));

        $response = $this->actingAs($this->customer())
            ->postJson('/api/payments/request', [
                'purpose' => 'wallet',
                'amount' => 100000,
                'gateway' => 'bazaar',
            ]);

        $response->assertOk()
            ->assertJsonPath('gateway', 'bazaar')
            ->assertJsonPath('redirectUrl', fn ($v) => str_contains((string) $v, 'sku=topup_100k'));

        $this->assertDatabaseHas('payments', [
            'purpose' => 'wallet',
            'gateway' => 'bazaar',
            'amount' => 100000,
        ]);
    }

    public function test_wallet_topup_rejects_an_amount_without_a_preset(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/payments/request', [
                'purpose' => 'wallet',
                'amount' => 123456,
                'gateway' => 'bazaar',
            ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'BAZAAR_FIXED_AMOUNT');

        $this->assertDatabaseCount('payments', 0);
    }
}
