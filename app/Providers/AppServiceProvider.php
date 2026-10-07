<?php

namespace App\Providers;

use App\Http\Responses\FortifyLockoutResponse;
use App\Http\Responses\FortifyLoginResponse;
use App\Listeners\FulfillBazaarPurchase;
use App\Models\User;
use App\Services\AuthService;
use App\Services\BazaarIapSettingsResolver;
use Caffenet\BazaarIap\Contracts\BazaarIapSettings;
use Caffenet\BazaarIap\Events\PurchaseConsumed;
use Caffenet\BazaarIap\Events\PurchaseFailed;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LockoutResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bazaar IAP credentials come from the database-backed settings panel
        // (system scope). Binding the package's settings contract here makes
        // the gateway read them instead of `.env`.
        $this->app->singleton(BazaarIapSettings::class, BazaarIapSettingsResolver::class);

        // The API contract keeps its own /api/auth/* endpoints, so Fortify's
        // route registration is skipped; AuthController drives the exact
        // same login pipeline pieces below.
        Fortify::ignoreRoutes();

        // JSON-shaped responses for the SPA, replacing Fortify's redirects.
        $this->app->singleton(LoginResponse::class, FortifyLoginResponse::class);
        $this->app->singleton(LockoutResponse::class, FortifyLockoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * OTP login inside Fortify's authentication pipeline.
         *
         * Fortify has no OTP concept, so the documented customization point
         * (Fortify::authenticateUsing) performs the OTP verification and
         * returns the user; AttemptToAuthenticate then runs guard->login()
         * and PrepareAuthenticatedSession regenerates the session ID —
         * i.e. session issuance, throttling and login events are 100%
         * Fortify's own. Failures keep the original Persian messages and
         * status codes of the API contract.
         */
        Fortify::authenticateUsing(function (Request $request) {
            $phone = AuthService::normalizePhone((string) $request->input('phone', ''));
            $code = preg_replace('/\D/', '', AuthService::toLatinDigits((string) $request->input('password', ''))) ?? '';

            if (! AuthService::isValidIranianMobile($phone) || strlen($code) !== 5) {
                $this->failLogin($request, 'شماره یا کد تایید معتبر نیست.');
            }

            $result = AuthService::verifyOtp($phone, $code);
            if (! $result['ok']) {
                $messages = [
                    'no_code' => 'کد تاییدی برای این شماره یافت نشد.',
                    'expired' => 'کد تایید منقضی شده است. دوباره تلاش کنید.',
                    'too_many' => 'تعداد تلاش‌های ناموفق زیاد است. کد جدید بگیرید.',
                    'mismatch' => 'کد تایید اشتباه است.',
                ];

                $this->failLogin($request, $messages[$result['reason']] ?? 'کد تایید نامعتبر است.');
            }

            if (User::where('phone', $phone)->where('is_blocked', true)->exists()) {
                throw new HttpResponseException(
                    response()->json(['error' => 'حساب کاربری شما مسدود شده است.'], 403)
                );
            }

            return AuthService::findOrCreateUser($phone);
        });

        // Explicit pipeline — Fortify's default minus two-factor steps
        // (2FA is not enabled in this installation).
        Fortify::authenticateThrough(fn () => [
            EnsureLoginIsNotThrottled::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        // Defense in depth: an admin is never lockable out of any ability.
        // (The admin role also holds the full permission catalog in spatie.)
        Gate::before(function ($user, string $ability) {
            if ($user instanceof User && $user->hasRole('admin')) {
                return true;
            }

            return null;
        });

        // Fulfil Cafe Bazaar in-app purchases once the package has consumed
        // the token (order → submitted / wallet credit), and roll back on
        // failure — reusing the same idempotent action as the Zarinpal flow.
        Event::listen(PurchaseConsumed::class, [FulfillBazaarPurchase::class, 'handleConsumed']);
        Event::listen(PurchaseFailed::class, [FulfillBazaarPurchase::class, 'handleFailed']);
    }

    /** Counts the attempt against Fortify's login limiter, then fails. */
    protected function failLogin(Request $request, string $message): never
    {
        app(LoginRateLimiter::class)->increment($request);

        throw new HttpResponseException(
            response()->json(['error' => $message], 400)
        );
    }
}
