<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «کارمزد کافی‌نت / کارمزد سامانه» + «اطلاعیه سرویس» — the three manually
 * added services columns (benefit, notice_type, notice_text) now have a
 * migration and a full API contract:
 *
 *  - GET  /api/services exposes benefit / noticeType / noticeText.
 *  - POST /api/services (admin) persists them; bodies WITHOUT the keys
 *    (older admin builds from the PWA cache) never wipe existing values.
 *  - notice_type = 'lock'  → order creation is rejected with the notice text
 *    (mirrors the red popup the client shows instead of the order form).
 *  - notice_type = 'normal' → the service still takes orders normally.
 */
class ServiceBenefitNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            'id' => 'cat-test', 'title' => 'دسته تست', 'icon_name' => 'grid', 'created_at' => now()->toIso8601String(),
        ]);
    }

    private function insertService(array $overrides = []): void
    {
        DB::table('services')->insert(array_merge([
            'id' => 'srv-test', 'category_id' => 'cat-test', 'title' => 'خدمت تست',
            'base_price' => 99000, 'is_active' => 1, 'fields_json' => '[]',
        ], $overrides));
    }

    private function customer(): User
    {
        return User::create([
            'id' => 'usr-cust-1', 'phone' => '09120000001', 'name' => 'مشتری تست',
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

    public function test_migration_creates_the_three_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('services', 'benefit'));
        $this->assertTrue(Schema::hasColumn('services', 'notice_type'));
        $this->assertTrue(Schema::hasColumn('services', 'notice_text'));
    }

    public function test_services_endpoint_returns_benefit_and_notice_fields(): void
    {
        $this->insertService([
            'base_price' => 99000, 'benefit' => 67000,
            'notice_type' => 'lock', 'notice_text' => 'این خدمت موقتاً بسته است.',
        ]);

        $resp = $this->getJson('/api/services');
        $resp->assertOk();

        $svc = collect($resp->json())->firstWhere('id', 'srv-test');
        $this->assertNotNull($svc);
        $this->assertSame(99000, $svc['basePrice']);
        $this->assertSame(67000, $svc['benefit']);
        $this->assertSame('lock', $svc['noticeType']);
        $this->assertSame('این خدمت موقتاً بسته است.', $svc['noticeText']);
    }

    public function test_services_endpoint_returns_nulls_for_unconfigured_rows(): void
    {
        $this->insertService();

        $svc = collect($this->getJson('/api/services')->json())->firstWhere('id', 'srv-test');
        $this->assertNull($svc['benefit']);
        $this->assertNull($svc['noticeType']);
        $this->assertNull($svc['noticeText']);
    }

    public function test_save_service_persists_benefit_and_notice(): void
    {
        $admin = $this->admin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'title' => 'تاییدیه کدپستی',
            'categoryId' => 'cat-test',
            'basePrice' => 99000,
            'benefit' => 67000,
            'noticeType' => 'normal',
            'noticeText' => "ساعات پاسخگویی: ۹ تا ۲۱\nلطفاً پیش از ثبت سفارش بخوانید.",
        ]);

        $resp->assertOk();
        $this->assertSame(67000, $resp->json('benefit'));
        $this->assertSame('normal', $resp->json('noticeType'));

        $row = DB::table('services')->where('title', 'تاییدیه کدپستی')->first();
        $this->assertSame(67000, (int) $row->benefit);
        $this->assertSame('normal', $row->notice_type);
        $this->assertStringContainsString('لطفاً پیش از ثبت سفارش بخوانید.', (string) $row->notice_text);
    }

    public function test_save_service_without_notice_keys_preserves_existing_values(): void
    {
        // Older admin builds (PWA cache) post bodies without the new keys —
        // saving from them must never wipe values configured by hand.
        $this->insertService([
            'benefit' => 67000, 'notice_type' => 'lock', 'notice_text' => 'متن اطلاعیه',
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'id' => 'srv-test',
            'title' => 'خدمت تست', // unchanged fields — a routine edit
            'categoryId' => 'cat-test',
            'basePrice' => 99000,
        ]);
        $resp->assertOk();

        $row = DB::table('services')->where('id', 'srv-test')->first();
        $this->assertSame(67000, (int) $row->benefit);
        $this->assertSame('lock', $row->notice_type);
        $this->assertSame('متن اطلاعیه', $row->notice_text);
    }

    public function test_save_service_with_empty_type_clears_the_notice(): void
    {
        $this->insertService([
            'benefit' => 67000, 'notice_type' => 'lock', 'notice_text' => 'متن اطلاعیه',
        ]);
        $admin = $this->admin();

        $resp = $this->actingAs($admin, 'web')->postJson('/api/services', [
            'id' => 'srv-test',
            'title' => 'خدمت تست',
            'categoryId' => 'cat-test',
            'noticeType' => '', // back to the normal flow
            'noticeText' => 'بی‌معنا وقتی نوع خالی است',
        ]);
        $resp->assertOk();

        $row = DB::table('services')->where('id', 'srv-test')->first();
        $this->assertNull($row->notice_type);
        $this->assertNull($row->notice_text);
        // benefit is absent from the body → preserved
        $this->assertSame(67000, (int) $row->benefit);
    }

    public function test_locked_service_rejects_order_creation_with_the_notice_text(): void
    {
        $this->insertService([
            'notice_type' => 'lock', 'notice_text' => 'سرویس در حال حاضر غیرفعال است.',
        ]);
        $customer = $this->customer();

        $resp = $this->actingAs($customer)->postJson('/api/orders', [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
        ]);

        $resp->assertStatus(400);
        $this->assertSame('سرویس در حال حاضر غیرفعال است.', $resp->json('error'));
        $this->assertSame(0, Order::count());
    }

    public function test_locked_service_without_text_falls_back_to_a_default_message(): void
    {
        $this->insertService(['notice_type' => 'lock']);
        $customer = $this->customer();

        $resp = $this->actingAs($customer)->postJson('/api/orders', [
            'serviceId' => 'srv-test',
            'formData' => [],
            'contactMethods' => ['app_chat'],
        ]);

        $resp->assertStatus(400);
        $this->assertSame('این خدمت تا اطلاع ثانوی قابل ثبت سفارش نیست.', $resp->json('error'));
    }

    public function test_normal_notice_service_still_takes_orders(): void
    {
        SettingsService::set('paymentsDisabled', '1');

        $this->insertService([
            'notice_type' => 'normal', 'notice_text' => 'لطفاً پیش از ثبت سفارش بخوانید.',
        ]);
        $customer = $this->customer();

        $resp = $this->actingAs($customer)->postJson('/api/orders', [
            'serviceId' => 'srv-test',
            'formData' => [],
            'urgency' => 'normal',
            'deliveryMethod' => 'download',
            'contactMethods' => ['app_chat'],
            'paymentMethod' => 'request',
        ]);

        $resp->assertStatus(201);
        $this->assertSame(1, Order::count());
    }
}
