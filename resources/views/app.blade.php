<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0284c7">
    <meta name="description" content="سامانه آنلاین خدمات کافی‌نت: پرینت، خدمات دانشجویی، اداری و مالیاتی">
    <title>{{ \App\Services\SettingsService::get('siteTitle', 'کافی‌نت آنلاین') }}</title>

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ \App\Services\SettingsService::get('siteTitle', 'کافی‌نت آنلاین') }}">

    <link rel="stylesheet" href="/css/app.css?v={{ filemtime(public_path('css/app.css')) }}">
    <script defer src="/js/jquery.min.js?v={{ filemtime(public_path('js/jquery.min.js')) }}"></script>
</head>
<body>
    <div class="stage">
        <div class="phone-frame" id="app-frame">
            <div id="offline-indicator" class="offline-banner hidden">
                اتصال اینترنت قطع است — در حالت آفلاین هستید
            </div>
            <header id="app-bar"></header>
            <main id="screen-host" class="screen-host" aria-live="polite"></main>
            <div id="sheet-host"></div>
            <div id="toast-host"></div>
            <div id="busy-overlay" class="busy-overlay hidden">
                <div class="spinner"></div>
            </div>
            <nav id="bottom-nav"></nav>
        </div>
    </div>

    @if (request()->query('payment') !== null)
        {{-- Gateway return params consumed by app.js (CSP-safe JSON data block) --}}
        <script type="application/json" id="payment-return">{ "status": "{{ request()->query("payment") }}", "authority": "{{ request()->query("authority") }}" }</script>
    @endif

    <script type="application/json" id="boot-data">@json(["csrf" => csrf_token(), "siteTitle" => \App\Services\SettingsService::get("siteTitle", "کافی‌نت آنلاین")])</script>
    {{-- Versioned by filemtime so edits can never be served from the browser
         HTTP cache (the old poll-every-second api.js previously stuck here). --}}
    <script defer src="/js/persian-utils.js?v={{ filemtime(public_path('js/persian-utils.js')) }}"></script>
    <script defer src="/js/icons.js?v={{ filemtime(public_path('js/icons.js')) }}"></script>
    <script defer src="/js/permissions.js?v={{ filemtime(public_path('js/permissions.js')) }}"></script>
    <script defer src="/js/pricing.js?v={{ filemtime(public_path('js/pricing.js')) }}"></script>
    <script defer src="/js/api.js?v={{ filemtime(public_path('js/api.js')) }}"></script>
    <script defer src="/js/ui.js?v={{ filemtime(public_path('js/ui.js')) }}"></script>
    <script defer src="/js/pwa.js?v={{ filemtime(public_path('js/pwa.js')) }}"></script>
    <script defer src="/js/app.js?v={{ filemtime(public_path('js/app.js')) }}"></script>
    <script defer src="/js/screens/auth.js?v={{ filemtime(public_path('js/screens/auth.js')) }}"></script>
    <script defer src="/js/screens/landing.js?v={{ filemtime(public_path('js/screens/landing.js')) }}"></script>
    <script defer src="/js/screens/order-form.js?v={{ filemtime(public_path('js/screens/order-form.js')) }}"></script>
    <script defer src="/js/screens/order-tracker.js?v={{ filemtime(public_path('js/screens/order-tracker.js')) }}"></script>
    <script defer src="/js/screens/wallet.js?v={{ filemtime(public_path('js/screens/wallet.js')) }}"></script>
    <script defer src="/js/screens/payment.js?v={{ filemtime(public_path('js/screens/payment.js')) }}"></script>
    <script defer src="/js/screens/operator.js?v={{ filemtime(public_path('js/screens/operator.js')) }}"></script>
    <script defer src="/js/screens/admin.js?v={{ filemtime(public_path('js/screens/admin.js')) }}"></script>
    <script defer src="/js/screens/admin-manage.js?v={{ filemtime(public_path('js/screens/admin-manage.js')) }}"></script>
</body>
</html>
