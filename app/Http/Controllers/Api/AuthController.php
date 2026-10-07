<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Services\AuthService;
use App\Services\SmsService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest;

/**
 * Auth endpoints — send-otp / verify-otp / logout / me.
 *
 * verify-otp drives Laravel Fortify's login pipeline with the phone as the
 * username and the 5-digit code in the password field: EnsureLoginIsNotThrottled
 * (Fortify's LoginRateLimiter) → AttemptToAuthenticate (guard->login via
 * Fortify::authenticateUsing) → PrepareAuthenticatedSession (session ID
 * regeneration). logout mirrors Fortify's AuthenticatedSessionController.
 *
 * Rate limits: send-otp 6/min, verify-otp 15/min per IP (contract),
 * plus Fortify's per-phone+IP lockout inside the pipeline.
 */
class AuthController extends Controller
{
    public function sendOtp(Request $request): JsonResponse
    {
        $executed = RateLimiter::attempt(
            key: 'otp-send:'.$request->ip(),
            maxAttempts: 6,
            callback: fn () => true,
            decaySeconds: 60,
        );

        if (! $executed) {
            return response()->json(['error' => 'تعداد درخواست‌ها بیش از حد مجاز است. چند لحظه بعد تلاش کنید.'], 429);
        }

        $phone = AuthService::normalizePhone((string) ($request->json('phone') ?? ''));
        if (! AuthService::isValidIranianMobile($phone)) {
            return response()->json(['error' => 'شماره موبایل معتبر نیست.'], 400);
        }

        // SMS disabled ⇒ nothing works, neither real nor simulator: the OTP is
        // never generated or surfaced, and the client is told why it cannot log in.
        if (! SettingsService::bool('smsEnabled')) {
            return response()->json(['error' => 'سامانه پیامک غیرفعال است؛ امکان دریافت کد تایید وجود ندارد.'], 503);
        }

        if (\App\Models\User::where('phone', $phone)->where('is_blocked', true)->exists()) {
            return response()->json(['error' => 'حساب کاربری شما مسدود شده است.'], 403);
        }

        if (AuthService::otpQuotaExhausted($phone)) {
            return response()->json(['error' => 'سقف ارسال کد برای این شماره پر شده است. کمی بعد تلاش کنید.'], 429);
        }

        $code = AuthService::generateOtp();
        AuthService::storeOtp($phone, $code, $request->ip());
        SmsService::sendOtp($phone, $code);

        // SMS is enabled at this point; in simulator mode the code is surfaced
        // instead of being SMSed (the simulator never runs while SMS is disabled).
        $testMode = SettingsService::bool('smsTestMode');

        $payload = ['message' => 'کد تایید ارسال شد.'];
        if ($testMode) {
            $payload['testCode'] = $code;
            $payload['message'] = 'حالت آزمایشی: کد تایید نمایش داده می‌شود.';
        }

        return response()->json($payload);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $executed = RateLimiter::attempt(
            key: 'otp-verify:'.$request->ip(),
            maxAttempts: 15,
            callback: fn () => true,
            decaySeconds: 60,
        );

        if (! $executed) {
            return response()->json(['error' => 'تعداد تلاش‌ها بیش از حد مجاز است.'], 429);
        }

        $phone = AuthService::normalizePhone((string) ($request->json('phone') ?? ''));
        $code = preg_replace('/\D/', '', AuthService::toLatinDigits((string) ($request->json('code') ?? ''))) ?? '';

        if (! AuthService::isValidIranianMobile($phone) || strlen($code) !== 5) {
            return response()->json(['error' => 'شماره یا کد تایید معتبر نیست.'], 400);
        }

        // Map the OTP request onto Fortify's login contract:
        // username = phone, password = the verification code.
        $request->merge(['phone' => $phone, 'password' => $code]);

        return $this->loginPipeline(LoginRequest::createFrom($request))
            ->then(fn (Request $request) => app(LoginResponse::class));
    }

    /**
     * The same pipeline Fortify's AuthenticatedSessionController runs for
     * POST /login — applied here because the API keeps its own endpoints.
     */
    protected function loginPipeline(LoginRequest $request): Pipeline
    {
        return (new Pipeline(app()))
            ->send($request)
            ->through(array_filter([
                EnsureLoginIsNotThrottled::class,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
            ]));
    }

    public function logout(Request $request): JsonResponse
    {
        // Fortify's AuthenticatedSessionController::destroy pattern.
        Auth::guard('web')->logout();

        // invalidate() flushes the old session and regenerateToken() mints a
        // brand-new CSRF token on the server. The SPA never reloads, so unless
        // the rotated token is shipped back here it keeps sending the stale one
        // and every later mutation (e.g. the next send-otp/verify-otp) fails
        // with a 419 CSRF token mismatch until the page is manually refreshed.
        // Mirror FortifyLoginResponse: hand the fresh token to the client.
        $csrf = null;
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $csrf = csrf_token();
        }

        return response()->json(['message' => 'خروج انجام شد.', 'csrf' => $csrf]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(Formatter::user($request->user()));
    }
}
