<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BazaarPay wallet top-up (checkout) pipeline in offline test mode:
 *  - POST /api/payments/request {purpose:wallet, gateway:'bazaarpay'}
 *    → payment row (gateway=bazaarpay, authority=checkout token) + redirectUrl
 *  - GET /api/payments/verify?authority=…&Status=OK (test-gateway return)
 *    → trace → commit → wallet credited
 *  - Status=OK missing (cancelled) → payment failed, wallet untouched.
 */
class BazaarPayCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::set('bazaarPayEnabled', '1');
        SettingsService::set('bazaarPayToken', 'test-merchant-token');
        SettingsService::set('bazaarPayDestination', 'ehsancaffenet_bazaar');
        SettingsService::set('appUrl', 'https://caffenet.example.com');
        SettingsService::set('paymentTestMode', '1');
    }

    private function customer(): User
    {
        return User::create([
            'id' => 'usr-cust-co',
            'phone' => '09120000003',
            'name' => 'مشتری شارژ',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function test_wallet_topup_creates_payment_and_redirect_url(): void
    {
        $user = $this->customer();

        $resp = $this->actingAs($user)
            ->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => 50000, 'gateway' => 'bazaarpay'])
            ->assertStatus(200)
            ->assertJsonPath('gateway', 'bazaarpay');

        $this->assertStringStartsWith('/test-gateway?authority=', $resp->json('redirectUrl'));

        $payment = DB::table('payments')->where('id', $resp->json('paymentId'))->first();
        $this->assertSame('bazaarpay', $payment->gateway);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(50000, (int) $payment->amount);
        $this->assertNotNull($payment->authority);
    }

    public function test_verify_callback_credits_the_wallet(): void
    {
        $user = $this->customer();

        $resp = $this->actingAs($user)
            ->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => 50000, 'gateway' => 'bazaarpay'])
            ->assertStatus(200);

        $authority = $resp->json('authority');

        // The internal test gateway returns the user to /api/payments/verify.
        $this->get('/api/payments/verify?authority='.urlencode($authority).'&Status=OK')
            ->assertRedirect();

        $payment = DB::table('payments')->where('id', $resp->json('paymentId'))->first();
        $this->assertSame('paid', $payment->status);

        $this->assertSame(50000, (int) User::find($user->id)->wallet_balance);
    }

    public function test_cancelled_gateway_return_fails_the_payment(): void
    {
        $user = $this->customer();

        $resp = $this->actingAs($user)
            ->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => 30000, 'gateway' => 'bazaarpay'])
            ->assertStatus(200);

        $authority = $resp->json('authority');

        $this->get('/api/payments/verify?authority='.urlencode($authority).'&Status=NOK')
            ->assertRedirect();

        $payment = DB::table('payments')->where('id', $resp->json('paymentId'))->first();
        $this->assertSame('failed', $payment->status);
        $this->assertSame(0, (int) User::find($user->id)->wallet_balance);
    }

    public function test_disabled_gateway_rejects_topup(): void
    {
        SettingsService::set('bazaarPayEnabled', '0');

        $this->actingAs($this->customer())
            ->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => 50000, 'gateway' => 'bazaarpay'])
            ->assertStatus(400)
            ->assertJsonPath('error', 'درگاه پرداخت بازارپی فعال نیست.');
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [
            'bazaarPayEnabled', 'bazaarPayToken', 'bazaarPayDestination',
        ])->delete();
        parent::tearDown();
    }
}
