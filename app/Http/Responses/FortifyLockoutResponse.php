<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;

/**
 * Lockout response — keeps the API contract's Persian 429 JSON shape
 * instead of Fortify's default redirect/validation error.
 */
class FortifyLockoutResponse implements LockoutResponseContract
{
    /** @param \Illuminate\Http\Request $request */
    public function toResponse($request)
    {
        return response()->json([
            'error' => 'تعداد تلاش‌ها بیش از حد مجاز است. چند لحظه بعد تلاش کنید.',
        ], 429);
    }
}
