<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guests (before login) must be able to browse the whole service catalog —
 * categories, active services and public settings — while protected surfaces
 * (their orders) still require a session.
 */
class GuestCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            'id' => 'cat-1', 'title' => 'دسته', 'icon_name' => 'Printer',
        ]);

        DB::table('services')->insert([
            ['id' => 'srv-active', 'category_id' => 'cat-1', 'title' => 'خدمت فعال', 'base_price' => 1000, 'is_active' => 1, 'fields_json' => '[]'],
            ['id' => 'srv-inactive', 'category_id' => 'cat-1', 'title' => 'خدمت غیرفعال', 'base_price' => 1000, 'is_active' => 0, 'fields_json' => '[]'],
        ]);
    }

    public function test_guest_can_browse_categories_and_active_services(): void
    {
        $this->getJson('/api/categories')->assertOk();

        $this->getJson('/api/settings')->assertOk();

        $services = $this->getJson('/api/services')->assertOk()->json();

        // Only the active service is exposed to a guest.
        $this->assertCount(1, $services);
    }

    public function test_guest_cannot_access_orders_or_wallet_payments(): void
    {
        $this->getJson('/api/orders')->assertStatus(401);

        $this->postJson('/api/payments/request', ['purpose' => 'wallet', 'amount' => 50000])
            ->assertStatus(401);
    }
}
