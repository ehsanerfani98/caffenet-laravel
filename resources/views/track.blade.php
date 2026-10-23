<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>پیگیری سفارش — کافی‌نت احسان</title>
    <style>
        /* Same design tokens as the SPA (public/css/app.css) — self-contained
           so this page never loads the whole app stylesheet. */
        @font-face { font-family: 'PeydaFa'; src: url('/fonts/PeydaFaNumWeb-Regular.woff2') format('woff2'); font-weight: 400; font-display: swap; }
        @font-face { font-family: 'PeydaFa'; src: url('/fonts/PeydaFaNumWeb-Medium.woff2') format('woff2'); font-weight: 500; font-display: swap; }
        @font-face { font-family: 'PeydaFa'; src: url('/fonts/PeydaFaNumWeb-SemiBold.woff2') format('woff2'); font-weight: 600; font-display: swap; }
        @font-face { font-family: 'PeydaFa'; src: url('/fonts/PeydaFaNumWeb-Bold.woff2') format('woff2'); font-weight: 700; font-display: swap; }

        :root {
            --sky: #1559de; --sky-deep: #1147b2; --sky-soft: #e3eafc;
            --emerald: #059669; --emerald-soft: #d1fae5;
            --amber: #d97706; --amber-soft: #fef3c7;
            --rose: #e11d48; --rose-soft: #ffe4e6;
            --ink: #1e293b; --ink-soft: #64748b; --line: #e2e8f0;
            --bg: #f8fafc; --card: #ffffff;
            --radius: 16px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            font-family: 'PeydaFa', system-ui, sans-serif; direction: rtl; color: var(--ink);
            background: radial-gradient(1200px 800px at 70% -10%, #14304d 0%, #0b1220 55%);
            min-height: 100vh; min-height: 100dvh; -webkit-font-smoothing: antialiased;
        }
        .stage { min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 0; }
        .page {
            width: 100%; max-width: 480px; min-height: 100vh; min-height: 100dvh;
            background: var(--bg); display: flex; flex-direction: column; overflow: hidden auto;
        }
        @media (min-width: 520px) { .stage { padding: 28px 0; } .page { min-height: 0; max-height: 92vh; border-radius: 34px; box-shadow: 0 30px 80px rgba(2,8,23,.5); } }

        .appbar { display: flex; align-items: center; gap: 10px; padding: 16px 18px 12px; border-bottom: 1px solid var(--line); background: var(--card); }
        .appbar .logo { width: 38px; height: 38px; border-radius: 12px; background: var(--sky-soft); color: var(--sky-deep); display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .appbar b { font-size: 14.5px; display: block; }
        .appbar .sub { font-size: 11px; color: var(--ink-soft); }

        .content { padding: 14px 14px 26px; display: flex; flex-direction: column; gap: 12px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px; }
        .card-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .card-title b { font-size: 14px; }
        .meta { font-size: 11.5px; color: var(--ink-soft); line-height: 2.1; }
        .meta b { color: var(--ink); }

        .badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
        .sk { width: 64px; height: 16px; border-radius: 8px; background: var(--line); animation: pulse 1.2s ease-in-out infinite; }
        @keyframes pulse { 50% { opacity: .45; } }

        .queue-card { padding: 0; overflow: hidden; }
        .queue-hero { display: flex; align-items: center; gap: 12px; padding: 14px; }
        .queue-hero-icon { width: 40px; height: 40px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .queue-hero-value { font-size: 20px; font-weight: 800; line-height: 1.3; }
        .queue-hero-label { font-size: 11px; color: var(--ink-soft); }
        .eta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 0 14px 14px; }
        .eta-cell { background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 9px 11px; }
        .eta-label { font-size: 10.5px; color: var(--ink-soft); margin-bottom: 3px; }
        .eta-value { font-size: 13px; font-weight: 800; color: var(--ink); }
        .queue-warn { margin: 0 14px 14px; background: var(--amber-soft); color: #92400e; font-size: 11.5px; line-height: 1.9; padding: 10px 12px; border-radius: 12px; }

        .tl-item { position: relative; padding: 0 22px 16px 0; border-right: 2px solid var(--line); margin-right: 8px; }
        .tl-item:last-child { padding-bottom: 2px; border-right-color: transparent; }
        .tl-dot { position: absolute; right: -7px; top: 1px; width: 12px; height: 12px; border-radius: 50%; background: var(--sky); border: 2.5px solid var(--card); box-shadow: 0 0 0 1px var(--line); }
        .tl-action { font-size: 12.5px; font-weight: 700; }
        .tl-meta { font-size: 10.5px; color: var(--ink-soft); margin-top: 2px; }
        .tl-note { font-size: 11.5px; color: var(--ink-soft); background: var(--bg); border-radius: 10px; padding: 7px 10px; margin-top: 6px; line-height: 1.9; }

        .notice { background: var(--sky-soft); border-radius: 12px; padding: 10px 12px; font-size: 12px; line-height: 1.9; }
        .notice .n-time { font-size: 10.5px; color: var(--ink-soft); margin-top: 3px; }
        .empty { text-align: center; color: var(--ink-soft); font-size: 12px; padding: 22px 10px; line-height: 2; }
        .empty .big { font-size: 30px; margin-bottom: 6px; }

        .footer { text-align: center; font-size: 10.5px; color: var(--ink-soft); padding: 4px 18px calc(20px + env(safe-area-inset-bottom)); line-height: 2; }
        .footer a { color: var(--sky-deep); font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<div class="stage">
    <div class="page" id="page">
        <div class="appbar">
            <div class="logo">☕</div>
            <div>
                <b>کافی‌نت احسان</b>
                <div class="sub">پیگیری زنده سفارش</div>
            </div>
        </div>
        <div class="content" id="content">
            <div class="card"><div class="sk" style="width:55%"></div><div class="sk" style="width:80%;margin-top:10px"></div></div>
        </div>
        <div class="footer">
            برای مدیریت کامل سفارش، <a href="/">اپلیکیشن کافی‌نت احسان</a> را باز کنید.
        </div>
    </div>
</div>
<script src="/js/track-page.js?v={{ filemtime(public_path('js/track-page.js')) }}"></script>
</body>
</html>
