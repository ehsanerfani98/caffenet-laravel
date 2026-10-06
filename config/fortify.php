<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of the password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which module is being used by Fortify. Caffenet Lite
    | authenticates by Iranian mobile number — the OTP code travels in the
    | "password" field of the login pipeline request.
    |
    */

    'username' => 'phone',

    'email' => 'phone',

    'lowercase_usernames' => false,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. (Unused by the JSON SPA, kept sane.)
    |
    */

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware / Views
    |--------------------------------------------------------------------------
    |
    | Routes are registered manually (Fortify::ignoreRoutes) because the API
    | contract keeps its own /api/auth/* endpoints; views are disabled —
    | this installation is a headless JSON backend for the jQuery SPA.
    |
    */

    'views' => false,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting / Features
    |--------------------------------------------------------------------------
    |
    | "limiters.login" left null → Fortify's built-in LoginRateLimiter
    | (5 attempts, 60s decay, lockout events) protects the verify step in
    | addition to the endpoint-level throttles in routes/api.php.
    |
    | No registration / password-reset / 2FA / email-verification features:
    | phone OTP is the only authentication flow.
    |
    */

    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
    ],

    'features' => [],

    /*
    |--------------------------------------------------------------------------
    | Logout Redirect
    |--------------------------------------------------------------------------
    |
    */

    'redirects' => [
        'logout' => '/',
        'password-reset' => '/',
        'email-verification' => '/',
    ],

];
