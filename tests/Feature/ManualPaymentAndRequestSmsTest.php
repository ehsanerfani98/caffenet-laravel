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
 * depends on a customer payment, and the request enters the queue without
 * one:
 *
 *  1. With the paymentsDisabled switch ON, registering an order request
 *     (paymentMethod='request') immediately sends the order-confirmation
 *     SMS (smsPatternPaid) — the payment condition is removed — and the
 *     order is born `submitted` and enters the operator queue IMMEDIATELY
 *     (queue entry + ETA + track token): the operator reviews it, announces
 *     the price, the customer pays by any means, and management registers
 *     the payment manually. Nothing waits for a payment that never comes.
 *  2. Management can register a manual (offline) payment for any unpaid
 *     order in ALL conditions; the order is marked paid exactly like a
 *     verified gateway payment (receipt SMS + wallet-ledger transaction +
 *     coupon consumption + queue enqueue when it was not already queued)
 *     — POST /orders/{id}/manual-payment. A queued request order keeps its
 *     current status (submitted/processing/ready) — no re-enqueue, no
 *     demotion back to submitted.
 *  3. Gateway payments (BazaarPay/Zarinpal/IAP) go through
 *     PaymentFulfillmentService::apply — they now send the receipt SMS too
 *     (the wallet path always did; apply() never did).
 *  4. The 30-minute abandonment cleanup never touches request orders —
 *     they are not waiting for any in-app payment.
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

    /* ───────── 1. request mode: SMS at registration + IMMEDIATE queue entry ───────── */

    public function test_request_order_registration_sends_confirmation_sms_without_any_payment(): void
    {
        $customer = $this->customer();

        $order = $this->createRequestOrder($customer);

        // No payment step exists — the order does NOT wait in pending_payment:
        // it is born submitted and goes straight into the operator queue.
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);
        $this->assertEquals('request', $order->payment_method);
        $this->assertEquals(0, $order->paid_amount);

        // Queue bookkeeping happened at submission: entry + position + ETA
        // + public track token — the exact state a paid order gets.
        $this->assertDatabaseHas('queue_entries', ['order_id' => $order->id]);
        $this->assertNotNull($order->queue_position);
        $this->assertNotNull($order->estimated_wait_minutes);
        $this->assertNotNull($order->track_token);
        $this->assertNotNull($order->estimated_start_at);

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

        // The order was already in the queue since submission — the manual
        // payment must NOT create a second entry or re-stamp the ETA.
        $this->assertEquals(1, DB::table('queue_entries')->where('order_id', $order->id)->count());

        // A payment row records the manual transaction.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'gateway' => 'manual',
            'status' => 'paid',
            'amount' => self::PRICE,
        ]);

        // A wallet-ledger transaction records the manual payment for the
        // customer — visible in their wallet history and the admin's
        // user-transactions roster, exactly like other payment methods.
        // The wallet balance itself must stay untouched (the money was
        // collected outside the wallet).
        $paymentId = DB::table('payments')->where('order_id', $order->id)->where('gateway', 'manual')->value('id');
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'payment_id' => $paymentId,
            'type' => 'manual_payment',
            'direction' => 'debit',
            'amount' => self::PRICE,
            'balance_after' => 0,
            'reference' => 'RCPT-7734',
            'performed_by_name' => 'مدیر سیستم',
        ]);
        $this->assertEquals(0, (int) $customer->fresh()->wallet_balance);

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

    public function test_manual_payment_keeps_processing_status_of_a_queued_request_order(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $order = $this->createRequestOrder($customer);

        // The operator already picked the request up and is working on it
        // (price announced, customer paid in the meantime).
        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $this->fakeIppanel();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/manual-payment", [])
            ->assertStatus(200);

        $order->refresh();

        // Paid like any other order…
        $this->assertEquals(self::PRICE, $order->paid_amount);
        $this->assertEquals('manual', $order->payment_method);
        // …but NOT demoted back to submitted and NOT re-enqueued: the
        // operator's work continues exactly where it was.
        $this->assertEquals(Order::STATUS_PROCESSING, $order->status);
        $this->assertEquals(1, DB::table('queue_entries')->where('order_id', $order->id)->count());
    }

    public function test_wallet_payment_of_a_queued_request_order_preserves_its_queue_state(): void
    {
        // Defensive API-path coverage: the UI never offers it, but a customer
        // paying a queued request order from wallet must not demote the order
        // out of its operator's hands or duplicate the queue entry.
        $customer = User::create([
            'id' => 'usr-cust-rich3',
            'phone' => '09120000007',
            'name' => 'مشتری متمول سه',
            'role' => 'customer',
            'wallet_balance' => self::PRICE,
            'created_at' => now()->toIso8601String(),
        ]);
        $order = $this->createRequestOrder($customer);
        $this->fakeIppanel();

        $this->actingAs($customer)
            ->postJson("/api/orders/{$order->id}/pay-wallet", [])
            ->assertStatus(200);

        $order->refresh();
        $this->assertEquals(self::PRICE, $order->paid_amount);
        $this->assertEquals('wallet', $order->payment_method);
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);
        $this->assertEquals(1, DB::table('queue_entries')->where('order_id', $order->id)->count());
    }

    public function test_abandonment_cleanup_never_expires_request_orders(): void
    {
        // Enough balance for the plain wallet-intent order below (the request
        // order itself never touches the wallet).
        $customer = User::create([
            'id' => 'usr-cust-rich4',
            'phone' => '09120000008',
            'name' => 'مشتری متمول چهار',
            'role' => 'customer',
            'wallet_balance' => self::PRICE,
            'created_at' => now()->toIso8601String(),
        ]);
        $requestOrder = $this->createRequestOrder($customer);

        // A plain wallet-intent order still sitting in pending_payment — the
        // classic case the 30-minute cleanup exists for.
        $plainOrder = $this->actingAs($customer)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
            ])
            ->assertStatus(201);
        $plainId = $plainOrder->json('id');

        // Age both orders past the abandonment window. The request order is
        // forced back to pending_payment to mimic legacy rows created before
        // request orders were born submitted.
        $old = now()->subMinutes(40)->toIso8601String();
        DB::table('orders')->where('id', $requestOrder->id)->update(['status' => Order::STATUS_PENDING_PAYMENT, 'created_at' => $old]);
        DB::table('orders')->where('id', $plainId)->update(['created_at' => $old]);

        $expired = \App\Services\CleanupService::expireAbandonedPayments();

        // Only the payment-waiting plain order dies; the request order — no
        // matter how old — is never killed for a payment that was never part
        // of its flow.
        $this->assertEquals(1, $expired);
        $this->assertEquals(Order::STATUS_PENDING_PAYMENT, Order::find($requestOrder->id)->status);
        $this->assertEquals(Order::STATUS_FAILED, Order::find($plainId)->status);
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

        // The ledger row carries the exact settled amount.
        $this->assertDatabaseHas('wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'manual_payment',
            'amount' => 50000,
        ]);
    }

    public function test_gateway_payments_do_not_create_manual_payment_ledger_rows(): void
    {
        // A gateway (BazaarPay) payment applies through the same fulfillment
        // service but must NOT produce a manual_payment ledger row — the
        // wallet history stays reserved for wallet/manual movements.
        $customer = $this->customer();
        $order = $this->createRequestOrder($customer);

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'purpose' => 'order',
            'amount' => self::PRICE,
            'gateway' => 'bazaarpay',
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
        ]);

        $applied = \App\Services\PaymentFulfillmentService::apply($payment, 'BP-TRK-1', null, 'bazaarpay');

        $this->assertTrue($applied);
        $this->assertDatabaseMissing('wallet_transactions', [
            'order_id' => $order->id,
            'type' => 'manual_payment',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', ['order_id' => $order->id]);
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
