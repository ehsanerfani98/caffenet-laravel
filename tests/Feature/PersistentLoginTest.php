<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * «یک‌بار ورود» — persistent login contract.
 *
 * Product rule: the user logs in ONCE and stays logged in until THEY log out.
 * Two mechanisms deliver it:
 *  1. A 30-day sliding session (config/session.php lifetime, was 120 min —
 *     the root cause of the reported "session clears after a while").
 *  2. Fortify's login pipeline runs with remember=true, so the session guard
 *     sets the remember_web_* cookie; once the session cookie is gone (30
 *     days idle, browser cleanup, GC) the guard silently re-authenticates
 *     from it on the next request — the user never sees a login screen.
 *
 * An explicit logout must still be a REAL logout: SessionGuard::logout()
 * cycles users.remember_token, so the old remember cookie stops matching.
 */
class PersistentLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '09121234567';

    protected function setUp(): void
    {
        parent::setUp();

        // JSON helpers (getJson/postJson) drop ALL cookies unless this is on —
        // the whole suite here is a cookie round-trip, so enable it once.
        $this->withCredentials();
    }

    private function enableSmsSimulator(): void
    {
        SettingsService::set('smsEnabled', '1');
        SettingsService::set('smsTestMode', '1');
    }

    /** Runs the real two-step OTP login through the Fortify pipeline.
     *  @return array{user: User, verify: \Illuminate\Testing\TestResponse} */
    private function loginOtp(): array
    {
        $code = $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])
            ->assertOk()
            ->json('testCode');
        $this->assertNotNull($code, 'simulator must surface the OTP code');

        $verify = $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('phone', self::PHONE);

        return ['user' => User::where('phone', self::PHONE)->firstOrFail(), 'verify' => $verify];
    }

    /** @return array{name:string, value:string} the remember_web_* cookie */
    private function rememberCookie($response): array
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                return ['name' => $cookie->getName(), 'value' => $cookie->getValue()];
            }
        }

        $this->fail('verify-otp must set the remember_web_* cookie');
    }

    public function test_login_sets_a_long_session_and_the_remember_cookie(): void
    {
        $this->assertSame(43200, (int) config('session.lifetime'), 'session must live 30 days, not the old 2 hours');

        $this->enableSmsSimulator();
        $result = $this->loginOtp();

        $this->assertNotEmpty($result['user']->fresh()->remember_token, 'guard must persist a remember token');
        $cookie = $this->rememberCookie($result['verify']);
        $this->assertNotSame('', $cookie['value']);
    }

    public function test_session_loss_is_silently_recovered_from_the_remember_cookie(): void
    {
        $this->enableSmsSimulator();
        $result = $this->loginOtp();
        $cookie = $this->rememberCookie($result['verify']);

        // The guard keeps the in-memory login across test requests AND the
        // array session store merges its attributes into cookie-less requests
        // (test-harness quirk). Wipe both so the recovery below really
        // resolves from the remember cookie alone.
        $this->app->make('session.store')->flush();
        $this->app->make('auth')->forgetGuards();

        // Simulate the worst case: the session cookie is gone AND the stored
        // session is gone (30+ days idle / GC / browser cleanup). The next
        // request carries ONLY the remember cookie — exactly what a returning
        // device looks like — and must land straight back as the same user.
        $this->withUnencryptedCookie($cookie['name'], $cookie['value'])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('phone', self::PHONE)
            ->assertJsonPath('id', $result['user']->id);
    }

    public function test_guest_without_remember_cookie_is_not_authenticated(): void
    {
        $this->enableSmsSimulator();
        $result = $this->loginOtp();
        $this->rememberCookie($result['verify']);

        // Wipe the in-memory login and the session store, then send no
        // cookies at all → the session guard has nothing to recover from and
        // /api/auth/me answers as a guest (401).
        $this->app->make('session.store')->flush();
        $this->app->make('auth')->forgetGuards();
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_explicit_logout_is_a_real_logout(): void
    {
        $this->enableSmsSimulator();
        $result = $this->loginOtp();
        $cookie = $this->rememberCookie($result['verify']);

        // Authenticate ONLY through the remember cookie (fresh guard and a
        // flushed session store), then log out — any request carrying it
        // restores the user first, that is the whole point.
        $this->app->make('session.store')->flush();
        $this->app->make('auth')->forgetGuards();
        $this->withUnencryptedCookie($cookie['name'], $cookie['value'])
            ->postJson('/api/auth/logout', [])
            ->assertOk();

        // SessionGuard::logout cycled users.remember_token — the old cookie
        // must now be dead: no session, no silent re-login.
        $this->app->make('session.store')->flush();
        $this->app->make('auth')->forgetGuards();
        $this->withUnencryptedCookie($cookie['name'], $cookie['value'])
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_sessions_table_row_loss_does_not_lock_the_user_out(): void
    {
        $this->enableSmsSimulator();
        $result = $this->loginOtp();
        $cookie = $this->rememberCookie($result['verify']);

        // DB-driver parity check (production runs SESSION_DRIVER=database):
        // even with the sessions table wiped, the remember cookie restores
        // the user on the next request.
        DB::table('sessions')->delete();
        $this->app->make('session.store')->flush();
        $this->app->make('auth')->forgetGuards();

        $this->withUnencryptedCookie($cookie['name'], $cookie['value'])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('phone', self::PHONE);
    }
}
