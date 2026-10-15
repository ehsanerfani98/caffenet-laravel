<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the service icon color feature (mirrors the category icon color):
 *  - Migration adds icon_color + sort_order columns to services.
 *  - POST /api/services accepts iconColor + sortOrder.
 *  - GET /api/services returns the iconColor and sortOrder fields.
 *  - Validation rejects malformed hex colors.
 *  - Empty color is accepted (frontend falls back to default).
 */
class ServiceIconColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_endpoint_returns_color_and_sort_fields(): void
    {
        $this->makeCategory('cat-test', 'دسته تست');
        DB::table('services')->insert([
            'id' => 'srv-test',
            'category_id' => 'cat-test',
            'title' => 'خدمت تستی',
            'description' => '',
            'base_price' => 10000,
            'estimated_time' => '۱ ساعت',
            'icon' => 'printer',
            'icon_color' => '#1559de',
            'sort_order' => 5,
            'is_active' => true,
            'fields_json' => '[]',
            'pricing_formula' => '',
            'created_at' => now()->toIso8601String(),
        ]);

        $resp = $this->getJson('/api/services');
        $resp->assertOk();

        $services = $resp->json();
        $svc = collect($services)->firstWhere('id', 'srv-test');
        $this->assertNotNull($svc);
        $this->assertSame('#1559de', $svc['iconColor']);
        $this->assertSame(5, $svc['sortOrder']);
    }

    public function test_save_service_accepts_color_and_sort(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت تستی',
            'categoryId' => 'cat-test',
            'description' => '',
            'basePrice' => 10000,
            'estimatedTime' => '۱ ساعت',
            'icon' => 'printer',
            'iconColor' => '#a82cc7',
            'sortOrder' => 3,
            'isActive' => true,
            'fields' => [],
        ]);

        $resp->assertOk();
        $this->assertSame('#a82cc7', $resp->json('iconColor'));
        $this->assertSame(3, $resp->json('sortOrder'));
        $this->assertDatabaseHas('services', [
            'title' => 'خدمت تستی',
            'icon_color' => '#a82cc7',
            'sort_order' => 3,
        ]);
    }

    public function test_save_service_normalizes_color_without_hash(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت بدون هش',
            'categoryId' => 'cat-test',
            'icon' => 'printer',
            'iconColor' => 'a82cc7', // missing leading #
        ]);

        $resp->assertOk();
        $this->assertSame('#a82cc7', $resp->json('iconColor'));
    }

    public function test_save_service_rejects_bad_color(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت بد',
            'categoryId' => 'cat-test',
            'icon' => 'printer',
            'iconColor' => 'not-a-color',
        ]);

        $resp->assertStatus(400);
        $resp->assertJson(['error' => 'رنگ آیکون باید کد هگز ۶ رقمی باشد (مثل #a82cc7).']);
    }

    public function test_save_service_accepts_empty_color(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت بدون رنگ',
            'categoryId' => 'cat-test',
            'icon' => 'printer',
            'iconColor' => '',
        ]);

        $resp->assertOk();
        $this->assertSame('', $resp->json('iconColor'));
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function makeCategory(string $id, string $title): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'title' => $title,
            'icon_name' => 'printer',
            'icon_color' => '#1559de',
            'sort_order' => 0,
            'description' => '',
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function makeAdmin()
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);

        DB::table('users')->insert([
            'id' => 'usr-admin-svc',
            'phone' => '09120000099',
            'name' => 'Admin Svc',
            'role' => 'admin',
            'wallet_balance' => 0,
            'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);

        $user = \App\Models\User::find('usr-admin-svc');
        $user->syncAccess('admin', null);

        return $user->fresh();
    }
}
