<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for GET /api/dashboard/stats.
 *
 * The regression being pinned: the staff «صف کار» stat used to count with
 * whereNotIn(NON_QUEUE_STATUSES) — i.e. everything except
 * pending_payment/failed/refunded — so delivered and cancelled orders (and
 * ready ones too) were still counted as work-queue items. The stat must use
 * the canonical Order::QUEUE_STATUSES (submitted + processing) instead,
 * matching the operator screen's صف کار chip and the queue lifecycle
 * (deliver/cancel dequeue the order for good).
 */
class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_queue_count_excludes_terminal_and_finished_statuses(): void
    {
        $admin = $this->admin();

        // 2 submitted + 1 processing → the ONLY things in the work queue.
        $this->makeOrder(['status' => Order::STATUS_SUBMITTED]);
        $this->makeOrder(['status' => Order::STATUS_SUBMITTED]);
        $this->makeOrder(['status' => Order::STATUS_PROCESSING]);

        // Everything that must NOT be counted:
        $this->makeOrder(['status' => Order::STATUS_READY]);            // work done, awaiting handover
        $this->makeOrder(['status' => Order::STATUS_DELIVERED]);        // final state
        $this->makeOrder(['status' => Order::STATUS_DELIVERED]);
        $this->makeOrder(['status' => Order::STATUS_CANCELLED]);        // left the queue
        $this->makeOrder(['status' => Order::STATUS_PENDING_PAYMENT]);  // never entered the queue
        $this->makeOrder(['status' => Order::STATUS_FAILED]);
        $this->makeOrder(['status' => Order::STATUS_REFUNDED]);

        $resp = $this->actingAs($admin)->getJson('/api/dashboard/stats');

        $resp->assertOk();
        $resp->assertJsonPath('scope', 'staff');
        $resp->assertJsonPath('queueCount', 3);
    }

    public function test_staff_queue_count_is_zero_when_only_finished_orders_exist(): void
    {
        $admin = $this->admin();

        $this->makeOrder(['status' => Order::STATUS_DELIVERED]);
        $this->makeOrder(['status' => Order::STATUS_CANCELLED]);
        $this->makeOrder(['status' => Order::STATUS_READY]);

        $resp = $this->actingAs($admin)->getJson('/api/dashboard/stats');

        $resp->assertOk();
        $resp->assertJsonPath('queueCount', 0);
    }

    public function test_operator_role_sees_staff_scope_too(): void
    {
        $operator = User::create([
            'id' => 'usr-dst-op',
            'phone' => '09120000003',
            'name' => 'اپراتور',
            'role' => 'operator',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $operator->assignRole('operator');

        $this->makeOrder(['status' => Order::STATUS_SUBMITTED]);
        $this->makeOrder(['status' => Order::STATUS_DELIVERED]);

        $resp = $this->actingAs($operator)->getJson('/api/dashboard/stats');

        $resp->assertOk();
        $resp->assertJsonPath('scope', 'staff');
        $resp->assertJsonPath('queueCount', 1);
    }

    public function test_customer_scope_counts_own_orders_by_status(): void
    {
        $customer = $this->customer();
        $other = User::create([
            'id' => 'usr-dst-other',
            'phone' => '09120000009',
            'name' => 'مشتری دیگر',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);

        $this->makeOrder(['status' => Order::STATUS_SUBMITTED, 'customer_id' => $customer->id]);
        $this->makeOrder(['status' => Order::STATUS_DELIVERED, 'customer_id' => $customer->id]);
        // Another customer's order must not leak into the counts below.
        $this->makeOrder(['status' => Order::STATUS_DELIVERED, 'customer_id' => $other->id]);

        $resp = $this->actingAs($customer)->getJson('/api/dashboard/stats');

        $resp->assertOk();
        $resp->assertJsonPath('scope', 'customer');
        $resp->assertJsonPath('totalOrders', 2);
        $resp->assertJsonPath('statusCounts.'.Order::STATUS_SUBMITTED, 1);
        $resp->assertJsonPath('statusCounts.'.Order::STATUS_DELIVERED, 1);
        $resp->assertJsonPath('statusCounts.'.Order::STATUS_CANCELLED, 0);
        $resp->assertJsonPath('walletBalance', 5000);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function admin(): User
    {
        static $admin = null;
        if ($admin) {
            return $admin;
        }

        $admin = User::create([
            'id' => 'usr-dst-admin',
            'phone' => '09120000001',
            'name' => 'مدیر سیستم',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function customer(): User
    {
        static $customer = null;
        if ($customer) {
            return $customer;
        }

        $customer = User::create([
            'id' => 'usr-dst-cust',
            'phone' => '09120000002',
            'name' => 'مشتری',
            'role' => 'customer',
            'wallet_balance' => 5000,
            'created_at' => now()->toIso8601String(),
        ]);

        return $customer;
    }

    private function makeOrder(array $overrides = []): Order
    {
        $order = new Order();
        $order->id = 'ord-'.Str::uuid();
        $order->order_number = 'CN-'.random_int(1000, 9999);
        $order->service_id = 'svc-test';
        $order->service_title = 'Test Service';
        $order->customer_id = $overrides['customer_id'] ?? 'usr-dst-cust';
        $order->customer_phone = '09120000000';
        $order->customer_name = 'Tester';
        $order->status = $overrides['status'] ?? Order::STATUS_SUBMITTED;
        $order->urgency = 'normal';
        $order->queue_type = 'normal';
        $order->priority_level = 5;
        $order->form_data_json = '[]';
        $order->customer_files_json = '[]';
        $order->result_files_json = '[]';
        $order->total_price = 1000;
        $order->paid_amount = 1000;
        $order->payment_method = 'wallet';
        $order->payment_tracking_code = '';
        $order->track_token = Str::random(32);
        $order->created_at = now()->toIso8601String();
        $order->updated_at = now()->toIso8601String();
        $order->save();

        return $order;
    }
}
