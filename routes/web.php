<?php

use App\Models\Order;
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

// Order uploads physically live in public/uploads (no storage:link symlink
// needed on shared hosting) and are normally served directly by the web
// server. This route is the fallback for requests that reach PHP: it keeps the
// per-order ownership check (orders.customer_files_json / result_files_json).
Route::get('/uploads/{filename}', function (Request $request, string $filename) {
    // Fortify session authentication.
    $user = $request->user();
    if (! $user) {
        abort(401, 'برای دانلود فایل باید وارد شوید.');
    }

    $filename = basename($filename);

    // Staff detection uses the canonical `role` column (the same source used by
    // OrderController::canAccess and the SPA). It must NOT rely solely on the
    // spatie role name: when a per-user permission override exists,
    // User::syncAccess() stores the generic `customer` spatie role while the
    // real role stays in the `role` column — so hasAnyRole() would wrongly
    // report an operator as a non-staff customer.
    $isStaff = in_array($user->role, ['admin', 'operator'], true)
        || $user->hasAnyRole(['admin', 'operator']);

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

    // Inline so images/PDFs can be previewed in the browser instead of always
    // being force-downloaded.
    return response()->file(Storage::disk('uploads')->path($filename));
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
