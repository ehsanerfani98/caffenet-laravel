<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the "featured service" feature:
 *  - Migration adds is_featured + featured_sort columns to services.
 *  - POST /api/services accepts isFeatured + featuredSort.
 *  - GET /api/services returns the isFeatured + featuredSort fields.
 */
class ServiceFeaturedTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_endpoint_returns_featured_fields(): void
    {
        $this->makeCategory('cat-test', 'دسته تست');
        DB::table('services')->insert([
            'id' => 'srv-feat',
            'category_id' => 'cat-test',
            'title' => 'خدمت پرطرفدار',
            'description' => '',
            'base_price' => 10000,
            'estimated_time' => '۱ ساعت',
            'icon' => 'printer',
            'icon_color' => '#1559de',
            'sort_order' => 1,
            'is_active' => true,
            'is_featured' => true,
            'featured_sort' => 3,
            'fields_json' => '[]',
            'pricing_formula' => '',
            'created_at' => now()->toIso8601String(),
        ]);

        $resp = $this->getJson('/api/services');
        $resp->assertOk();

        $svc = collect($resp->json())->firstWhere('id', 'srv-feat');
        $this->assertNotNull($svc);
        $this->assertTrue($svc['isFeatured']);
        $this->assertSame(3, $svc['featuredSort']);
    }

    public function test_save_service_accepts_featured_flag(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت تستی',
            'categoryId' => 'cat-test',
            'icon' => 'printer',
            'isFeatured' => true,
            'featuredSort' => 5,
        ]);

        $resp->assertOk();
        $this->assertTrue($resp->json('isFeatured'));
        $this->assertSame(5, $resp->json('featuredSort'));
        $this->assertDatabaseHas('services', [
            'title' => 'خدمت تستی',
            'is_featured' => true,
            'featured_sort' => 5,
        ]);
    }

    public function test_save_service_defaults_featured_to_false(): void
    {
        $admin = $this->makeAdmin();
        $this->makeCategory('cat-test', 'دسته تست');

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'خدمت عادی',
            'categoryId' => 'cat-test',
            'icon' => 'printer',
            // isFeatured omitted entirely
        ]);

        $resp->assertOk();
        $this->assertFalse($resp->json('isFeatured'));
        $this->assertSame(0, $resp->json('featuredSort'));
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
            'id' => 'usr-admin-feat',
            'phone' => '09120000077',
            'name' => 'Admin Feat',
            'role' => 'admin',
            'wallet_balance' => 0,
            'is_blocked' => false,
            'created_at' => now()->toIso8601String(),
        ]);

        $user = \App\Models\User::find('usr-admin-feat');
        $user->syncAccess('admin', null);

        return $user->fresh();
    }
}
