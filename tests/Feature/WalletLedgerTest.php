<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PaymentFulfillmentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wallet ledger + refunds:
 *  - every money movement (top-up, purchase, manual admin change, refund) is
 *    recorded with a running balance_after snapshot;
 *  - refunds are admin/manager only and credit the customer's wallet;
 *  - /api/auth/me reflects the debited balance (the "nothing is deducted" bug).
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 50000;

    protected function setUp(): void
    {
        parent::setUp();

        // Real roles + the full permission catalog (admin gets everything),
        // so hasAnyPermission checks inside controllers behave like production.
        $this->seed(RoleSeeder::class);

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
    }

    private function user(string $role, int $balance, string $phone): User
    {
        $user = User::create([
            'phone' => $phone,
            'name' => 'کاربر '.$role,
            'role' => $role,
            'wallet_balance' => $balance,
            'created_at' => now()->toIso8601String(),
        ]);

        if ($role === 'admin') {
            $user->assignRole('admin');
        }

        return $user;
    }

    private function orderPayload(): array
    {
        return [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
        ];
    }

    public function test_wallet_topup_records_credit_ledger(): void
    {
        $user = $this->user('customer', 0, '09120000001');

        $payment = Payment::create([
            'user_id' => $user->id,
            'purpose' => 'wallet',
            'amount' => self::PRICE,
            'authority' => 'TEST-TOPUP-1',
            'gateway' => 'test',
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ]);

        PaymentFulfillmentService::apply($payment, 'REF-1', '603799');

        $this->assertSame(self::PRICE, (int) User::find($user->id)->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'topup',
            'direction' => 'credit',
            'amount' => self::PRICE,
            'balance_after' => self::PRICE,
        ]);
    }

    public function test_order_wallet_payment_records_debit_ledger_and_me_reflects_balance(): void
    {
        $user = $this->user('customer', 100000, '09120000002');

        $order = $this->actingAs($user)
            ->postJson('/api/orders', $this->orderPayload())
            ->assertStatus(201)
            ->json();

        $this->actingAs($user)
            ->postJson('/api/orders/'.$order['id'].'/pay-wallet')
            ->assertOk();

        $this->assertSame(50000, (int) User::find($user->id)->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'order_id' => $order['id'],
            'type' => 'purchase',
            'direction' => 'debit',
            'amount' => self::PRICE,
            'balance_after' => 50000,
        ]);

        // Bug regression: /api/auth/me must expose the debited balance so the
        // SPA stops showing the stale pre-payment amount. A fresh model
        // instance mirrors what a real follow-up HTTP request loads.
        $this->actingAs(User::find($user->id))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('walletBalance', 50000);
    }

    public function test_manual_admin_wallet_change_records_ledger(): void
    {
        $admin = $this->user('admin', 0, '09120000009');
        $customer = $this->user('customer', 0, '09120000003');

        $this->actingAs($admin)
            ->patchJson('/api/users/'.$customer->id, ['walletChange' => 30000])
            ->assertOk();

        $this->assertSame(30000, (int) User::find($customer->id)->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'type' => 'manual_credit',
            'direction' => 'credit',
            'amount' => 30000,
            'balance_after' => 30000,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/users/'.$customer->id, ['walletChange' => -10000])
            ->assertOk();

        $this->assertSame(20000, (int) User::find($customer->id)->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'type' => 'manual_debit',
            'direction' => 'debit',
            'amount' => 10000,
            'balance_after' => 20000,
        ]);
    }

    public function test_admin_refund_credits_wallet_and_marks_order_refunded(): void
    {
        $admin = $this->user('admin', 0, '09120000009');
        $customer = $this->user('customer', 0, '09120000004');

        $orderId = 'ord-refund-1';
        DB::table('orders')->insert([
            'id' => $orderId,
            'order_number' => 'CN-0001-0001',
            'service_id' => 'srv-test',
            'service_title' => 'خدمت تست',
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone,
            'customer_name' => $customer->name,
            'status' => Order::STATUS_DELIVERED,
            'form_data_json' => '{}',
            'customer_files_json' => '[]',
            'result_files_json' => '[]',
            'total_price' => self::PRICE,
            'discount_amount' => 0,
            'paid_amount' => self::PRICE,
            'payment_method' => 'wallet',
            'payment_tracking_code' => 'WLT-000001',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->actingAs($admin)->postJson('/api/wallet/refund', [
            'userId' => $customer->id,
            'orderId' => $orderId,
            'amount' => self::PRICE,
            'reason' => 'رضایت مشتری',
        ])->assertOk();

        $fresh = Order::find($orderId);
        $this->assertSame(Order::STATUS_REFUNDED, $fresh->status);
        $this->assertSame(self::PRICE, (int) $fresh->refund_amount);
        $this->assertNotNull($fresh->refunded_at);

        $this->assertSame(self::PRICE, (int) User::find($customer->id)->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'order_id' => $orderId,
            'type' => 'refund',
            'direction' => 'credit',
            'amount' => self::PRICE,
            'balance_after' => self::PRICE,
        ]);
        $this->assertDatabaseHas('order_logs', ['order_id' => $orderId]);
    }

    public function test_refund_is_denied_for_non_admin(): void
    {
        $operator = $this->user('operator', 0, '09120000005');
        $customer = $this->user('customer', 0, '09120000006');

        $this->actingAs($operator)->postJson('/api/wallet/refund', [
            'userId' => $customer->id,
            'amount' => 10000,
        ])->assertStatus(403);
    }

    public function test_customer_sees_only_own_transactions(): void
    {
        $a = $this->user('customer', 0, '09120000007');
        $b = $this->user('customer', 0, '09120000008');

        WalletTransaction::create([
            'user_id' => $a->id, 'type' => 'topup', 'direction' => 'credit',
            'amount' => 1000, 'balance_after' => 1000, 'created_at' => now()->toIso8601String(),
        ]);
        WalletTransaction::create([
            'user_id' => $b->id, 'type' => 'topup', 'direction' => 'credit',
            'amount' => 2000, 'balance_after' => 2000, 'created_at' => now()->toIso8601String(),
        ]);

        $response = $this->actingAs($a)->getJson('/api/wallet/transactions')->assertOk()->json();

        $this->assertCount(1, $response);
        $this->assertSame($a->id, $response[0]['userId']);
        $this->assertSame(1000, $response[0]['amount']);
    }

    public function test_admin_can_view_all_transactions(): void
    {
        $admin = $this->user('admin', 0, '09120000009');
        $a = $this->user('customer', 0, '09120000010');
        $b = $this->user('customer', 0, '09120000011');

        WalletTransaction::create([
            'user_id' => $a->id, 'type' => 'topup', 'direction' => 'credit',
            'amount' => 1000, 'balance_after' => 1000, 'created_at' => now()->toIso8601String(),
        ]);
        WalletTransaction::create([
            'user_id' => $b->id, 'type' => 'topup', 'direction' => 'credit',
            'amount' => 2000, 'balance_after' => 2000, 'created_at' => now()->toIso8601String(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/wallet/transactions')->assertOk()->json();

        $this->assertCount(2, $response);
    }
}
