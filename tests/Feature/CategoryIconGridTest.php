<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the colorful category icon grid feature:
 *  - Migration adds icon_color + sort_order columns.
 *  - POST /api/categories accepts iconColor + sortOrder.
 *  - GET /api/categories returns categories sorted by sort_order.
 *  - Validation rejects malformed hex colors.
 */
class CategoryIconGridTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_endpoint_returns_color_and_sort_fields(): void
    {
        // Seed two categories out of order — the endpoint must return them by sort_order.
        DB::table('categories')->insert([
            ['id' => 'cat-a', 'title' => 'دسته الف', 'icon_name' => 'printer', 'icon_color' => '#1559de', 'sort_order' => 2, 'description' => '', 'created_at' => now()->toIso8601String()],
            ['id' => 'cat-b', 'title' => 'دسته ب', 'icon_name' => 'wallet', 'icon_color' => '#0ca67d', 'sort_order' => 1, 'description' => '', 'created_at' => now()->toIso8601String()],
        ]);

        $resp = $this->getJson('/api/categories');
        $resp->assertOk();

        $cats = $resp->json();
        $this->assertCount(2, $cats);
        // sort_order 1 (cat-b) must come before sort_order 2 (cat-a).
        $this->assertSame('cat-b', $cats[0]['id']);
        $this->assertSame('cat-a', $cats[1]['id']);

        // New fields are present.
        $this->assertArrayHasKey('iconColor', $cats[0]);
        $this->assertArrayHasKey('sortOrder', $cats[0]);
        $this->assertSame('#0ca67d', $cats[0]['iconColor']);
        $this->assertSame(1, $cats[0]['sortOrder']);
    }

    public function test_save_category_accepts_color_and_sort(): void
    {
        // Login as admin (the route requires services.create|services.edit|services.manage).
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/categories', [
            'title' => 'دسته تستی',
            'iconName' => 'printer',
            'iconColor' => '#a82cc7',
            'sortOrder' => 5,
            'description' => 'توضیح تستی',
        ]);

        $resp->assertOk();
        $this->assertSame('#a82cc7', $resp->json('iconColor'));
        $this->assertSame(5, $resp->json('sortOrder'));
        $this->assertDatabaseHas('categories', [
            'title' => 'دسته تستی',
            'icon_color' => '#a82cc7',
            'sort_order' => 5,
        ]);
    }

    public function test_save_category_normalizes_color_without_hash(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/categories', [
            'title' => 'دسته بدون هش',
            'iconName' => 'printer',
            'iconColor' => 'a82cc7', // missing leading #
        ]);

        $resp->assertOk();
        $this->assertSame('#a82cc7', $resp->json('iconColor'));
    }

    public function test_save_category_rejects_bad_color(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/categories', [
            'title' => 'دسته بد',
            'iconName' => 'printer',
            'iconColor' => 'not-a-color',
        ]);

        $resp->assertStatus(400);
        $resp->assertJson(['error' => 'رنگ آیکون باید کد هگز ۶ رقمی باشد (مثل #a82cc7).']);
    }

    public function test_save_category_accepts_empty_color(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/categories', [
            'title' => 'دسته بدون رنگ',
            'iconName' => 'printer',
            'iconColor' => '',
        ]);

        $resp->assertOk();
        $this->assertSame('', $resp->json('iconColor'));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function makeAdmin()
    {
        // Seed spatie roles + an admin user.
        $this->seed(\Database\Seeders\RoleSeeder::class);

        DB::table('users')->insert([
            'id' => 'usr-admin-test',
            'phone' => '09120000001',
            'name' => 'Admin Test',
            'role' => 'admin',
            'wallet_balance' => 0,
            'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);

        $user = \App\Models\User::find('usr-admin-test');
        $user->syncAccess('admin', null);

        return $user->fresh();
    }
}
