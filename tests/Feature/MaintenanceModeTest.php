<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance gate («حالت تعمیر و نگه‌داری») — end-to-end contract.
 *
 * The admin flips maintenanceMode in «تنظیمات سیستمی» → MaintenanceGate
 * (appended to the web group, which also hosts /api/* here) replaces every
 * page and every API with the «سایت در حال تعمیر است» screen for everyone
 * EXCEPT admins. Survivors that must keep working even while the gate is
 * closed: admin sessions, the auth endpoints (so an admin whose session
 * expired can log back in), GET /api/settings (SPA boot), the external
 * gateway payment callbacks, health probes, the artisan runner, and the
 * /?entry=admin shell used by the «ورود مدیر» link on the maintenance page.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-mnt-admin',
            'phone' => '09120000011',
            'name' => 'ادمین تعمیر',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->syncAccess('admin', null);

        return $admin;
    }

    private function customer(): User
    {
        $user = User::create([
            'id' => 'usr-mnt-cust',
            'phone' => '09120000012',
            'name' => 'مشتری تعمیر',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $user->syncAccess('customer', null);

        return $user;
    }

    private function turnOn(array $overrides = []): void
    {
        SettingsService::set('maintenanceMode', '1');
        foreach ($overrides as $key => $value) {
            SettingsService::set($key, (string) $value);
        }
    }

    /** Replays every Set-Cookie of the given response onto the next request —
     *  the Laravel test client does not persist cookies automatically. All
     *  wire values are sent unencrypted (the server-side middleware does the
     *  decrypting) — exactly what a real browser round-trip looks like. */
    private function replayCookies($response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null && $cookie->getValue() !== '' && ! $cookie->isCleared()) {
                $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
        }
    }

    public function test_gate_is_open_by_default(): void
    {
        $this->get('/')->assertOk()->assertSee('screen-host', false);
        $this->getJson('/api/categories')->assertOk();
        $this->get('/track/tok123')->assertOk();
    }

    public function test_gate_closes_pages_and_api_for_guests(): void
    {
        $this->turnOn();

        // SPA shell and the tracking page both become the maintenance screen.
        $this->get('/')
            ->assertStatus(503)
            ->assertSee('سایت در حال تعمیر است', false)
            ->assertSee('maintenance-hero.png', false);
        $this->get('/track/tok123')->assertStatus(503)->assertSee('سایت در حال تعمیر است', false);

        // APIs answer with the structured 503 the SPA bounce handler keys on.
        $this->getJson('/api/categories')
            ->assertStatus(503)
            ->assertJsonPath('maintenance', true);
        $this->getJson('/api/orders')->assertStatus(503);
    }

    public function test_eta_card_reflects_the_setting_and_can_be_hidden(): void
    {
        $this->turnOn(['maintenanceEta' => 'حدود یک ساعت دیگر']);
        $this->get('/')->assertStatus(503)->assertSee('حدود یک ساعت دیگر', false);

        $this->turnOn(['maintenanceEta' => '']);
        $this->get('/')->assertStatus(503)->assertDontSee('زمان تقریبی بازگشت', false);
    }

    public function test_admin_keeps_the_full_site_while_the_gate_is_closed(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->turnOn();

        $this->get('/')->assertOk()->assertSee('screen-host', false);
        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/settings/system')->assertOk();
    }

    public function test_customer_with_a_live_session_is_still_blocked(): void
    {
        $this->actingAs($this->customer(), 'web');
        $this->turnOn();

        $this->get('/')->assertStatus(503);
        $this->getJson('/api/orders')->assertStatus(503);
    }

    public function test_recovery_paths_survive_the_gate(): void
    {
        // Pre-provision the admin so findOrCreateUser() finds an admin-role
        // user for this phone instead of minting a fresh customer.
        $this->admin();
        $this->turnOn([
            'smsEnabled' => '1',
            'smsTestMode' => '1',
        ]);

        // Public settings read (SPA boot).
        $this->getJson('/api/settings')->assertOk();
        // Health probe.
        $this->getJson('/api/health')->assertOk();

        // Auth path: an admin must be able to log in during maintenance.
        $code = $this->postJson('/api/auth/send-otp', ['phone' => '09120000011'])
            ->assertOk()
            ->json('testCode');
        $this->assertNotNull($code);

        // The ?entry=admin shell (the «ورود مدیر» link target) stays open…
        $this->get('/?entry=admin')->assertOk()->assertSee('screen-host', false);
        // …so the admin that just logged in lands on the working panel.
        $verify = $this->postJson('/api/auth/verify-otp', ['phone' => '09120000011', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('role', 'admin');
        // The guard keeps the in-memory login across test requests — forget
        // it so the next request really resolves from the replayed cookies.
        $this->app->make('auth')->forgetGuards();
        $this->replayCookies($verify);
        $this->get('/')->assertOk()->assertSee('screen-host', false);
    }

    public function test_gateway_callback_bypasses_the_gate(): void
    {
        $this->turnOn();

        $response = $this->get('/api/payments/verify');
        $this->assertNotSame(503, $response->status(), 'gateway callbacks must never be swallowed by the gate');
    }

    public function test_switch_persists_through_the_system_settings_endpoint(): void
    {
        $this->actingAs($this->admin(), 'web');

        // The exact payload openSystemSettings() sends when the admin flips
        // the switch and presses «ذخیره تنظیمات سیستمی».
        $this->putJson('/api/settings/system', [
            'settings' => [
                'maintenanceMode' => '1',
                'maintenanceEta' => '۲ ساعت دیگر',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'maintenanceMode', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'maintenanceEta', 'value' => '۲ ساعت دیگر']);

        // Drop the actingAs identity — the guard instance persists across
        // test requests, and without this the "guest" request below would
        // still ride the admin's identity and bypass the gate.
        $this->app->make('auth')->forgetGuards();

        // The very next guest request is already gated — no restart, no cache.
        $this->getJson('/api/categories')->assertStatus(503);
    }
}
