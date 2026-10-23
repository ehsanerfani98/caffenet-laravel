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
 * Management notification for a NEWLY registered order («سفارش جدید»).
 *
 * A single admin-configured number (smsNewOrderAdminPhone) receives the
 * plain-text pattern smsPatternNewOrder (NO IPPanel variables) exactly
 * once per order — at the moment the order ENTERS the system:
 *
 *   1. request-mode registration (paymentsDisabled on) — born submitted;
 *   2. wallet payment that lifts a pre-payment order into submitted;
 *   3. gateway / manual fulfillment (PaymentFulfillmentService::apply).
 *
 * A request-mode order that is paid later must NOT be announced twice
 * (it was already announced at registration). Empty admin number = the
 * notification is silently off.
 *
 * The wallet-path test also guards a queue-entry regression: the enqueue
 * guard there checked the POST-payment status against pre-payment states
 * (always false) — wallet-paid classic orders never got a queue entry,
 * ETA or track token. The fix captures the pre-payment state inside the
 * transaction ($enteredQueue) and the test pins queue_entries existence.
 */
class NewOrderAdminSmsTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 90000;
    private const ADMIN_NUMBER = '09123456789';

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

        // The feature under test: one management number + a variable-free
        // plain-text pattern (e.g. «سفارش جدید دارید» defined in IPPanel).
        SettingsService::set('smsPatternNewOrder', 'code-new-order');
        SettingsService::set('smsNewOrderAdminPhone', self::ADMIN_NUMBER);

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

    private function richCustomer(): User
    {
        return User::create([
            'id' => 'usr-cust-rich',
            'phone' => '09120000005',
            'name' => 'مشتری متمول',
            'role' => 'customer',
            'wallet_balance' => self::PRICE,
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

    /** Register a classic unpaid order (waits in pending_payment). */
    private function createClassicOrder(User $user): string
    {
        $resp = $this->actingAs($user)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
            ])
            ->assertStatus(201);

        $order = Order::find($resp->json('id'));
        $this->assertEquals(Order::STATUS_PENDING_PAYMENT, $order->status);

        return $order->id;
    }

    /** The management SMS: pattern code + the single configured number + EMPTY params. */
    private function assertAdminSmsSent(string $orderNumber): void
    {
        Http::assertSent(function ($request) use ($orderNumber) {
            if (! str_starts_with((string) $request->url(), 'https://edge.ippanel.com')) {
                return false;
            }
            $body = $request->data();

            return ($body['code'] ?? '') === 'code-new-order'
                && in_array('+98'.substr(self::ADMIN_NUMBER, 1), $body['recipients'] ?? [], true)
                // Recorded data() mirrors the original payload — the wire
                // params are {} (stdClass) for a variable-free pattern.
                && empty((array) ($body['params'] ?? []));
        });
        $this->assertDatabaseHas('sms_logs', [
            'phone' => self::ADMIN_NUMBER,
            'type' => 'new_order_admin',
            'status' => 'sent',
        ]);
    }

    /** How many management-notification SMS were dispatched so far. */
    private function adminSmsCount(): int
    {
        return collect(Http::recorded())->filter(function ($pair) {
            $request = $pair[0];

            return str_starts_with((string) $request->url(), 'https://edge.ippanel.com')
                && ($request->data()['code'] ?? '') === 'code-new-order';
        })->count();
    }

    /* ───────── 1. request-mode registration ───────── */

    public function test_request_order_registration_notifies_the_configured_admin_number_once(): void
    {
        $customer = $this->customer();

        $order = $this->createRequestOrder($customer);

        $this->assertAdminSmsSent($order->order_number);
        $this->assertSame(1, $this->adminSmsCount());

        // The customer receipt SMS still fires as before — unaffected.
        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone, 'type' => 'paid', 'status' => 'sent',
        ]);
    }

    /* ───────── 2. wallet payment of a classic order ───────── */

    public function test_wallet_payment_of_a_classic_order_notifies_management_and_enters_the_queue(): void
    {
        $customer = $this->richCustomer();
        $orderId = $this->createClassicOrder($customer);
        $this->fakeIppanel(); // reset recorded HTTP — only the payment matters below

        $this->actingAs($customer)
            ->postJson("/api/orders/{$orderId}/pay-wallet")
            ->assertStatus(200);

        $order = Order::find($orderId);
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);

        // Queue-entry regression guard: the payment must bring the order into
        // the queue — entry + position + ETA + public track token.
        $this->assertDatabaseHas('queue_entries', ['order_id' => $orderId]);
        $this->assertNotNull($order->queue_position);
        $this->assertNotNull($order->estimated_wait_minutes);
        $this->assertNotNull($order->track_token);

        // Exactly ONE management notification, to the configured number.
        $this->assertAdminSmsSent($order->order_number);
        $this->assertSame(1, $this->adminSmsCount());
    }

    /* ───────── 3. request-order paid later: never announced twice ───────── */

    public function test_request_order_paid_later_is_not_notified_twice(): void
    {
        $customer = $this->customer('usr-cust-rq2', '09120000006');
        $customer->wallet_balance = self::PRICE;
        $customer->save();

        $order = $this->createRequestOrder($customer);
        $this->assertSame(1, $this->adminSmsCount()); // announced at registration

        $this->fakeIppanel(); // reset recorded HTTP — only the later payment is observed now

        $this->actingAs($customer)
            ->postJson("/api/orders/{$order->id}/pay-wallet")
            ->assertStatus(200);

        // The wallet payment must NOT re-announce the order to management…
        $this->assertSame(0, $this->adminSmsCount());
        // …nor create a second queue entry.
        $this->assertEquals(1, DB::table('queue_entries')->where('order_id', $order->id)->count());
    }

    /* ───────── 4. manual payment registration by management ───────── */

    public function test_manual_payment_registration_notifies_management(): void
    {
        $customer = $this->richCustomer();
        $admin = $this->admin();
        $orderId = $this->createClassicOrder($customer);
        $this->fakeIppanel();

        $this->actingAs($admin)
            ->postJson("/api/orders/{$orderId}/manual-payment", [
                'reference' => 'RCPT-7734',
            ])
            ->assertStatus(200);

        $order = Order::find($orderId);
        $this->assertEquals(Order::STATUS_SUBMITTED, $order->status);

        $this->assertAdminSmsSent($order->order_number);
        $this->assertSame(1, $this->adminSmsCount());
        $this->assertDatabaseHas('queue_entries', ['order_id' => $orderId]);
    }

    /* ───────── 5. empty admin number = notification off ───────── */

    public function test_empty_admin_phone_sends_no_management_sms(): void
    {
        SettingsService::set('smsNewOrderAdminPhone', '');

        $customer = $this->customer();

        $order = $this->createRequestOrder($customer);

        $this->assertSame(0, $this->adminSmsCount());
        $this->assertDatabaseMissing('sms_logs', ['type' => 'new_order_admin']);

        // The customer-facing chain is untouched.
        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone, 'type' => 'paid', 'status' => 'sent',
        ]);
    }
}
