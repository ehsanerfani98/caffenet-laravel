<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 38 — exactly ONE rating + review submission per order.
 *
 * The customer composes (stars + comment) and finalises with a single
 * PATCH {rating, reviewComment}. Once review_comment is no longer NULL the
 * order is locked: any further rating/reviewComment payload gets 409 and
 * leaves the stored values untouched. Staff-only fields (internalNote,
 * assignedOperatorId) keep working on locked orders.
 */
class OrderReviewOnceTest extends TestCase
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

        // Request-mode (payments disabled) — the same setup Task 37 tests use
        // so POST /api/orders submits straight into the queue.
        SettingsService::set('paymentsDisabled', '1');
    }

    public function test_owner_submits_rating_and_review_once_then_locked(): void
    {
        $customer = $this->customer('usr-cust-1', '09120000001');
        $order = $this->createOrder($customer);

        // The one-shot submission: rating + comment together.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", [
                'rating' => 5,
                'reviewComment' => 'عالی بود، ممنون',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'rating' => 5, 'review_comment' => 'عالی بود، ممنون',
        ]);

        // A second attempt — same values or not — must be rejected with 409.
        $resp = $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", [
                'rating' => 2,
                'reviewComment' => 'بگذار تغییرش بدهم',
            ]);
        $resp->assertStatus(409);
        $this->assertSame(
            'برای این سفارش قبلاً امتیاز و نظر ثبت شده است؛ هر سفارش فقط یکبار قابل امتیازدهی است.',
            $resp->json('error')
        );

        // Values are untouched after the rejected attempt.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'rating' => 5, 'review_comment' => 'عالی بود، ممنون',
        ]);

        // Even a rating-only patch is now locked.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 1])
            ->assertStatus(409);
    }

    public function test_rating_only_patch_composes_without_locking_or_wiping(): void
    {
        $customer = $this->customer('usr-cust-2', '09120000002');
        $order = $this->createOrder($customer);

        // Compose phase: a rating-only patch is allowed (API keeps supporting
        // the legacy shape) and must NOT touch the still-NULL comment.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 4])
            ->assertStatus(200);

        $order->refresh();
        $this->assertSame(4, (int) $order->rating);
        $this->assertNull($order->review_comment);

        // Final submission locks the order.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", [
                'rating' => 5,
                'reviewComment' => 'نهایی',
            ])
            ->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'review_comment' => 'نهایی']);

        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['reviewComment' => 'دوباره'])
            ->assertStatus(409);
    }

    public function test_invalid_rating_is_rejected_before_submission(): void
    {
        $customer = $this->customer('usr-cust-3', '09120000003');
        $order = $this->createOrder($customer);

        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 9, 'reviewComment' => 'x'])
            ->assertStatus(400);

        $order->refresh();
        $this->assertNull($order->rating);
        $this->assertNull($order->review_comment);

        // Still unlocked: a valid submission afterwards succeeds.
        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 3, 'reviewComment' => 'معمولی'])
            ->assertStatus(200);
    }

    public function test_non_owner_cannot_rate(): void
    {
        $customer = $this->customer('usr-cust-4', '09120000004');
        $other = $this->customer('usr-cust-5', '09120000005');
        $order = $this->createOrder($customer);

        $this->actingAs($other)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 1, 'reviewComment' => 'x'])
            ->assertStatus(403);

        $order->refresh();
        $this->assertNull($order->rating);
    }

    public function test_staff_note_still_works_on_locked_order(): void
    {
        $customer = $this->customer('usr-cust-6', '09120000006');
        $admin = $this->admin();
        $order = $this->createOrder($customer);

        $this->actingAs($customer)
            ->patchJson("/api/orders/{$order->id}", ['rating' => 5, 'reviewComment' => 'قفل شود'])
            ->assertStatus(200);

        // internalNote / assignment are staff-only fields — unaffected by the
        // review lock.
        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}", ['internalNote' => 'پیگیری داخلی'])
            ->assertStatus(200);

        $order->refresh();
        $this->assertSame('پیگیری داخلی', $order->internal_note);
        $this->assertSame('قفل شود', $order->review_comment);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function customer(string $id, string $phone): User
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

    private function createOrder(User $user): Order
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
