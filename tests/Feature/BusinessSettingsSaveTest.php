<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the «تنظیمات کسب‌وکار» (business settings) pipeline end-to-end:
 * PUT /api/settings with the EXACT payload openBusinessSettings()
 * (admin-manage.js) builds must persist every key, and GET /api/settings
 * must hand the saved values back to the clients.
 *
 * Regression context: the admin reported "تنظیمات ذخیره نمیشه". The server
 * pipeline was correct — the CLIENT never re-fetched /api/settings after the
 * PUT (saveSettings/updateSystemSettings now call syncWithBackend), so every
 * reader of state.settings — the settings sheet itself, the order form's
 * bazaarPay.directEnabled gate, the wallet page's gateway choice — kept
 * showing pre-save values for the whole session. This test pins the SERVER
 * half of that flow: what the client sends must come back on the next read.
 *
 * Also pins that walletTopUpPresets work with EMPTY SKUs — with the BazaarPay
 * gateway active, top-ups are per-amount checkouts and no Cafe Bazaar SKU is
 * required (SKU only matters for the legacy in-Bazaar-app IAP deep-link flow).
 */
class BusinessSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-admin-biz',
            'phone' => '09120000010',
            'name' => 'ادمین تست',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->syncAccess('admin', null);

        return $admin;
    }

    /** The exact payload openBusinessSettings() sends on «ذخیره تنظیمات». */
    private function uiPayload(): array
    {
        return [
            'siteTitle' => 'کافی‌نت احسان',
            'coffeenetAddress' => 'تهران، خیابان آزمایشی',
            'coffeenetPhone' => '02112345678',
            'workingHours' => '۹ تا ۲۱',
            'fileRetentionDays' => 30,
            'urgentPriceMultiplier' => 1.5,
            'immediatePriceMultiplier' => 1.8,
            'courierCost' => 40000,
            'walletTopUpPresets' => [
                ['amount' => 50000, 'sku' => ''],
                ['amount' => 200000, 'sku' => ''],
            ],
            'enableCoupons' => false,
            'termsText' => 'قوانین نمونه',
        ];
    }

    public function test_business_put_persists_every_key_and_get_returns_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->putJson('/api/settings', $this->uiPayload())
            ->assertStatus(200);

        // Stored canonically in the settings table…
        $this->assertDatabaseHas('settings', ['key' => 'siteTitle', 'value' => 'کافی‌نت احسان']);
        $this->assertDatabaseHas('settings', ['key' => 'workingHours', 'value' => '۹ تا ۲۱']);
        $this->assertDatabaseHas('settings', ['key' => 'fileRetentionDays', 'value' => '30']);
        $this->assertDatabaseHas('settings', ['key' => 'urgentPriceMultiplier', 'value' => '1.5']);
        $this->assertDatabaseHas('settings', ['key' => 'courierCost', 'value' => '40000']);
        $this->assertDatabaseHas('settings', ['key' => 'enableCoupons', 'value' => '0']); // bool → '0'
        $this->assertDatabaseHas('settings', ['key' => 'termsText', 'value' => 'قوانین نمونه']);

        // …and served back exactly the way the clients consume it.
        $res = $this->getJson('/api/settings')->assertStatus(200)->json();
        $this->assertSame('کافی‌نت احسان', $res['siteTitle']);
        $this->assertSame(30, $res['fileRetentionDays']);
        $this->assertSame(1.5, $res['urgentPriceMultiplier']);
        $this->assertSame(40000, $res['courierCost']);
        $this->assertFalse($res['enableCoupons']);
        $this->assertSame('قوانین نمونه', $res['termsText']);
        $this->assertSame(
            [['amount' => 50000, 'sku' => ''], ['amount' => 200000, 'sku' => '']],
            $res['walletTopUpPresets'],
            'presets survive with EMPTY SKUs (BazaarPay needs none)'
        );
    }

    public function test_partial_payload_only_touches_the_keys_it_sends(): void
    {
        $admin = $this->admin();

        SettingsService::set('siteTitle', 'عنوان قبلی');

        $this->actingAs($admin, 'web')
            ->putJson('/api/settings', ['workingHours' => '۸ تا ۱۶'])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'workingHours', 'value' => '۸ تا ۱۶']);
        $this->assertSame('عنوان قبلی', SettingsService::raw('siteTitle'), 'untouched key keeps its value');
    }

    public function test_settings_put_is_forbidden_without_permission(): void
    {
        $customer = User::create([
            'id' => 'usr-cust-biz',
            'phone' => '09120000011',
            'name' => 'مشتری تست',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);

        $this->actingAs($customer, 'web')
            ->putJson('/api/settings', ['siteTitle' => 'هک'])
            ->assertStatus(403);
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [
            'siteTitle', 'coffeenetAddress', 'coffeenetPhone', 'workingHours',
            'fileRetentionDays', 'urgentPriceMultiplier', 'immediatePriceMultiplier',
            'courierCost', 'walletTopUpPresets', 'enableCoupons', 'termsText',
        ])->delete();
        parent::tearDown();
    }
}
