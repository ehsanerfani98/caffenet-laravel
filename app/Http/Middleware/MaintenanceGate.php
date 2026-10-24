<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance gate — «حالت تعمیر و نگه‌داری».
 *
 * Registered by APPENDING it to the `web` middleware group (bootstrap/app.php).
 * That placement matters for two reasons:
 *  1. The /api/* surface runs INSIDE the web group in this app (Fortify session
 *     auth shares the cookie), so one registration covers both the SPA shell,
 *     /track/{token} and every API route.
 *  2. Appended group middleware runs AFTER StartSession, so $request->user()
 *     resolves from the session (and from the remember_web_* cookie) here —
 *     admins are recognised even before any route middleware runs.
 *
 * Behaviour when SettingsService::bool('maintenanceMode') is OFF:
 *   pass-through — one settings SELECT per request, nothing else changes.
 *
 * Behaviour when it is ON:
 *   - Admins (users.role === 'admin' or the spatie `admin` role) pass through
 *     untouched: they must be able to see the site and flip the switch back off.
 *   - A small allow-list of paths passes for EVERYONE, because blocking them
 *     would break self-recovery or in-flight payments:
 *       /api/auth/*            — an admin whose session expired must be able to
 *                                log back in during maintenance;
 *       GET /api/settings      — the SPA boot (and ?entry=admin login shell)
 *                                reads the public business settings;
 *       /api/payments/verify + /api/payments/bazaarpay/contract — external
 *                                gateway callbacks; a user who started a
 *                                payment before the switch was flipped must
 *                                still complete it;
 *       /api/health + /up      — uptime probes;
 *       /api/_artisan/run/*    — token-authed deploy/diagnostics runner used
 *                                for cPanel no-SSH maintenance operations.
 *       /  with ?entry=admin   — the SPA shell for the admin login escape
 *                                hatch linked from the maintenance page.
 *   - Everything else: API requests get a 503 JSON {error, maintenance:true};
 *     page requests get the standalone «سایت در حال تعمیر است» Blade page
 *     (Retry-After + a meta-refresh so the tab auto-recovers when the gate
 *     opens again).
 */
class MaintenanceGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SettingsService::bool('maintenanceMode')) {
            return $next($request);
        }

        // Admins keep the whole site — they own the switch.
        $user = $request->user();
        if ($user && ($user->role === 'admin' || method_exists($user, 'hasRole') && $user->hasRole('admin'))) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return response()->json([
                'error' => 'سایت در حال تعمیر و به‌روزرسانی است. لطفاً کمی بعد دوباره سر بزنید.',
                'maintenance' => true,
            ], 503);
        }

        return response()->view('maintenance', [
            'eta' => trim((string) SettingsService::get('maintenanceEta', '')),
            'siteTitle' => (string) SettingsService::get('siteTitle', 'کافی‌نت احسان'),
        ], 503)->withHeaders([
            'Retry-After' => '120',
        ]);
    }

    /**
     * Paths that must keep working for everyone while the gate is closed.
     * Everything here is either needed to log an admin back in, needed to
     * finish an already-started payment, or an operational endpoint.
     */
    private function isAlwaysAllowed(Request $request): bool
    {
        // Admin login escape hatch: the maintenance page links here so an
        // admin whose session expired can still open the login sheet.
        if ($request->isMethod('GET')
            && ! $request->is('api/*')
            && $request->query('entry') === 'admin') {
            return true;
        }

        return $request->is('api/auth/*')                 // login/logout/me
            || $request->is('up')                          // framework health route
            || ($request->isMethod('GET') && $request->is('api/settings'))        // public business settings read
            || ($request->isMethod('GET') && $request->is('api/payments/verify')) // gateway callback
            || ($request->isMethod('GET') && $request->is('api/payments/bazaarpay/contract')) // direct-pay callback
            || $request->is('api/health')
            || $request->is('api/_artisan/run/*');
    }
}
