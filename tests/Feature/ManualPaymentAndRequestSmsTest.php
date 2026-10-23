<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «حالت ثبت درخواست» + پرداخت دستی مدیریت — the SMS chain no longer
 * depends on a customer payment:
 *
 *  1. With the paymentsDisabled switch ON, registering an order request
 *     (paymentMethod='request') immediately sends the order-confirmation
 *     SMS (smsPatternPaid) — the payment condition is removed.
 *  2. Management can register a manual (offline) payment for any unpaid
 *     order in ALL conditions; the order is marked paid + submitted exactly
 *     like a verified gateway payment (receipt SMS + queue enqueue +
 *     coupon consumption) — POST /orders/{id}/manual-payment.
 *  3. Gateway payments (BazaarPay/Zarinpal/IAP) go through
 *     PaymentFulfillmentService::apply — they now send the receipt SMS too
 *     (the wallet path always did; apply() never did).
 */
class ManualPaymentAndRequestSmsTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 90000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

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

        // SMS system fully configured and live (real edge.ippanel HTTP call,
        // faked below) so every assertion targets the actual send path.
        SettingsService::set('smsEnabled', '1');
        SettingsService::set('smsNotifyEnabled', '1');
        SettingsService::set('smsTestMode', '0');
        SettingsService::set('smsApiKey', 'test-api-key');
        SettingsService::set('smsSender', '09120000000');
        SettingsService::set('smsPatternPaid', 'code-paid');
        SettingsService::set('smsPatternPaid_order_number', 'factor');
        SettingsService::set('smsPatternPaid_amount', 'amount');

        $this->fakeIppanel();
    }

    private function fakeIppanel(): void
    {
        Http::fake([
            'edge.ippanel.com/*' => Http::response([
                'meta' => ['status' => true, 'message' => 'ok'],
                'data' => ['message_outbox_ids' => [12345]],
            ], 200),
        ]);
    }

    private function customer(string $id = 'usr-cust-1', string $phone = '09120000001'): User
    {
        return User::create([
            'id' => $id,
            'phone' => $phone,
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-admin-1',
            'phone' => '09120000002',
            'name' => 'مدیر سیستم',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    /** Register an order request (valid only while paymentsDisabled is on). */
    private function createRequestOrder(User $user): Order
    {
        SettingsService::set('paymentsDisabled', '1');

        $resp = $this->actingAs($user)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
                'paymentMethod' => 'request',
            ])
            ->assertStatus(201);

        SettingsService::set('paymentsDisabled', '0');

        return Order::find($resp->json('id'));
    }

    private function assertPaidSmsSent(string $phone, string $orderNumber, string $amount): void
    {
        Http::assertSent(function ($request) use ($phone, $orderNumber, $amount) {
            if (! str_starts_with((string) $request->url(), 'https://edge.ippanel.com')) {
                return false;
            }
            $body = $request->data();

            return ($body['code'] ?? '') === 'code-paid'
                && in_array('+98'.substr($phone, 1), $body['recipients'] ?? [], true)
                && ($body['params']['factor'] ?? '') === $orderNumber
                && ($body['params']['amount'] ?? '') === $amount;
        });
        $this->assertDatabaseHas('sms_logs', [
            'phone' => $phone,
            'type' => 'paid',
            'status' => 'sent',
        ]);
    }

    /* ───────── 1. request mode: SMS at registration (no payment) ───────── */

    public function test_request_order_registration_sends_confirmation_sms_without_any_payment(): void
    {
        $customer = $this->customer();

        $order = $this->createRequestOrder($customer);

        $this->assertEquals(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertEquals('request', $order->payment_method);
        $this->assertEquals(0, $order->paid_amount);

        $this->assertPaidSmsSent($customer->phone, $order->order_number, (string) self::PRICE);
    }

    public function test_normal_wallet_order_still_skips_sms_at_registration(): void
    {
        // Enough balance so the wallet path accepts the order creation.
        $customer = User::create([
            'id' => 'usr-cust-rich',
            'phone' => '09120000005',
            'name' => 'مشتری متمول',
            'role' => 'customer',
            'wallet_balance' => self::PRICE,
            'created_at' => now()->toIso8601String(),
        ]);

        $resp = $this->actingAs($customer)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
            ])
            ->assertStatus(201);

        // No payment happened yet → no receipt SMS. The paid SMS must only
        // fire when the wallet/gateway payment verifies.
        $this->assertDatabaseMissing('sms_logs', ['type' => 'paid']);
        Http::assertNothingSent();
        $this->assertNotNull(Order::find($resp->json('id')));
    }

    /* ───────── 2. manual payment registration by management ───────── */

    public function test_admin_can_register_manual_payment_for_a_request_order(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);
        $this->fakeIppanel();

        $resp = $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [
                'reference' => 'RCPT-7734',
                'note' => 'پرداخت نقدی در محل',
            ])
            ->assertStatus(200);

        $order->refresh();

        $this->assertEquals(self::PRICE, $order->paid_amount);
        $this->assertEquals('manual', $order->payment_method);
        $this->assertEquals('RCPT-7734', $order->payment_tracking_code);
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);

        // A payment row records the manual transaction.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'manual',
            'status' => 'paid',
            'amount' => self::PRICE,
        ]);

        // Exactly like a gateway payment: queue entry + receipt SMS + logs.
        $this->assertDatabaseHas('queue_entries', ['order_id' => $order->id]);
        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'action' => 'ثبت پرداخت دستی توسط مدیریت',
            'performed_by' => 'مدیر سیستم',
        ]);
        $this->assertDatabaseHas('order_logs', [
            'order_id' => $order->id,
            'action' => 'توضیح پرداخت دستی',
            'note' => 'پرداخت نقدی در محل',
        ]);
        $this->assertPaidSmsSent($customer->phone, $order->order_number, (string) self::PRICE);

        $this->assertEquals('manual', $resp->json('paymentMethod'));
    }

    public function test_manual_payment_consumes_reserved_coupon(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);

        DB::table('coupons')->insert([
            'id' => 'cpn-test', 'code' => 'OFF10', 'percent' => 10,
            'expire_date' => '2099-12-31', 'usage_count' => 0, 'max_usage' => 100, 'is_active' => 1,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'coupon_id' => 'cpn-test',
            'discount_amount' => 9000,
            'total_price' => self::PRICE,
        ]);
        $this->fakeIppanel();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(200);

        $order->refresh();
        // Amount omitted → defaults to payable (total - discount).
        $this->assertEquals(self::PRICE - 9000, $order->paid_amount);
        $this->assertEquals(1, DB::table('coupons')->where('id', 'cpn-test')->value('usage_count'));
    }

    public function test_manual_payment_with_custom_amount_records_that_amount(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);
        $this->fakeIppanel();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", ['amount' => 50000])
            ->assertStatus(200);

        $order->refresh();
        $this->assertEquals(50000, $order->paid_amount);
    }

    public function test_manual_payment_is_available_even_with_payments_enabled(): void
    {
        // Enough balance for the plain wallet-intent order creation.
        $customer = User::create([
            'id' => 'usr-cust-rich2',
            'phone' => '09120000006',
            'name' => 'مشتری متمول دو',
            'role' => 'customer',
            'wallet_balance' => self::PRICE,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin = $this->admin();

        // A plain pending_payment order (payments enabled, never paid).
        $order = $this->actingAs($customer)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
            ])
            ->assertStatus(201);
        $order = Order::find($order->json('id'));
        $this->fakeIppanel();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(200);

        $order->refresh();
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);
        $this->assertEquals(self::PRICE, $order->paid_amount);
    }

    /* ───────── guards ───────── */

    public function test_manual_payment_rejects_already_paid_order(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);
        DB::table('orders')->where('id', $order->id)->update(['paid_amount' => self::PRICE]);

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(400);
    }

    public function test_manual_payment_rejects_cancelled_and_delivered_orders(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);

        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_CANCELLED]);
        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(400);

        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_DELIVERED]);
        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(400);
    }

    public function test_manual_payment_rejects_zero_amount(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", ['amount' => 0])
            ->assertStatus(400);
    }

    public function test_customer_cannot_register_manual_payment(): void
    {
        $customer = $this->customer();
        $other = $this->customer('usr-cust-2', '09120000009');
        $order = $this->createRequestOrder($other);

        $this->actingAs($customer)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(403);
    }

    /* ───────── 3. gateway payments now send the receipt SMS too ───────── */

    public function test_gateway_payment_via_fulfillment_service_sends_paid_sms(): void
    {
        $customer = $this->customer();
        $order = $this->createRequestOrder($customer);
        $this->fakeIppanel();

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'purpose' => 'order',
            'amount' => self::PRICE,
            'gateway' => 'bazaarpay',
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ]);

        $applied = \App\Services\PaymentFulfillmentService::apply($payment, 'BP-9981', null, 'bazaarpay');

        $this->assertTrue($applied);
        $order->refresh();
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);
        $this->assertEquals('bazaarpay', $order->payment_method);
        $this->assertPaidSmsSent($customer->phone, $order->order_number, (string) self::PRICE);

        // Two rows so far: the request-registration confirmation + this payment.
        $afterFirst = DB::table('sms_logs')->where('phone', $customer->phone)->where('type', 'paid')->count();
        $this->assertEquals(2, $afterFirst);

        // Idempotency: a second apply (concurrent callback) must NOT resend.
        \App\Services\PaymentFulfillmentService::apply($payment->fresh(), 'BP-9981', null, 'bazaarpay');
        $afterSecond = DB::table('sms_logs')->where('phone', $customer->phone)->where('type', 'paid')->count();
        $this->assertEquals($afterFirst, $afterSecond);
    }
}
