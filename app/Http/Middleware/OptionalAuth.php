<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * optionalAuth: fills the session attributes when a valid session exists,
 * never rejects. Used for public catalog/slider endpoints where admins see
 * extra data.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_blocked) {
            $request->attributes->set('authUser', $user);
            $request->attributes->set('authPermissions', $user->effectivePermissions());
        }

        return $next($request);
    }
}
