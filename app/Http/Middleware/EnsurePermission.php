<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * requirePermission equivalent: grants access when the user holds AT LEAST
 * ONE of the required permissions (comma-separated parameter), honouring the
 * manage-umbrella and implicit-view inheritance rules. Admins always pass.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->attributes->get('authUser');
        if (! $user) {
            return response()->json(['error' => 'برای این عملیات باید وارد شوید.'], 401);
        }

        $required = array_filter(explode('|', $permissions));
        $granted = $request->attributes->get('authPermissions') ?? [];

        if ($user->role === 'admin' || PermissionService::hasAny($granted, $required)) {
            return $next($request);
        }

        return response()->json(['error' => 'شما دسترسی لازم برای این عملیات را ندارید.'], 403);
    }
}
