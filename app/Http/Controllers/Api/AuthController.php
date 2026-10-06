<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * OTP auth endpoints — send-otp / verify-otp / logout / me.
 * Rate limits: send-otp 6/min, verify-otp 15/min per IP.
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

        if (User::where('phone', $phone)->where('is_blocked', true)->exists()) {
            return response()->json(['error' => 'حساب کاربری شما مسدود شده است.'], 403);
        }

        if (AuthService::otpQuotaExhausted($phone)) {
            return response()->json(['error' => 'سقف ارسال کد برای این شماره پر شده است. کمی بعد تلاش کنید.'], 429);
        }

        $code = AuthService::generateOtp();
        AuthService::storeOtp($phone, $code, $request->ip());
        SmsService::sendOtp($phone, $code);

        // In either test mode the code is surfaced instead of being SMSed.
        $testMode = \App\Services\SettingsService::bool('otpTestMode')
            || \App\Services\SettingsService::bool('smsTestMode');

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
        $code = preg_replace('/\D/', '', (string) ($request->json('code') ?? '')) ?? '';

        if (! AuthService::isValidIranianMobile($phone) || strlen($code) !== 5) {
            return response()->json(['error' => 'شماره یا کد تایید معتبر نیست.'], 400);
        }

        $result = AuthService::verifyOtp($phone, $code);
        if (! $result['ok']) {
            $messages = [
                'no_code' => 'کد تاییدی برای این شماره یافت نشد.',
                'expired' => 'کد تایید منقضی شده است. دوباره تلاش کنید.',
                'too_many' => 'تعداد تلاش‌های ناموفق زیاد است. کد جدید بگیرید.',
                'mismatch' => 'کد تایید اشتباه است.',
            ];

            return response()->json(['error' => $messages[$result['reason']] ?? 'کد تایید نامعتبر است.'], 400);
        }

        if (User::where('phone', $phone)->where('is_blocked', true)->exists()) {
            return response()->json(['error' => 'حساب کاربری شما مسدود شده است.'], 403);
        }

        $user = AuthService::findOrCreateUser($phone);
        $token = AuthService::signToken($user);

        return response()->json(Formatter::user($user))
            ->cookie(AuthService::authCookie($token));
    }

    public function logout(Request $request): JsonResponse
    {
        $response = response()->json(['message' => 'خروج انجام شد.']);
        $response->cookie(cookie(AuthService::AUTH_COOKIE, '', -1, '/', null, app()->environment('production'), true, false, 'Lax'));

        return $response;
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');

        return response()->json(Formatter::user($user));
    }
}
