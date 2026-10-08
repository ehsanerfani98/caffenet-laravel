<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ادامه پرداخت</title>
    <style>
        html, body { height: 100%; margin: 0; font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; }
        .wrap { display: flex; min-height: 100%; flex-direction: column; align-items: center; justify-content: center; gap: 14px; padding: 24px; text-align: center; }
        .spinner { width: 46px; height: 46px; border-radius: 50%; border: 4px solid #334155; border-top-color: #38bdf8; animation: spin 0.9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        a { color: #38bdf8; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="spinner"></div>
        <div>در حال ادامه پرداخت در کافه‌بازار…</div>
        <a id="manual" href="{{ $deepLink }}">اگر باز نشد، اینجا را لمس کنید</a>
    </div>

    <script>
        (function () {
            var deepLink = @json($deepLink);
            try { window.location.replace(deepLink); } catch (e) { /* fallback link below */ }
        })();
    </script>
</body>
</html>
