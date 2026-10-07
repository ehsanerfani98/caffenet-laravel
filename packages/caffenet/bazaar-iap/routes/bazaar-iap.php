<?php

declare(strict_types=1);

use Caffenet\BazaarIap\Http\Controllers\BazaarIapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bazaar IAP routes
|--------------------------------------------------------------------------
| Loaded under the prefix / middleware configured in `config/bazaar-iap.php`
| (default prefix: `bazaar-iap`, default middleware: `web`). The callback is
| the URL handed to Cafe Bazaar as the redirect target.
*/

Route::get('/callback', [BazaarIapController::class, 'callback'])->name('bazaar-iap.callback');
Route::get('/status', [BazaarIapController::class, 'status'])->name('bazaar-iap.status');
Route::get('/login', [BazaarIapController::class, 'login'])->name('bazaar-iap.login');
Route::post('/start', [BazaarIapController::class, 'start'])->name('bazaar-iap.start');
