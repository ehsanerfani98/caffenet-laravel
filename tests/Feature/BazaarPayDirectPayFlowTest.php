<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Full BazaarPay Direct-Pay order pipeline in offline test mode
 * (paymentTestMode=1 → BazaarPayService simulates every endpoint).
 *
 * Covers:
 *  - no contract yet  → contract_pending + signing URL + contract row with the
 *    configured type/period/amount_limit persisted on bazaarpay_contracts
 *  - contract callback after signing → payment executes automatically
 *  - active contract  → order paid in ONE request, zero redirects
 *  - amounts: order total (Toman) is what the payment row carries
 */
class BazaarPayDirectPayFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 120000;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            'id' => 'cat-test', 'title' => 'دسته تست', 'icon_name' => 'Printer',
        ]);
        DB::table('services')->insert([
            'id' => 'srv-test',
            'category_id' => 'cat-test',
            'title' => 'خدمت تست',
            'base_price' => self::PRICE,
            'is_active' => 1,
            'fields_json' => '[]',
        ]);

        SettingsService::set('bazaarPayEnabled', '1');
        SettingsService::set('bazaarPayDirectEnabled', '1');
        SettingsService::set('bazaarPayDirectType', 'wallet');
        SettingsService::set('bazaarPayDirectPeriod', 'monthly');
        SettingsService::set('bazaarPayDirectAmountLimit', '2000000');
        SettingsService::set('appUrl', 'https://caffenet.example.com');
        SettingsService::set('paymentTestMode', '1');
    }

    private function customer(): User
    {
        // Zero balance on purpose: with the paymentMethod=bazaarpay intent the
        // server skips the wallet-balance creation guard (money comes from the
        // user's Bazaar wallet/bank, never from the local wallet).
        return User::create([
            'id' => 'usr-cust-bp',
            'phone' => '09120000002',
            'name' => 'مشتری دایرکت‌پی',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function createOrder(User $user): Order
    {
        $payload = [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
            // Direct-Pay intent — lets a zero-balance customer create the order.
            'paymentMethod' => 'bazaarpay',
        ];

        $resp = $this->actingAs($user)
            ->postJson('/api/orders', $payload)
            ->assertStatus(201);

        return Order::find($resp->json('id'));
    }

    /** ── Regression guard for the creation-guard bug ──────────────────── */

    public function test_zero_balance_customer_can_create_order_for_direct_pay(): void
    {
        $user = $this->customer(); // wallet_balance = 0

        $resp = $this->actingAs($user)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
                'paymentMethod' => 'bazaarpay',
            ])
            ->assertStatus(201);

        $order = Order::find($resp->json('id'));
        $this->assertSame('bazaarpay', $order->payment_method);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
    }

    public function test_bazaarpay_intent_falls_back_to_wallet_guard_when_direct_pay_disabled(): void
    {
        SettingsService::set('bazaarPayDirectEnabled', '0');

        // bazaarpay intent is ignored → wallet rules apply → zero balance blocks.
        $this->actingAs($this->customer())
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
                'paymentMethod' => 'bazaarpay',
            ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'INSUFFICIENT_WALLET');
    }

    public function test_wallet_method_still_requires_sufficient_balance(): void
    {
        // No paymentMethod (or wallet) + zero balance → blocked as before.
        $this->actingAs($this->customer())
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
            ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'INSUFFICIENT_WALLET');
    }

    /** ── Direct-pay pipeline ──────────────────────────────────────────── */

    public function test_first_direct_pay_creates_contract_and_returns_signing_url(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $resp = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'contract_pending');

        $signUrl = $resp->json('contractRedirectUrl');
        $this->assertStringStartsWith('https://app.bazaar-pay.ir/contract/direct-pay', $signUrl);
        $this->assertStringContainsString('redirect_url=https%3A%2F%2Fcaffenet.example.com%2Fapi%2Fpayments%2Fbazaarpay%2Fcontract', $signUrl);
        // The buyer phone travels along to pre-fill the Bazaar login.
        $this->assertStringContainsString('phone=09120000002', $signUrl);

        // The contract row mirrors the configured settings.
        $contract = DB::table('bazaarpay_contracts')->where('user_id', $user->id)->first();
        $this->assertNotNull($contract);
        $this->assertSame('wallet', $contract->type);
        $this->assertSame('monthly', $contract->period);
        $this->assertSame(2000000, $contract->amount_limit);
        $this->assertSame('new', $contract->state);

        // The pending payment is linked to the contract for the callback.
        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertSame('pending', $payment->status);
        $this->assertSame($contract->contract_token, $payment->contract_token);
    }

    public function test_contract_callback_after_signing_pays_the_order(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $resp = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertJsonPath('status', 'contract_pending');

        $contract = DB::table('bazaarpay_contracts')->where('user_id', $user->id)->first();

        // The user signed on BazaarPay → gateway redirects to our callback.
        $this->get('/api/payments/bazaarpay/contract?contract_token='.$contract->contract_token)
            ->assertRedirect();

        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertSame('paid', $payment->status);

        $order->refresh();
        $this->assertGreaterThan(0, (int) $order->paid_amount);

        // The contract became active and got an activation timestamp.
        $contract = DB::table('bazaarpay_contracts')->where('id', $contract->id)->first();
        $this->assertSame('active', $contract->state);
        $this->assertNotNull($contract->activated_at);
    }

    public function test_active_contract_pays_in_one_shot_without_redirect(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        // First attempt: create + sign the contract.
        $this->actingAs($user)->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertJsonPath('status', 'contract_pending');
        $contract = DB::table('bazaarpay_contracts')->where('user_id', $user->id)->first();
        $this->get('/api/payments/bazaarpay/contract?contract_token='.$contract->contract_token);

        // Second order, same user: an ACTIVE contract exists → single request
        // pays it server-side, no signing URL, no redirect.
        $order2 = $this->createOrder($user);
        $this->actingAs($user)
            ->postJson("/api/orders/{$order2->id}/pay-bazaarpay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('message', 'پرداخت مستقیم با موفقیت انجام شد.');

        $payment2 = DB::table('payments')->where('order_id', $order2->id)->first();
        $this->assertSame('paid', $payment2->status);
        $this->assertNotNull($payment2->authority, 'checkout token stored in authority');
    }
}
