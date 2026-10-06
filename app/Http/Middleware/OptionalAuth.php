<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * optionalAuth: fills the session when a valid token is present, never rejects.
 * Used for public catalog/slider endpoints where admins see extra data.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = AuthService::extractToken($request);
        if ($token) {
            $user = AuthService::resolveUser($token);
            if ($user) {
                $request->attributes->set('authUser', $user);
                $request->attributes->set('authPermissions', $user->effectivePermissions());
            }
        }

        return $next($request);
    }
}
