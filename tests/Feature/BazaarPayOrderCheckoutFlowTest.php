<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BazaarPay CHECKOUT-redirect order payments — the customer picks «درگاه
 * پرداخت» in the order form and pays on the BazaarPay gateway page exactly
 * like a wallet top-up.
 *
 * Covers (test mode = BazaarPayService simulates every endpoint):
 *  - pay-bazaarpay with direct pay off → status=redirect + pending payment
 *    row (purpose=order) linked to the order
 *  - /verify callback → order submitted + paid + log entry
 *  - pollable /status endpoint reconciles a lost callback
 *
 * And in real HTTP mode (Http::fake) the smart-resolution fallbacks:
 *  - direct pay ON + contract init rejected ("Contract type not found") →
 *    falls back to the checkout redirect instead of failing
 *  - direct pay ON + contract init OK → contract_pending (unchanged)
 */
class BazaarPayOrderCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 90000;

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
        SettingsService::set('bazaarPayDirectEnabled', '0'); // gateway-only by default here
        SettingsService::set('appUrl', 'https://caffenet.example.com');
        SettingsService::set('paymentTestMode', '1');
    }

    private function customer(): User
    {
        // Zero balance on purpose: with the bazaarpay intent the server skips
        // the wallet-balance creation guard (money never comes from the local
        // wallet on this path).
        return User::create([
            'id' => 'usr-cust-ck',
            'phone' => '09120000003',
            'name' => 'مشتری درگاه',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function createOrder(User $user): Order
    {
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

        return Order::find($resp->json('id'));
    }

    /** ── Test-mode checkout pipeline ──────────────────────────────────── */

    public function test_gateway_order_payment_returns_checkout_redirect(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $resp = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'redirect')
            ->assertJsonPath('gateway', 'bazaarpay')
            ->assertJsonPath('orderId', $order->id);

        $this->assertStringStartsWith('/test-gateway?authority=TEST-', $resp->json('redirectUrl'));

        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('order', $payment->purpose);
        $this->assertSame('bazaarpay', $payment->gateway);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(self::PRICE, (int) $payment->amount);
        $this->assertNotNull($payment->authority);
        $this->assertSame('', (string) $payment->contract_token);

        // The order itself is untouched until the payment verifies.
        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(0, (int) $order->paid_amount);
    }

    public function test_verify_callback_fulfils_the_order(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $pay = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertJsonPath('status', 'redirect');

        $authority = $pay->json('authority');

        // Gateway return: /verify with the checkout token + Status=OK.
        $this->get('/api/payments/verify?authority='.urlencode($authority).'&Status=OK')
            ->assertRedirect();

        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertSame('paid', $payment->status);

        $order->refresh();
        $this->assertSame(Order::STATUS_SUBMITTED, $order->status);
        $this->assertSame(self::PRICE, (int) $order->paid_amount);
        $this->assertSame('bazaarpay', $order->payment_method);
        $this->assertNotSame('', (string) $order->payment_tracking_code);

        // Order log records the BazaarPay gateway success.
        $this->assertTrue(
            DB::table('order_logs')->where('order_id', $order->id)
                ->where('action', 'پرداخت با درگاه بازارپی با موفقیت انجام شد')
                ->exists()
        );
    }

    public function test_status_poll_reconciles_a_lost_callback(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $pay = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertJsonPath('status', 'redirect');

        // The user paid on the gateway page but the /verify callback never
        // arrived — the pollable status endpoint is the self-healing path.
        $this->actingAs($user)
            ->getJson('/api/payments/status?authority='.urlencode($pay->json('authority')))
            ->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('purpose', 'order')
            ->assertJsonPath('orderId', $order->id);

        $order->refresh();
        $this->assertSame(Order::STATUS_SUBMITTED, $order->status);
        $this->assertSame(self::PRICE, (int) $order->paid_amount);
    }

    public function test_paying_an_already_paid_order_is_rejected(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertJsonPath('status', 'redirect');
        $this->get('/api/payments/verify?authority='.urlencode(
            (string) DB::table('payments')->where('order_id', $order->id)->value('authority')
        ).'&Status=OK');

        $this->actingAs($user)->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(400)
            ->assertJsonPath('error', 'این سفارش قبلاً پرداخت شده است.');
    }

    public function test_other_users_cannot_pay_someone_elses_order(): void
    {
        $user = $this->customer();
        $order = $this->createOrder($user);

        $intruder = User::create([
            'id' => 'usr-intruder',
            'phone' => '09120000004',
            'name' => 'غریبه',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);

        $this->actingAs($intruder)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(403);
    }

    /** ── Real-HTTP smart resolution (Http::fake) ──────────────────────── */

    private function realMode(): void
    {
        SettingsService::set('paymentTestMode', '0');
        SettingsService::set('bazaarPayToken', 'test-merchant-token');
        SettingsService::set('bazaarPayDestination', 'ehsancaffenet_bazaar');
    }

    public function test_contract_init_failure_falls_back_to_checkout_redirect(): void
    {
        $this->realMode();
        SettingsService::set('bazaarPayDirectEnabled', '1'); // direct pay wanted…

        // …but BazaarPay rejects the contract (merchant not activated yet,
        // exactly the current production situation) while checkout works.
        Http::fake([
            '*/badje/v1/direct-pay/contract/init*' => Http::response(
                ['detail' => ['Contract type not found']], 400
            ),
            '*/badje/v1/checkout/init*' => Http::response([
                'checkout_token' => 'bp-checkout-1',
                'payment_url' => 'https://app.bazaar-pay.ir/pay/session-1',
            ], 200),
        ]);

        $user = $this->customer();
        $order = $this->createOrder($user);

        $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'redirect')
            ->assertJsonPath('authority', 'bp-checkout-1')
            ->assertJsonPath('redirectUrl', 'https://app.bazaar-pay.ir/pay/session-1'
                .'?redirect_url=https%3A%2F%2Fcaffenet.example.com%2Fapi%2Fpayments%2Fverify%3Fauthority%3Dbp-checkout-1%26gw%3Dbazaarpay'
                .'&phone=09120000003');

        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertSame('bp-checkout-1', $payment->authority);
        $this->assertSame('', (string) $payment->contract_token);
    }

    public function test_contract_init_success_still_returns_contract_pending(): void
    {
        $this->realMode();
        SettingsService::set('bazaarPayDirectEnabled', '1');

        Http::fake([
            '*/badje/v1/direct-pay/contract/init*' => Http::response(
                ['contract_token' => 'ct-real-1'], 200
            ),
        ]);

        $user = $this->customer();
        $order = $this->createOrder($user);

        $resp = $this->actingAs($user)
            ->postJson("/api/orders/{$order->id}/pay-bazaarpay")
            ->assertStatus(200)
            ->assertJsonPath('status', 'contract_pending');

        $this->assertStringStartsWith('https://app.bazaar-pay.ir/contract/direct-pay', $resp->json('contractRedirectUrl'));

        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        $this->assertSame('ct-real-1', $payment->contract_token);
    }

    public function test_gateway_disabled_rejects_order_payment(): void
    {
        SettingsService::set('bazaarPayEnabled', '0');

        $user = $this->customer();
        // Without the gateway the creation intent falls back to wallet, which
        // blocks the zero-balance customer — so craft the order directly.
        DB::table('orders')->insert([
            'id' => 'ord-manual-1',
            'order_number' => '100001',
            'service_id' => 'srv-test',
            'service_title' => 'خدمت تست',
            'customer_id' => $user->id,
            'customer_phone' => $user->phone,
            'customer_name' => $user->name,
            'status' => 'pending_payment',
            'urgency' => 'normal',
            'delivery_method' => 'download',
            'form_data_json' => '[]',
            'customer_files_json' => '[]',
            'result_files_json' => '[]',
            'total_price' => self::PRICE,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'bazaarpay',
            'payment_tracking_code' => '',
            'contact_methods_json' => '[]',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/orders/ord-manual-1/pay-bazaarpay')
            ->assertStatus(400)
            ->assertJsonPath('error', 'درگاه پرداخت بازارپی فعال نیست.');
    }
}
