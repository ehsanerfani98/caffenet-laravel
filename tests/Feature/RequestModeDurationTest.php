<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 37 — request-mode work duration + the «پاسخ نداد» strike endpoint.
 *
 * With «غیرفعال کردن همه پرداخت‌ها» on, the urgency picker is hidden and the
 * order's work duration is a random draw from the admin-configured
 * [requestOrderMinDuration, requestOrderMaxDuration] minutes window; the
 * queue estimator consumes those per-order durations. The strike endpoint
 * (PATCH /orders/{id}/no-response, staff only) arms the 15-minute clock that
 * the scheduler sweeps — three pushes to the back then auto-cancel.
 */
class RequestModeDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            'id' => 'cat-test', 'title' => 'دسته تست', 'icon_name' => 'grid', 'created_at' => now()->toIso8601String(),
        ]);
        DB::table('services')->insert([
            'id' => 'srv-test', 'category_id' => 'cat-test', 'title' => 'خدمت تست',
            'base_price' => 150000, 'is_active' => 1, 'fields_json' => '[]',
        ]);
    }

    public function test_duration_range_normalizes_persian_digits_and_swaps_bounds(): void
    {
        // Persian-digit input from the admin panel must parse.
        SettingsService::set('requestOrderMinDuration', '۳۰');
        SettingsService::set('requestOrderMaxDuration', '۶۰');
        $this->assertSame([30, 60], SettingsService::requestOrderDurationRange());

        // Bounds typed the other way around get swapped, never reversed.
        SettingsService::set('requestOrderMinDuration', '90');
        SettingsService::set('requestOrderMaxDuration', '30');
        $this->assertSame([30, 90], SettingsService::requestOrderDurationRange());

        // Empty/invalid falls back to the 30..60 defaults.
        SettingsService::set('requestOrderMinDuration', '');
        SettingsService::set('requestOrderMaxDuration', 'abc');
        $this->assertSame([30, 60], SettingsService::requestOrderDurationRange());
    }

    public function test_request_order_draws_duration_within_configured_window(): void
    {
        $customer = $this->customer('usr-cust-1', '09120000001');
        SettingsService::set('paymentsDisabled', '1');
        SettingsService::set('requestOrderMinDuration', '30');
        SettingsService::set('requestOrderMaxDuration', '60');

        $draws = [];
        for ($i = 0; $i < 5; $i++) {
            $resp = $this->actingAs($customer)->postJson('/api/orders', [
                'serviceId' => 'srv-test',
                'formData' => [],
                'urgency' => 'normal',
                'deliveryMethod' => 'download',
                'contactMethods' => ['app_chat'],
                'paymentMethod' => 'request',
            ]);
            $resp->assertStatus(201);

            $order = Order::find($resp->json('id'));
            $this->assertSame(Order::STATUS_SUBMITTED, $order->status);
            $this->assertGreaterThanOrEqual(30, (int) $order->estimated_duration_minutes);
            $this->assertLessThanOrEqual(60, (int) $order->estimated_duration_minutes);
            $this->assertDatabaseHas('queue_entries', ['order_id' => $order->id]);
            // ETA end reflects the drawn duration: start + duration.
            $this->assertNotNull($order->estimated_end_at);
            $draws[] = (int) $order->estimated_duration_minutes;
        }

        SettingsService::set('paymentsDisabled', '0');

        // A 30..60 random draw over 5 orders can legitimately repeat, but it
        // must never leave the window — asserted above per iteration.
        $this->assertCount(5, $draws);
    }

    public function test_no_response_endpoint_arms_deadline_for_staff_only(): void
    {
        $customer = $this->customer('usr-cust-2', '09120000002');
        $admin = $this->admin();

        SettingsService::set('paymentsDisabled', '1');
        $order = $this->createRequestOrder($customer);
        SettingsService::set('paymentsDisabled', '0');

        // Customer (non-staff) is rejected.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}/no-response")
            ->assertStatus(403);

        // Staff arms the clock: deadline set on BOTH the entry and the order.
        $before = now()->timestamp;
        $resp = $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/no-response")
            ->assertStatus(200);
        $resp->assertJsonPath('requeue_count', 0);

        $entry = QueueEntry::where('order_id', $order->id)->first();
        $this->assertNotNull($entry->no_response_deadline);
        $this->assertGreaterThanOrEqual($before + 14 * 60, strtotime((string) $entry->no_response_deadline));
        $this->assertSame($entry->no_response_deadline, $order->fresh()->no_response_deadline);

        // A non-waiting order cannot be armed.
        $order->status = Order::STATUS_PROCESSING;
        $order->save();
        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/no-response")
            ->assertStatus(422);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function customer(string $id = 'usr-cust-1', string $phone = '09120000001'): User
    {
        return User::create([
            'id' => $id, 'phone' => $phone, 'name' => 'مشتری تست',
            'role' => 'customer', 'wallet_balance' => 0, 'created_at' => now()->toIso8601String(),
        ]);
    }

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-admin-1', 'phone' => '09120000009', 'name' => 'مدیر سیستم',
            'role' => 'admin', 'wallet_balance' => 0, 'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function createRequestOrder(User $user): Order
    {
        $resp = $this->actingAs($user)->postJson('/api/orders', [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
            'paymentMethod' => 'request',
        ])->assertStatus(201);

        return Order::find($resp->json('id'));
    }
}
