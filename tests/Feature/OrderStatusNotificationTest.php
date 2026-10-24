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
 * Customer notifications for the status transitions the STAFF UI actually
 * performs.
 *
 * The operator console (screens/operator.js) and the admin orders screen
 * (screens/admin-orders.js) change an order's status exclusively through
 * PATCH /api/orders/{id}/status (OrderController::updateStatus) — the
 * queue's dedicated /start and /complete endpoints are never called by the
 * frontend. Those UI chips ("در حال انجام", "آماده تحویل", "لغو شده") MUST
 * therefore fire the same customer notifications as the dedicated queue
 * endpoints do:
 *
 *   processing → in-app log order_started + FCM push + pattern SMS
 *   ready      → in-app log order_ready   + FCM push + pattern SMS
 *   cancelled  → in-app log cancelled     + FCM push + pattern SMS
 *
 * The blanket status_change SMS is deliberately SKIPPED for those three
 * transitions (the dispatcher already sends the dedicated pattern — a
 * generic SMS would double-text the customer) and kept for every other
 * status (delivered / failed / refunded / submitted / pending_payment).
 *
 * FCM is disabled here (default) so the assertions target the in-app log
 * and the SMS wire; FcmPushServiceTest covers the push channel itself.
 */
class OrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** Cached staff actor — setOrderStatus must not recreate the admin row. */
    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        DB::table('categories')->insert([
            'id' => 'cat-ns', 'title' => 'دسته تست', 'icon_name' => 'Printer',
        ]);
        DB::table('services')->insert([
            'id' => 'srv-ns',
            'category_id' => 'cat-ns',
            'title' => 'خدمت تست',
            'base_price' => 50000,
            'is_active' => 1,
            'fields_json' => '[]',
        ]);

        // SMS system fully configured and live (real edge.ippanel call,
        // faked below) so every assertion targets the actual send path.
        SettingsService::set('smsEnabled', '1');
        SettingsService::set('smsNotifyEnabled', '1');
        SettingsService::set('smsTestMode', '0');
        SettingsService::set('smsApiKey', 'test-api-key');
        SettingsService::set('smsSender', '09120000000');

        // Dedicated patterns for the mapped transitions + the generic one.
        SettingsService::set('smsPatternStarted', 'code-started');
        SettingsService::set('smsPatternReady', 'code-ready');
        SettingsService::set('smsPatternCancelled', 'code-cancelled');
        SettingsService::set('smsPatternStatusChange', 'code-status');
        // fcmEnabled stays off → dispatcher skips the push channel.

        Http::fake([
            'edge.ippanel.com/*' => Http::response([
                'meta' => ['status' => true, 'message' => 'ok'],
                'data' => ['message_outbox_ids' => [12345]],
            ], 200),
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'id' => 'usr-ns-cust',
            'phone' => '09120000001',
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function admin(): User
    {
        if ($this->admin instanceof User) {
            return $this->admin;
        }

        $admin = User::create([
            'id' => 'usr-ns-admin',
            'phone' => '09120000002',
            'name' => 'مدیر سیستم',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $this->admin = $admin;
    }

    /** A submitted (queued) request-mode order — the thing staff chips act on. */
    private function queuedOrder(User $user): Order
    {
        SettingsService::set('paymentsDisabled', '1');

        $resp = $this->actingAs($user)
            ->postJson('/api/orders', [
                'serviceId' => 'srv-ns',
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

    private function setOrderStatus(string $orderId, string $status): void
    {
        $this->actingAs($this->admin())
            ->patchJson("/api/orders/{$orderId}/status", ['status' => $status])
            ->assertStatus(200);
    }

    public function test_processing_chip_fires_the_order_started_notification(): void
    {
        $customer = $this->customer();
        $order = $this->queuedOrder($customer);

        // The UI chip «در حال انجام» → PATCH /orders/{id}/status.
        $this->setOrderStatus($order->id, Order::STATUS_PROCESSING);

        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);

        // In-app notification log row (source of the tracker's polling toasts).
        $this->assertDatabaseHas('notification_logs', [
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'type' => 'order_started',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);

        // Dedicated pattern SMS reached the customer.
        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone,
            'type' => 'order_started',
            'status' => 'sent',
        ]);

        // The generic status_change SMS is skipped — no double-texting.
        $this->assertDatabaseMissing('sms_logs', ['type' => 'status_change']);
    }

    public function test_ready_chip_fires_the_order_ready_notification(): void
    {
        $customer = $this->customer();
        $order = $this->queuedOrder($customer);

        $this->setOrderStatus($order->id, Order::STATUS_PROCESSING);
        $this->setOrderStatus($order->id, Order::STATUS_READY);

        $this->assertEquals(Order::STATUS_READY, $order->fresh()->status);

        $this->assertDatabaseHas('notification_logs', [
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'type' => 'order_ready',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone,
            'type' => 'order_ready',
            'status' => 'sent',
        ]);

        $this->assertDatabaseMissing('sms_logs', ['type' => 'status_change']);
    }

    public function test_cancel_chip_fires_the_cancelled_notification(): void
    {
        $customer = $this->customer();
        $order = $this->queuedOrder($customer);

        $this->setOrderStatus($order->id, Order::STATUS_CANCELLED);

        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);

        $this->assertDatabaseHas('notification_logs', [
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'type' => 'cancelled',
            'channel' => 'in_app',
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone,
            'type' => 'cancelled',
            'status' => 'sent',
        ]);
    }

    public function test_delivered_keeps_the_generic_status_change_sms(): void
    {
        $customer = $this->customer();
        $order = $this->queuedOrder($customer);

        $this->setOrderStatus($order->id, Order::STATUS_PROCESSING);
        $this->setOrderStatus($order->id, Order::STATUS_READY);
        $this->setOrderStatus($order->id, Order::STATUS_DELIVERED);

        $this->assertEquals(Order::STATUS_DELIVERED, $order->fresh()->status);

        // delivered has NO dedicated pattern — the generic SMS covers it.
        $this->assertDatabaseHas('sms_logs', [
            'phone' => $customer->phone,
            'type' => 'status_change',
            'status' => 'sent',
        ]);

        // …and no in-app notification row was invented for it.
        $this->assertDatabaseMissing('notification_logs', [
            'order_id' => $order->id,
            'type' => 'delivered',
        ]);
    }

    public function test_idempotent_reapply_sends_nothing_twice(): void
    {
        $customer = $this->customer();
        $order = $this->queuedOrder($customer);

        $this->setOrderStatus($order->id, Order::STATUS_PROCESSING);
        // Re-applying the same status is a no-op (early return) — the
        // duplicate chip tap must not re-notify the customer.
        $this->setOrderStatus($order->id, Order::STATUS_PROCESSING);

        $this->assertSame(
            1,
            DB::table('notification_logs')->where('order_id', $order->id)->where('type', 'order_started')->count()
        );
        $this->assertSame(
            1,
            DB::table('sms_logs')->where('phone', $customer->phone)->where('type', 'order_started')->count()
        );
    }
}
