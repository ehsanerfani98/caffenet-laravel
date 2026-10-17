<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the manual display order («ترتیب نمایش در دسته») of services:
 *  - GET /api/services returns services sorted by sort_order first, with
 *    created_at only as a tie-breaker — mirroring the categories endpoint,
 *    so changing the number in the admin service form reorders the services
 *    on the home page.
 *  - Services sharing the same sort_order keep their creation order.
 */
class ServiceOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(string $id, string $title): void
    {
        DB::table('categories')->insert([
            'id' => $id,
            'title' => $title,
            'icon_name' => 'printer',
            'sort_order' => 1,
            'description' => '',
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function makeService(string $id, string $catId, string $title, int $sortOrder, string $createdAt): void
    {
        DB::table('services')->insert([
            'id' => $id,
            'category_id' => $catId,
            'title' => $title,
            'description' => '',
            'base_price' => 1000,
            'estimated_time' => '',
            'icon' => 'printer',
            'icon_color' => '',
            'sort_order' => $sortOrder,
            'is_active' => true,
            'fields_json' => '[]',
            'pricing_formula' => '',
            'created_at' => $createdAt,
        ]);
    }

    public function test_services_are_returned_in_sort_order_not_creation_order(): void
    {
        $this->makeCategory('cat-1', 'دسته یک');
        $t = now()->toIso8601String();

        // Inserted in creation order a, b, c — but the admin set «ترتیب نمایش»
        // c=1, a=2, b=3, so the endpoint must return c, a, b.
        $this->makeService('srv-a', 'cat-1', 'الف', 2, $t);
        $this->makeService('srv-b', 'cat-1', 'ب', 3, $t);
        $this->makeService('srv-c', 'cat-1', 'پ', 1, $t);

        $resp = $this->getJson('/api/services');
        $resp->assertOk();

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertSame(['srv-c', 'srv-a', 'srv-b'], $ids);
    }

    public function test_services_with_equal_sort_order_keep_creation_order(): void
    {
        $this->makeCategory('cat-1', 'دسته یک');
        $this->makeService('srv-old', 'cat-1', 'قدیمی', 1, now()->subMinute()->toIso8601String());
        $this->makeService('srv-new', 'cat-1', 'جدید', 1, now()->toIso8601String());

        $resp = $this->getJson('/api/services');
        $resp->assertOk();

        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertSame(['srv-old', 'srv-new'], $ids);
    }
}
