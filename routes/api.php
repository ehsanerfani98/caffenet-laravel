<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\SliderController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — exact mapping of the original Express API (routes.ts)
|--------------------------------------------------------------------------
| Every route keeps the same method, path, access rule and response shape
| as documented in docs/PROJECT_DOCUMENTATION.md §13. Global API rate
| limiting (300/min) applies through the ThrottleRequests group below.
*/

Route::get('/health', fn () => response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]));

// ---------------- Auth (dedicated rate limiters) ----------------
// IP throttles (send 6/min, verify 15/min) live in AuthController so the
// contract's Persian 429 JSON ships; Fortify's login limiter additionally
// locks out brute-force verification attempts.
Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth.session');

// ---------------- Catalog (public reads, guarded writes) ----------------
Route::middleware('auth.optional')->get('/categories', [CatalogController::class, 'categories']);
Route::middleware('auth.optional')->get('/services', [CatalogController::class, 'services']);

Route::middleware(['auth.session', 'permission:services.create|services.edit|services.manage'])->group(function () {
    Route::post('/categories', [CatalogController::class, 'saveCategory']);
    Route::post('/services', [CatalogController::class, 'saveService']);
});
Route::middleware(['auth.session', 'permission:services.delete|services.manage'])->group(function () {
    Route::delete('/categories/{id}', [CatalogController::class, 'deleteCategory']);
    Route::delete('/services/{id}', [CatalogController::class, 'deleteService']);
});

// ---------------- Upload ----------------
Route::middleware('auth.session')->group(function () {
    Route::post('/upload', [UploadController::class, 'upload']);
    Route::delete('/upload', [UploadController::class, 'delete']);
});

// ---------------- Orders ----------------
Route::middleware('auth.session')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{id}/pay-wallet', [OrderController::class, 'payWithWallet']);
    Route::post('/orders/{id}/result', [OrderController::class, 'uploadResult'])
        ->middleware('permission:orders.edit|orders.manage');
    Route::delete('/orders/{id}/result', [OrderController::class, 'deleteResult'])
        ->middleware('permission:orders.edit|orders.manage');
    Route::delete('/orders/{id}/customer-file', [OrderController::class, 'deleteCustomerFile'])
        ->middleware('permission:orders.edit|orders.manage');
    Route::post('/orders/{id}/ticket', [OrderController::class, 'ticket']);
    Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])
        ->middleware('permission:orders.edit|orders.manage');
    Route::patch('/orders/{id}', [OrderController::class, 'update']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy'])
        ->middleware('permission:orders.delete|orders.manage');
});

// ---------------- Payments ----------------
Route::middleware('auth.session')->group(function () {
    Route::post('/payments/request', [PaymentController::class, 'request']);
    Route::get('/payments/status', [PaymentController::class, 'status']);
});
Route::get('/payments/verify', [PaymentController::class, 'verify']); // gateway callback

// ---------------- Wallet ledger / refunds ----------------
// The customer's own ledger; staff with wallet.view|wallet.manage may inspect
// any (or all) users'. Refund and manual adjust are admin/manager only.
Route::middleware('auth.session')->get('/wallet/transactions', [WalletController::class, 'transactions']);
Route::middleware(['auth.session', 'permission:wallet.refund|wallet.manage'])->post('/wallet/refund', [WalletController::class, 'refund']);
Route::middleware(['auth.session', 'permission:wallet.adjust|wallet.manage'])->post('/wallet/adjust', [WalletController::class, 'adjust']);

// ---------------- Users / permissions / dashboard ----------------
Route::middleware(['auth.session', 'permission:users.view|users.manage'])->get('/users', [UserController::class, 'index']);
Route::middleware(['auth.session', 'permission:users.create|users.manage'])->post('/users', [UserController::class, 'store']);
Route::middleware(['auth.session', 'permission:users.edit|users.permissions|users.manage'])->patch('/users/{id}', [UserController::class, 'update']);
Route::middleware(['auth.session', 'permission:users.delete|users.manage'])->delete('/users/{id}', [UserController::class, 'destroy']);

Route::middleware('auth.session')->get('/dashboard/stats', [DashboardController::class, 'stats']);
Route::middleware('auth.session')->get('/permissions', [PermissionController::class, 'catalog']);

// ---------------- Coupons ----------------
Route::middleware('auth.session')->get('/coupons', [CouponController::class, 'index']);
Route::middleware('auth.session')->post('/coupons/validate', [CouponController::class, 'validate']);
Route::middleware(['auth.session', 'permission:coupons.create|coupons.manage'])->post('/coupons', [CouponController::class, 'store']);
Route::middleware(['auth.session', 'permission:coupons.edit|coupons.manage'])->patch('/coupons/{id}', [CouponController::class, 'update']);
Route::middleware(['auth.session', 'permission:coupons.delete|coupons.manage'])->delete('/coupons/{id}', [CouponController::class, 'destroy']);

// ---------------- Settings ----------------
Route::middleware('auth.optional')->get('/settings', [SettingsController::class, 'business']);
Route::middleware(['auth.session', 'permission:settings.edit|settings.manage'])->put('/settings', [SettingsController::class, 'updateBusiness']);
Route::middleware(['auth.session', 'permission:settings.edit|settings.manage'])->get('/settings/system', [SettingsController::class, 'system']);
Route::middleware(['auth.session', 'permission:settings.edit|settings.manage'])->put('/settings/system', [SettingsController::class, 'updateSystem']);

// ---------------- SMS log ----------------
Route::middleware(['auth.session', 'permission:sms.view|sms.manage'])->get('/sms', [SmsController::class, 'index']);
Route::middleware(['auth.session', 'permission:sms.delete|sms.manage'])->group(function () {
    Route::delete('/sms', [SmsController::class, 'clear']);
    Route::delete('/sms/{id}', [SmsController::class, 'destroy']);
});

// ---------------- Slider ----------------
Route::middleware('auth.optional')->get('/slider', [SliderController::class, 'index']);
Route::middleware(['auth.session', 'permission:slider.create|slider.edit|slider.manage'])->post('/slider/upload', [SliderController::class, 'upload']);
Route::middleware(['auth.session', 'permission:slider.create|slider.edit|slider.manage'])->post('/slider', [SliderController::class, 'store']);
Route::middleware(['auth.session', 'permission:slider.edit|slider.manage'])->put('/slider/reorder', [SliderController::class, 'reorder']);
Route::middleware(['auth.session', 'permission:slider.delete|slider.manage'])->delete('/slider/{id}', [SliderController::class, 'destroy']);

// Order files are stored in public/uploads (no symlink needed) and served
// directly from /uploads/*; the fallback route in web.php keeps the
// ownership check for requests that still reach PHP.
