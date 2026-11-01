<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hourly per-customer registration cap («هر مشتری در یک ساعت فقط ۳ سفارش»).
 *
 * OrderController::store counts the customer's orders created inside the
 * rolling last-60-minutes window regardless of status — a cancelled or
 * unpaid order was still a REGISTRATION, and the rule exists to stop
 * spam/duplicate tapping. The 4th attempt inside the window gets a 429 with
 * a Persian message + ORDER_HOURLY_LIMIT code (the SPA toasts the message).
 *
 * Request-mode orders (paymentsDisabled on) are used throughout so no
 * wallet balance or gateway is needed to place an order.
 */
class OrderHourlyLimitTest extends TestCase
{
    use RefreshDatabase;

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
            'base_price' => 50000,
            'is_active' => 1,
            'fields_json' => '[]',
        ]);

        // Request mode: orders are born submitted without any payment step.
        SettingsService::set('paymentsDisabled', '1');
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

    /** POST /api/orders as a request-mode order; returns the HTTP response. */
    private function postOrder(User $user)
    {
        return $this->actingAs($user)->postJson('/api/orders', [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
            'paymentMethod' => 'request',
        ]);
    }

    /* ───────── 1. three pass, the fourth inside the hour is rejected ───────── */

    public function test_a_customer_can_place_three_orders_but_the_fourth_within_the_hour_is_rejected(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $this->postOrder($customer)->assertStatus(201);
        }

        $resp = $this->postOrder($customer)->assertStatus(429);
        $resp->assertJsonPath('code', 'ORDER_HOURLY_LIMIT');
        $this->assertStringContainsString('۳ سفارش', (string) $resp->json('error'));
        $this->assertStringContainsString('یک ساعت', (string) $resp->json('error'));

        // The rejected request must not have persisted anything.
        $this->assertSame(3, Order::where('customer_id', $customer->id)->count());
    }

    /* ───────── 2. the window is rolling — old orders stop counting ───────── */

    public function test_orders_older_than_one_hour_do_not_count(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $this->postOrder($customer)->assertStatus(201);
        }

        // All three were registered two hours ago → outside the window.
        DB::table('orders')->where('customer_id', $customer->id)->update([
            'created_at' => now()->subHours(2)->toDateTimeString(),
        ]);

        $this->postOrder($customer)->assertStatus(201);
        $this->assertSame(4, Order::where('customer_id', $customer->id)->count());
    }

    /* ───────── 3. the cap is per customer ───────── */

    public function test_the_limit_is_per_customer_and_others_are_unaffected(): void
    {
        $a = $this->customer('usr-cust-a', '09120000001');
        $b = $this->customer('usr-cust-b', '09120000002');

        for ($i = 0; $i < 3; $i++) {
            $this->postOrder($a)->assertStatus(201);
        }
        $this->postOrder($a)->assertStatus(429);

        // Customer B has no history — their first order goes through.
        $this->postOrder($b)->assertStatus(201);
    }

    /* ───────── 4. cancelled orders still count (they were registered) ───────── */

    public function test_cancelled_orders_still_count_toward_the_limit(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $this->postOrder($customer)->assertStatus(201);
        }

        // Cancelling one of them does not free a slot: the registration
        // already happened, which is what the cap polices.
        Order::where('customer_id', $customer->id)
            ->where('status', Order::STATUS_SUBMITTED)
            ->first()
            ->update(['status' => Order::STATUS_CANCELLED]);

        $this->postOrder($customer)->assertStatus(429);
        $this->assertSame(3, Order::where('customer_id', $customer->id)->count());
    }

    /* ───────── 5. staff can view everything but can never register ─────────
     * The SPA gives admin/operator a READ-ONLY preview of every service
     * (service-page + order-form readOnly host); the server side of that
     * contract is this guard: staff POSTs to /api/orders are always 403. */

    public function test_staff_accounts_can_never_register_orders(): void
    {
        foreach (['admin', 'operator'] as $role) {
            $staff = User::create([
                'id' => 'usr-staff-'.$role,
                'phone' => '0912000099'.($role === 'admin' ? '1' : '2'),
                'name' => $role === 'admin' ? 'مدیر' : 'اپراتور',
                'role' => $role,
                'wallet_balance' => 0,
                'created_at' => now()->toIso8601String(),
            ]);
            if ($role === 'admin') $staff->assignRole('admin');
            else $staff->assignRole('operator');

            $this->actingAs($staff)
                ->postJson('/api/orders', [
                    'serviceId' => 'srv-test',
                    'formData' => [],
                    'urgency' => 'normal',
                    'deliveryMethod' => 'download',
                    'contactMethods' => ['app_chat'],
                ])
                ->assertStatus(403)
                ->assertJsonPath('error', 'ثبت سفارش تنها با حساب مشتری امکان‌پذیر است.');
        }

        // No order row was created by either staff account.
        $this->assertSame(0, Order::count());
    }
}
