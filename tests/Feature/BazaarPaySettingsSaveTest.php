<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the BazaarPay system-settings pipeline end-to-end:
 *
 *  1) The PUT /api/settings/system endpoint persists bazaarPay* keys —
 *     INCLUDING bazaarPayDirectType / bazaarPayDirectPeriod — correctly.
 *
 *  2) REGRESSION GUARD for the admin-panel bug: the settings sheet in
 *     admin-manage.js builds its payload from [data-set] inputs only and the
 *     directType/directPeriod segment selectors are NOT data-set inputs, so
 *     unless those two keys are explicitly appended to the payload they never
 *     reach the server and the DB keeps the defaults. This test reproduces
 *     both paths:
 *       a. the payload as the buggy UI sends it  → type/period stay default
 *       b. the payload as the UI SHOULD send it  → type/period persisted
 *
 *  3) bazaarPaySettings() snapshot honours the stored values, and
 *     directEnabled requires BOTH the gateway master switch and the direct
 *     switch to be on.
 */
class BazaarPaySettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-admin-bp',
            'phone' => '09120000009',
            'name' => 'ادمین تست',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->syncAccess('admin', null);

        return $admin;
    }

    /** The payload admin-manage.js builds from [data-set] + derived flags. */
    private function uiPayloadAsBuggyUiSendsIt(): array
    {
        return [
            'appUrl' => 'https://caffenet.example.com',
            'bazaarPayEnabled' => '1',
            'bazaarPayToken' => 'test-merchant-token',
            'bazaarPayDestination' => 'developers',
            'bazaarPayDirectEnabled' => '1',
            // ❌ bazaarPayDirectType / bazaarPayDirectPeriod are missing — the
            // segment() controls carry data-mode-val, not data-set, and the
            // save handler never copies state.directType/directPeriod into the
            // payload (unlike state.currency → payload.paymentCurrency).
            'bazaarPayDirectAmountLimit' => '5000000',
            'bazaarPayCallbackUrl' => '',
            'bazaarPayRialRate' => '10',
            'paymentTestMode' => '0',
        ];
    }

    public function test_server_endpoint_persists_direct_type_and_period_when_sent(): void
    {
        $admin = $this->admin();

        $payload = $this->uiPayloadAsBuggyUiSendsIt();
        $payload['bazaarPayDirectType'] = 'direct_debit';
        $payload['bazaarPayDirectPeriod'] = 'weekly';

        $this->actingAs($admin, 'web')
            ->putJson('/api/settings/system', ['settings' => $payload])
            ->assertStatus(200);

        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectType', 'value' => 'direct_debit']);
        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectPeriod', 'value' => 'weekly']);
        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectEnabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectAmountLimit', 'value' => '5000000']);

        $cfg = SettingsService::bazaarPaySettings();
        $this->assertTrue($cfg['enabled']);
        $this->assertTrue($cfg['directEnabled']);
        $this->assertSame('direct_debit', $cfg['directType']);
        $this->assertSame('weekly', $cfg['directPeriod']);
        $this->assertSame(5000000, $cfg['directAmountLimit']);
    }

    public function test_ui_payload_without_type_period_leaves_defaults_in_db(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->putJson('/api/settings/system', ['settings' => $this->uiPayloadAsBuggyUiSendsIt()])
            ->assertStatus(200);

        // Everything with a [data-set] input IS saved…
        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectEnabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'bazaarPayDirectAmountLimit', 'value' => '5000000']);

        // …but the two segment-driven keys never arrive → no rows at all.
        $this->assertDatabaseMissing('settings', ['key' => 'bazaarPayDirectType']);
        $this->assertDatabaseMissing('settings', ['key' => 'bazaarPayDirectPeriod']);

        // The snapshot therefore falls back to the defaults.
        $cfg = SettingsService::bazaarPaySettings();
        $this->assertSame('wallet', $cfg['directType']);
        $this->assertSame('monthly', $cfg['directPeriod']);
    }

    public function test_direct_enabled_requires_gateway_master_switch(): void
    {
        SettingsService::set('bazaarPayEnabled', '0');
        SettingsService::set('bazaarPayDirectEnabled', '1');

        $this->assertFalse(SettingsService::bazaarPaySettings()['directEnabled']);

        SettingsService::set('bazaarPayEnabled', '1');
        $this->assertTrue(SettingsService::bazaarPaySettings()['directEnabled']);
    }

    public function test_invalid_type_and_period_values_fall_back_to_defaults(): void
    {
        SettingsService::set('bazaarPayDirectType', 'credit_card');
        SettingsService::set('bazaarPayDirectPeriod', 'hourly');

        $cfg = SettingsService::bazaarPaySettings();
        $this->assertSame('wallet', $cfg['directType']);
        $this->assertSame('monthly', $cfg['directPeriod']);
    }

    public function test_callback_url_falls_back_to_app_url_when_empty(): void
    {
        SettingsService::set('appUrl', 'https://caffenet.example.com');
        SettingsService::set('bazaarPayCallbackUrl', '');

        $cfg = SettingsService::bazaarPaySettings();
        $this->assertSame('https://caffenet.example.com/api/payments', $cfg['callbackUrl']);

        SettingsService::set('bazaarPayCallbackUrl', 'https://custom.example.com/return');
        $cfg = SettingsService::bazaarPaySettings();
        $this->assertSame('https://custom.example.com/return', $cfg['callbackUrl']);
    }

    protected function tearDown(): void
    {
        DB::table('settings')->whereIn('key', [
            'bazaarPayEnabled', 'bazaarPayDirectEnabled', 'bazaarPayDirectType',
            'bazaarPayDirectPeriod', 'bazaarPayDirectAmountLimit', 'bazaarPayCallbackUrl',
        ])->delete();
        parent::tearDown();
    }
}
