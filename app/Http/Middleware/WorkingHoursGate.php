<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Working-hours gate — «ساعت کاری».
 *
 * Registered by APPENDING it to the `web` middleware group (bootstrap/app.php),
 * directly after MaintenanceGate which established the pattern:
 *  1. The /api/* surface runs INSIDE the web group in this app (Fortify session
 *     auth shares the cookie), so one registration covers the SPA shell,
 *     /track/{token} and every API route.
 *  2. Appended group middleware runs AFTER StartSession, so $request->user()
 *     resolves from the session (and the remember_web_* cookie) here — admins
 *     are recognised even before any route middleware runs.
 *
 * Behaviour when SettingsService::bool('workingHoursEnabled') is OFF:
 *   pass-through — one settings SELECT per request, nothing else changes.
 *
 * Behaviour when it is ON, OUTSIDE the configured [from, to) window (Tehran
 * time — the server itself may sit in UTC):
 *   - Admins (users.role === 'admin' or the spatie `admin` role) pass through
 *     untouched: the owner must be able to check orders and flip the switch
 *     at night, and a locked-out owner with an expired session could never
 *     recover (the gate judges by session, not by intention).
 *   - A small allow-list passes for EVERYONE, because blocking them would
 *     break self-recovery or in-flight payments:
 *       /api/auth/*            — an admin whose session expired must be able
 *                                to log back in outside working hours;
 *       GET /api/settings      — the SPA boot reads the public business
 *                                settings (also exposes from/to so a client
 *                                could render the message itself);
 *       GET /api/payments/*    — external gateway callbacks (verify + the
 *                                BazaarPay contract return) and the SPA's
 *                                result poll (status): a user who started a
 *                                payment at 20:58 must still complete it at
 *                                21:01 — the money already moved;
 *       /  with ?payment=      — the gateway-return shell (the SPA's
 *                                handlePaymentReturn shows the result);
 *       /  with ?entry=admin   — the SPA shell for the admin login escape
 *                                hatch linked from the closed page;
 *       /api/health + /up      — uptime probes;
 *       /api/_artisan/run/*    — token-authed deploy/diagnostics runner used
 *                                for cPanel no-SSH maintenance operations.
 *   - Everything else: API requests get a 503 JSON {error, workingHours:true,
 *     from, to} the SPA bounce handler keys on; page requests get the
 *     standalone «خارج از ساعت کاری هستیم» Blade page (Retry-After + a
 *     meta-refresh so the tab auto-recovers the moment hours open — e.g. a
 *     9→21 window reopens itself by 21:02 without any user action).
 *
 * Window semantics: the customer-facing phrase «از ساعت ۹ تا ساعت ۲۱» means
 * [9, 21) — open at 09:00, closed at exactly 21:00. A from GREATER than to is
 * an overnight window (22→6 = open 22:00..05:59). from EQUAL to to is treated
 * as an empty window (always closed) — the settings endpoint refuses to save
 * that combination, this is just a defensive guard against hand-edited rows.
 */
class WorkingHoursGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SettingsService::bool('workingHoursEnabled')) {
            return $next($request);
        }

        // Admins keep the whole app — they own the switch.
        $user = $request->user();
        if ($user && ($user->role === 'admin' || method_exists($user, 'hasRole') && $user->hasRole('admin'))) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        $from = self::hourSetting('workingHoursFrom', 9);
        $to = self::hourSetting('workingHoursTo', 21);
        if (self::isOpenNow($from, $to)) {
            return $next($request);
        }

        $message = 'سامانه خارج از ساعت کاری است. ساعت کاری ما از ساعت '
            .self::fa($from).' تا ساعت '.self::fa($to).' می‌باشد.';

        if ($request->is('api/*')) {
            return response()->json([
                'error' => $message,
                'workingHours' => true,
                'from' => $from,
                'to' => $to,
            ], 503);
        }

        return response()->view('working-hours', [
            'from' => self::fa($from),
            'to' => self::fa($to),
            'hoursText' => trim((string) SettingsService::get('workingHours', '')),
            'siteTitle' => (string) SettingsService::get('siteTitle', 'کافی‌نت احسان'),
        ], 503)->withHeaders([
            'Retry-After' => '120',
        ]);
    }

    /**
     * Whether the app is open at the CURRENT moment (Tehran time — the
     * owner's clock, not the server's). Public + static so the contract is
     * unit-testable via Carbon::setTestNow().
     */
    public static function isOpenNow(int $from, int $to): bool
    {
        if ($from === $to) {
            return false; // empty window — always closed (defensive guard)
        }

        $h = (int) Carbon::now('Asia/Tehran')->format('G');

        return $from < $to
            ? ($h >= $from && $h < $to)
            : ($h >= $from || $h < $to); // overnight window (e.g. 22 → 6)
    }

    /** Paths that must keep working for everyone while the gate is closed. */
    private function isAlwaysAllowed(Request $request): bool
    {
        // Gateway return lands on the SPA shell with ?payment=… — the SPA's
        // handlePaymentReturn() shows the result of a payment that started
        // before closing time.
        if ($request->isMethod('GET') && ! $request->is('api/*') && $request->query('payment') !== null) {
            return true;
        }

        // Admin login escape hatch: the closed page links here so an admin
        // whose session expired can still open the login sheet.
        if ($request->isMethod('GET') && ! $request->is('api/*') && $request->query('entry') === 'admin') {
            return true;
        }

        return $request->is('api/auth/*')                                        // login/logout/me
            || $request->is('up')                                                // framework health route
            || ($request->isMethod('GET') && $request->is('api/settings'))       // public business settings read
            || ($request->isMethod('GET') && $request->is('api/payments/verify'))             // gateway callback
            || ($request->isMethod('GET') && $request->is('api/payments/bazaarpay/contract')) // direct-pay callback
            || ($request->isMethod('GET') && $request->is('api/payments/status'))             // SPA result poll
            || $request->is('api/health')
            || $request->is('api/_artisan/run/*');
    }

    /** An hour setting parsed defensively — garbage falls back to the default. */
    private static function hourSetting(string $key, int $fallback): int
    {
        $v = (int) SettingsService::toLatinDigits(trim((string) SettingsService::get($key, (string) $fallback)));

        return ($v >= 0 && $v <= 23) ? $v : $fallback;
    }

    /** Latin → Persian digits for the customer-facing message. */
    private static function fa(int $n): string
    {
        return strtr((string) $n, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}
