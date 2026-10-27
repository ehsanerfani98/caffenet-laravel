<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working-hours gate («ساعت کاری») — end-to-end contract.
 *
 * The admin enables «بستن خودکار خارج از ساعت کاری» in «تنظیمات کسب‌وکار»
 * with a from/to hour window → WorkingHoursGate (appended to the web group
 * next to MaintenanceGate) replaces every page and every API with the
 * «خارج از ساعت کاری هستیم» screen for everyone EXCEPT admins whenever the
 * Tehran clock is outside [from, to). «از ۹ تا ۲۱» means [9, 21): open at
 * 09:00, closed at exactly 21:00. A from greater than to is an overnight
 * window (22→6). Survivors that must keep working even while the gate is
 * closed: admin sessions, the auth endpoints (so an admin whose session
 * expired can log back in), GET /api/settings (SPA boot), the external
 * gateway payment callbacks + the SPA result poll, the /?payment= return
 * shell, the /?entry=admin shell, health probes and the artisan runner.
 */
class WorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(); // unfreeze — a leaked freeze poisons other tests
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::create([
            'id' => 'usr-wh-admin',
            'phone' => '09120000021',
            'name' => 'ادمین ساعت کاری',
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
            'id' => 'usr-wh-cust',
            'phone' => '09120000022',
            'name' => 'مشتری ساعت کاری',
            'role' => 'customer',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $user->syncAccess('customer', null);

        return $user;
    }

    private function enable(int $from = 9, int $to = 21): void
    {
        SettingsService::set('workingHoursEnabled', '1');
        SettingsService::set('workingHoursFrom', (string) $from);
        SettingsService::set('workingHoursTo', (string) $to);
    }

    /** Freeze the clock at a Tehran wall-clock time (server tz is irrelevant). */
    private function at(string $tehranTime): void
    {
        Carbon::setTestNow(Carbon::parse($tehranTime, 'Asia/Tehran'));
    }

    public function test_gate_is_open_by_default_even_outside_hours(): void
    {
        $this->at('2026-10-27 23:30');
        $this->get('/')->assertOk()->assertSee('screen-host', false);
        $this->getJson('/api/categories')->assertOk();
    }

    public function test_open_inside_the_configured_window(): void
    {
        $this->enable();
        $this->at('2026-10-27 10:30');
        $this->get('/')->assertOk();
        $this->getJson('/api/categories')->assertOk();
    }

    public function test_closed_outside_the_window_with_the_exact_page_copy(): void
    {
        $this->enable();
        $this->at('2026-10-27 22:00');

        // Pages become the standalone closed screen — with the exact phrase
        // the owner asked for and the hours rendered in Persian digits.
        $this->get('/')
            ->assertStatus(503)
            ->assertSee('خارج از ساعت کاری هستیم', false)
            ->assertSee('از ساعت ۹ تا ساعت ۲۱ می‌باشد', false);
        $this->get('/track/tok123')->assertStatus(503);

        // APIs answer with the structured 503 the SPA bounce handler keys on.
        $this->getJson('/api/categories')
            ->assertStatus(503)
            ->assertJsonPath('workingHours', true)
            ->assertJsonPath('from', 9)
            ->assertJsonPath('to', 21);
    }

    public function test_window_boundaries_from_is_inclusive_and_to_is_exclusive(): void
    {
        $this->enable(9, 21);

        $this->at('2026-10-27 09:00');
        $this->getJson('/api/categories')->assertOk(); // «از ساعت ۹» → open AT 9

        $this->at('2026-10-27 20:59');
        $this->getJson('/api/categories')->assertOk();

        $this->at('2026-10-27 21:00');
        $this->getJson('/api/categories')->assertStatus(503); // «تا ساعت ۲۱» → closed AT 21
    }

    public function test_overnight_window_wraps_midnight(): void
    {
        $this->enable(22, 6);

        $this->at('2026-10-27 23:00');
        $this->getJson('/api/categories')->assertOk();

        $this->at('2026-10-27 05:59');
        $this->getJson('/api/categories')->assertOk();

        $this->at('2026-10-27 06:00');
        $this->getJson('/api/categories')->assertStatus(503);

        $this->at('2026-10-27 12:00');
        $this->getJson('/api/categories')->assertStatus(503);
    }

    public function test_equal_bounds_are_treated_as_an_empty_window(): void
    {
        // The settings endpoint refuses from == to; this guards against a
        // hand-edited row so a degenerate range can never open the app 24/7.
        $this->enable(9, 9);
        $this->at('2026-10-27 09:00');
        $this->getJson('/api/categories')->assertStatus(503);
    }

    public function test_garbage_hour_values_fall_back_to_defaults(): void
    {
        $this->enable();
        SettingsService::set('workingHoursFrom', 'abc');
        SettingsService::set('workingHoursTo', '۹۹'); // out of range, Persian digits

        $this->at('2026-10-27 10:00'); // inside the 9..21 defaults
        $this->getJson('/api/categories')->assertOk();

        $this->at('2026-10-27 22:00');
        $this->getJson('/api/categories')->assertStatus(503);
    }

    public function test_admin_keeps_the_full_app_while_the_gate_is_closed(): void
    {
        $this->actingAs($this->admin(), 'web');
        $this->enable();
        $this->at('2026-10-27 22:00');

        $this->get('/')->assertOk()->assertSee('screen-host', false);
        $this->getJson('/api/categories')->assertOk();
        $this->putJson('/api/settings', ['workingHoursEnabled' => false])->assertOk();
    }

    public function test_customer_with_a_live_session_is_still_blocked(): void
    {
        $this->actingAs($this->customer(), 'web');
        $this->enable();
        $this->at('2026-10-27 22:00');

        $this->get('/')->assertStatus(503);
        $this->getJson('/api/orders')->assertStatus(503);
    }

    public function test_recovery_and_payment_paths_survive_the_gate(): void
    {
        $this->admin(); // pre-provisioned for the OTP login flow below
        $this->enable();
        // OTP login needs a working SMS channel even in tests (the controller
        // 503s with «سامانه پیامک غیرفعال است» otherwise).
        SettingsService::set('smsEnabled', '1');
        SettingsService::set('smsTestMode', '1');
        $this->at('2026-10-27 22:00');

        // Public settings read (SPA boot) + health probe.
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/health')->assertOk();

        // Gateway callbacks + the SPA result poll must never be swallowed.
        $this->assertNotSame(503, $this->get('/api/payments/verify')->status());
        $this->assertNotSame(503, $this->get('/api/payments/status?authority=x')->status());

        // The gateway-return shell (?payment=…) loads so the customer sees
        // the result of a payment started before closing time.
        $this->get('/?payment=success&authority=x')->assertOk();

        // Auth path: an admin must be able to log in outside working hours…
        $code = $this->postJson('/api/auth/send-otp', ['phone' => '09120000021'])
            ->assertOk()
            ->json('testCode');
        $this->assertNotNull($code);

        // …via the ?entry=admin shell the closed page links to.
        $this->get('/?entry=admin')->assertOk()->assertSee('screen-host', false);
        $verify = $this->postJson('/api/auth/verify-otp', ['phone' => '09120000021', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('role', 'admin');
        $this->app->make('auth')->forgetGuards();
        foreach ($verify->headers->getCookies() as $cookie) {
            if ($cookie->getValue() !== null && $cookie->getValue() !== '' && ! $cookie->isCleared()) {
                $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
        }
        // The freshly logged-in admin rides through the closed gate.
        $this->get('/')->assertOk()->assertSee('screen-host', false);
    }

    public function test_business_settings_endpoint_exposes_typed_keys(): void
    {
        $this->enable(10, 22);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('workingHoursEnabled', true)
            ->assertJsonPath('workingHoursFrom', 10)
            ->assertJsonPath('workingHoursTo', 22);
    }

    public function test_business_settings_update_persists_and_validates_the_window(): void
    {
        $this->actingAs($this->admin(), 'web');

        // The exact payload openBusinessSettings() sends.
        $this->putJson('/api/settings', [
            'workingHoursEnabled' => true,
            'workingHoursFrom' => 9,
            'workingHoursTo' => 21,
        ])->assertOk();

        $this->assertDatabaseHas('settings', ['key' => 'workingHoursEnabled', 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => 'workingHoursFrom', 'value' => '9']);
        $this->assertDatabaseHas('settings', ['key' => 'workingHoursTo', 'value' => '21']);

        // Nothing must save when the window is broken.
        $this->putJson('/api/settings', ['workingHoursFrom' => 9, 'workingHoursTo' => 9])
            ->assertStatus(400);
        $this->putJson('/api/settings', ['workingHoursFrom' => 24])
            ->assertStatus(400);
        $this->assertDatabaseHas('settings', ['key' => 'workingHoursFrom', 'value' => '9']);
    }

    public function test_maintenance_gate_still_wins_when_both_gates_are_closed(): void
    {
        $this->enable();
        SettingsService::set('maintenanceMode', '1');
        $this->at('2026-10-27 22:00');

        // Registration order: MaintenanceGate runs first — the maintenance
        // page (the bigger hammer) is what guests see, not the hours page.
        $this->get('/')->assertStatus(503)->assertSee('سایت در حال تعمیر است', false);
    }
}
