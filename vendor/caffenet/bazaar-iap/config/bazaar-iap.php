<?php

/*
|--------------------------------------------------------------------------
| Bazaar IAP configuration
|--------------------------------------------------------------------------
|
| Every value defaults to an environment variable so a plain Laravel
| application only needs to edit `.env`. Applications that keep their
| credentials elsewhere (for example a database `settings` table) should
| bind a custom implementation of Caffenet\BazaarIap\Contracts\BazaarIapSettings
| instead of relying on this file — see the package README.
|
*/

return [

    // Master switch. When false the manager refuses to build deep links and
    // consume calls, and the HTTP routes respond 503.
    'enabled' => env('BAZAAR_IAP_ENABLED', false),

    // Test mode: deep links are still produced (so the UI flow can be tested)
    // but the consume call is simulated instead of hitting Bazaar.
    'test_mode' => env('BAZAAR_IAP_TEST_MODE', false),

    // The application's (developer account) package name in Cafe Bazaar,
    // e.g. "ir.example.myapp".
    'package_name' => env('BAZAAR_IAP_PACKAGE_NAME', ''),

    // The API secret from the Cafe Bazaar developer panel. This MUST stay on
    // the server and is never sent to the browser.
    'api_secret' => env('BAZAAR_IAP_API_SECRET', ''),

    // The default SKU used when a caller does not provide one.
    'default_sku' => env('BAZAAR_IAP_DEFAULT_SKU', ''),

    // The package name that receives the money (usually the same as the
    // application package name). Falls back to `package_name`.
    'dealer_package_name' => env('BAZAAR_IAP_DEALER_PACKAGE_NAME', ''),

    // Permission scope requested during the in-app login deep link.
    'permission_scope' => (int) env('BAZAAR_IAP_PERMISSION_SCOPE', 1),

    // Where Bazaar returns the user after login/payment. Leave empty to use
    // the package's own callback route (recommended).
    'redirect_url' => env('BAZAAR_IAP_REDIRECT_URL', ''),

    // Bazaar REST API endpoints (only change for a mock/proxy).
    'base_urls' => [
        'consume' => env('BAZAAR_IAP_CONSUME_URL', 'https://pardakht.cafebazaar.ir/devapi/v2/api/consume'),
        'validate' => env('BAZAAR_IAP_VALIDATE_URL', 'https://pardakht.cafebazaar.ir/devapi/v2/api/validate'),
    ],

    // HTTP timeout (seconds) for Bazaar API calls.
    'timeout' => (int) env('BAZAAR_IAP_TIMEOUT', 20),

    // Package HTTP routes (start / callback / status / resume).
    'routes' => [
        'enabled' => (bool) env('BAZAAR_IAP_ROUTES', true),
        'prefix' => env('BAZAAR_IAP_ROUTE_PREFIX', 'bazaar-iap'),
        'middleware' => ['web'],
    ],

    // Table used to persist in-flight transactions.
    'table' => env('BAZAAR_IAP_TABLE', 'bazaar_iap_transactions'),

];
