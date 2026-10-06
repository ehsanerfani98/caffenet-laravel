<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * requireAuth equivalent — Laravel Fortify session authentication.
 *
 * Rejects guests and blocked accounts, and attaches the user with its
 * resolved (expanded, spatie-backed) permission list as request attributes
 * for the controllers.
 */
class EnsureAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'برای این عملیات باید وارد شوید.'], 401);
        }

        if ($user->is_blocked) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json(['error' => 'حساب کاربری شما مسدود شده است.'], 403);
        }

        $request->attributes->set('authUser', $user);
        $request->attributes->set('authPermissions', $user->effectivePermissions());

        return $next($request);
    }
}
