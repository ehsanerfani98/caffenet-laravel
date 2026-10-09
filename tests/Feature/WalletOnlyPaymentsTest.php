<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Services are paid exclusively from the wallet: the gateway endpoint only
 * accepts wallet top-ups, and an order is never created when the customer
 * cannot afford it.
 */
class WalletOnlyPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 50000;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            'id' => 'cat-test', 'title' => 'دسته تست', 'icon_name' => 'Printer',
        ]);

        // A simple fixed-price service (no dynamic fields) so pricing is exactly
        // its base_price for a normal/digital order.
        DB::table('services')->insert([
            'id' => 'srv-test',
            'category_id' => 'cat-test',
            'title' => 'خدمت تست',
            'base_price' => self::PRICE,
            'is_active' => 1,
            'fields_json' => '[]',
        ]);
    }

    private function customer(int $balance = 0): User
    {
        return User::create([
            'phone' => '09120000001',
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => $balance,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function orderPayload(): array
    {
        return [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            // A contact channel is required at creation time.
            'contactMethods' => ['app_chat'],
        ];
    }

    public function test_payment_request_rejects_order_purpose(): void
    {
        $user = $this->customer(0);

        $this->actingAs($user)
            ->postJson('/api/payments/request', ['purpose' => 'order', 'orderId' => 'ord-x'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'WALLET_ONLY');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_request_creates_wallet_topup_only(): void
    {
        SettingsService::set('paymentTestMode', '1');
        $user = $this->customer(0);

        $response = $this->actingAs($user)
            ->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => self::PRICE]);

        $response->assertOk()
            ->assertJsonPath('authority', fn ($v) => str_starts_with((string) $v, 'TEST-'));

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'purpose' => 'wallet',
            'order_id' => null,
            'amount' => self::PRICE,
        ]);
    }

    public function test_order_creation_blocked_when_wallet_is_insufficient(): void
    {
        $user = $this->customer(0);

        $this->actingAs($user)
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(400)
            ->assertJsonPath('code', 'INSUFFICIENT_WALLET')
            ->assertJsonPath('required', self::PRICE)
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('shortfall', self::PRICE);

        // No order row may be persisted when the wallet cannot cover it.
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_is_paid_from_wallet(): void
    {
        $user = $this->customer(100000);

        $order = $this->actingAs($user)
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(201)
            ->json();

        $this->actingAs($user)
            ->postJson('/api/orders/'.$order['id'].'/pay-wallet')
            ->assertOk();

        $fresh = Order::find($order['id']);
        $this->assertSame(Order::STATUS_SUBMITTED, $fresh->status);
        $this->assertSame(self::PRICE, (int) $fresh->paid_amount);
        $this->assertSame('wallet', $fresh->payment_method);

        $this->assertSame(50000, (int) User::find($user->id)->wallet_balance);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order['id'],
            'purpose' => 'order',
            'gateway' => 'wallet',
            'status' => 'paid',
            'amount' => self::PRICE,
        ]);
    }

    public function test_pay_with_wallet_returns_structured_shortage(): void
    {
        $user = $this->customer(100000);

        $order = $this->actingAs($user)
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(201)
            ->json();

        // Drain the balance after the order was created (e.g. another debit).
        DB::table('users')->where('id', $user->id)->update(['wallet_balance' => 0]);

        $this->actingAs($user)
            ->postJson('/api/orders/'.$order['id'].'/pay-wallet')
            ->assertStatus(400)
            ->assertJsonPath('code', 'INSUFFICIENT_WALLET')
            ->assertJsonPath('required', self::PRICE)
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('shortfall', self::PRICE);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($order['id'])->status);
    }
}
