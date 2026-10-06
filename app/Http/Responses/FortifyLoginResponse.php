<?php

namespace App\Http\Responses;

use App\Http\Support\Formatter;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * JSON login response — the session cookie is already issued by Laravel's
 * session machinery when this runs; the SPA only needs the user payload.
 */
class FortifyLoginResponse implements LoginResponseContract
{
    protected StatefulGuard $guard;

    public function __construct(StatefulGuard $guard)
    {
        $this->guard = $guard;
    }

    /** @param \Illuminate\Http\Request $request */
    public function toResponse($request)
    {
        $user = $this->guard->user() ?? $request->user();

        // Laravel rotates the session ID *and* the CSRF token on login
        // (SessionGuard::updateSession). The SPA never reloads, so the fresh
        // token ships alongside the user payload and api.js stores it.
        return response()->json(
            Formatter::user($user) + ['csrf' => csrf_token()]
        );
    }
}
