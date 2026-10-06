<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * requireAuth equivalent: verifies the JWT (cookie or Bearer), loads the user,
 * rejects blocked accounts, and attaches the user with resolved permissions
 * to the request attributes.
 */
class EnsureAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = AuthService::extractToken($request);
        if (! $token) {
            return response()->json(['error' => 'برای این عملیات باید وارد شوید.'], 401);
        }

        $user = AuthService::resolveUser($token);
        if (! $user) {
            return response()->json(['error' => 'نشست شما نامعتبر یا منقضی شده است.'], 401);
        }

        $request->attributes->set('authUser', $user);
        $request->attributes->set('authPermissions', $user->effectivePermissions());

        return $next($request);
    }
}
