<?php

use App\Models\Order;
use App\Services\AuthService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web routes — SPA shell + private file serving + internal test gateway
|--------------------------------------------------------------------------
*/

// The jQuery SPA shell (same role as index.html + Vite in the original).
Route::get('/', function () {
    return view('app');
})->name('home');

// Private uploads: authenticated users only, with per-order ownership check
// (GET /uploads/:filename → orders.customer_files_json / result_files_json).
Route::get('/uploads/{filename}', function (Request $request, string $filename) {
    $token = AuthService::extractToken($request);
    if (! $token) {
        abort(401, 'برای دانلود فایل باید وارد شوید.');
    }
    $user = AuthService::resolveUser($token);
    if (! $user) {
        abort(401, 'نشست نامعتبر است.');
    }

    $filename = basename($filename);
    $isStaff = $user->role === 'admin' || $user->role === 'operator';

    if (! $isStaff) {
        $owns = Order::where('customer_id', $user->id)
            ->where(function ($q) use ($filename) {
                $q->where('customer_files_json', 'like', "%{$filename}%")
                    ->orWhere('result_files_json', 'like', "%{$filename}%");
            })
            ->exists();
        if (! $owns) {
            abort(403, 'دسترسی به این فایل مجاز نیست.');
        }
    }

    if (! Storage::disk('uploads')->exists($filename)) {
        abort(404, 'فایل یافت نشد.');
    }

    return response()->download(Storage::disk('uploads')->path($filename), $filename);
})->where('filename', '[A-Za-z0-9._-]+');

// Internal test gateway (paymentTestMode) — mirrors /test-gateway of the
// original: success/cancel buttons that drive the same verify pipeline.
Route::get('/test-gateway', function (Request $request) {
    $authority = (string) $request->query('authority', '');
    $callback = SettingsService::paymentSettings()['callbackUrl'];
    $amount = 0;

    $payment = \App\Models\Payment::where('authority', $authority)->first();
    if ($payment) {
        $amount = (int) $payment->amount;
    }

    return view('test-gateway', [
        'authority' => $authority,
        'amount' => $amount,
        'callback' => $callback,
    ]);
});
