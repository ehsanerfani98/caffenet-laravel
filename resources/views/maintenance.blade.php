{{-- Standalone «سایت در حال تعمیر است» page — served by MaintenanceGate with
     HTTP 503 to everyone except admins while maintenanceMode is on. Fully
     self-contained (inline CSS, self-hosted PeydaFa font, one PNG hero) so it
     renders even with the SPA's JS unreachable. A meta refresh re-requests
     the page every 120s: the moment the gate opens, the tab lands back on
     the normal SPA. --}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#eaf1fd">
    <meta http-equiv="refresh" content="120">
    <meta name="robots" content="noindex">
    <title>{{ $siteTitle }} — در حال تعمیر و نگه‌داری</title>
    <style>
        @font-face {
            font-family: 'PeydaFa';
            src: url('/fonts/PeydaFaNumWeb-Regular.woff2') format('woff2');
            font-weight: 400;
            font-display: swap;
        }
        @font-face {
            font-family: 'PeydaFa';
            src: url('/fonts/PeydaFaNumWeb-Bold.woff2') format('woff2');
            font-weight: 700;
            font-display: swap;
        }
        @font-face {
            font-family: 'PeydaFa';
            src: url('/fonts/PeydaFaNumWeb-Black.woff2') format('woff2');
            font-weight: 900;
            font-display: swap;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'PeydaFa', Tahoma, Arial, sans-serif;
            background: #f7f8fa;
            color: #1c2b4a;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            padding: 24px 20px 96px;
        }

        .mtn {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-top: 8px;
        }

        .hero {
            width: 100%;
            max-width: 340px;
            height: auto;
            user-select: none;
            pointer-events: none;
            animation: hero-in .7s ease-out both;
        }
        @keyframes hero-in {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: none; }
        }

        h1 {
            font-size: 27px;
            font-weight: 900;
            margin-top: 18px;
            letter-spacing: -.2px;
        }

        .sub {
            margin-top: 14px;
            font-size: 14.5px;
            line-height: 2.1;
            color: #7c8aa5;
        }

        .eta-card {
            margin-top: 26px;
            width: 100%;
            background: #e8f0fe;
            border-radius: 22px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-align: right;
        }
        .eta-clock {
            flex: 0 0 auto;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6d8dfb, #4f6ef7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 8px 18px rgba(79, 110, 247, .35);
        }
        .eta-label {
            font-size: 12.5px;
            color: #6b7ba6;
        }
        .eta-value {
            margin-top: 3px;
            font-size: 17px;
            font-weight: 700;
            color: #22315a;
        }

        .progress {
            margin-top: 26px;
            width: 100%;
            height: 10px;
            border-radius: 99px;
            background: #e3eaf7;
            overflow: hidden;
        }
        .progress .bar {
            width: 42%;
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #6d8dfb, #4f6ef7);
            animation: crawl 2.4s ease-in-out infinite alternate;
        }
        @keyframes crawl {
            from { width: 30%; }
            to   { width: 62%; }
        }

        .updating {
            margin-top: 12px;
            font-size: 13px;
            color: #8a97b0;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .updating svg {
            animation: spin 3.2s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .thanks {
            margin-top: 34px;
            font-size: 15px;
            font-weight: 700;
            color: #43506d;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .thanks svg { color: #3b82f6; }

        .social {
            margin-top: 18px;
            display: flex;
            align-items: center;
            gap: 26px;
            color: #8a97b0;
        }
        .social span { display: inline-flex; }

        .admin-link {
            position: relative;
            z-index: 2;
            margin-top: 34px;
            font-size: 11.5px;
            color: #a9b4c9;
            text-decoration: none;
        }
        .admin-link:hover { color: #6b7ba6; text-decoration: underline; }

        .wave {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1;
            pointer-events: none;
            line-height: 0;
        }
        .wave svg {
            width: 100%;
            height: 90px;
            display: block;
        }
    </style>
</head>
<body>
    <div class="mtn">
        <img class="hero" src="/media/maintenance-hero.png?v=1" alt="ربات تعمیرکار" width="640" height="318">

        <h1>سایت در حال تعمیر است</h1>

        <p class="sub">در حال ارتقا و به‌روزرسانی تجربه شما هستیم.<br>به زودی دوباره در دسترس خواهیم بود.</p>

        @if ($eta !== '')
            <div class="eta-card">
                <div class="eta-clock">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 7v5l3.2 1.8"></path>
                    </svg>
                </div>
                <div>
                    <div class="eta-label">زمان تقریبی بازگشت</div>
                    <div class="eta-value">{{ $eta }}</div>
                </div>
            </div>
        @endif

        <div class="progress" role="progressbar" aria-label="در حال انجام بروزرسانی‌ها"><div class="bar"></div></div>

        <div class="updating">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3.2"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span>در حال انجام بروزرسانی‌ها…</span>
        </div>

        <div class="thanks">
            <span>از صبر و همراهی شما سپاس‌گزاریم</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 21s-7.5-4.7-10-9.3C.4 8.6 2.4 5 5.8 5c2 0 3.4 1.1 4.2 2.3h4C14.8 6.1 16.2 5 18.2 5c3.4 0 5.4 3.6 3.8 6.7C19.5 16.3 12 21 12 21z" transform="scale(.92) translate(1 1)"></path>
            </svg>
        </div>

        <div class="social" aria-hidden="true">
            <span>
                {{-- globe --}}
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18"></path><path d="M12 3a14.5 14.5 0 0 1 0 18 14.5 14.5 0 0 1 0-18z"></path></svg>
            </span>
            <span>
                {{-- telegram --}}
                <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor"><path d="M21.9 4.6 18.9 19c-.2 1-.8 1.2-1.7.8l-4.6-3.4-2.2 2.1c-.25.25-.45.45-.9.45l.3-4.6L18.2 7c.4-.3-.1-.5-.6-.2L7.3 13.3l-4.4-1.4c-1-.3-1-1 .2-1.4L20.6 3.2c.8-.3 1.5.2 1.3 1.4z"></path></svg>
            </span>
            <span>
                {{-- instagram --}}
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"></circle></svg>
            </span>
        </div>

        <a class="admin-link" href="/?entry=admin">ورود مدیر</a>
    </div>

    <div class="wave">
        <svg viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true">
            <path fill="#eaf2fd" d="M0,64 C240,110 480,120 720,96 C960,72 1200,20 1440,44 L1440,120 L0,120 Z"></path>
            <path fill="#dbe8fb" d="M0,92 C260,60 520,116 760,104 C1000,92 1240,52 1440,78 L1440,120 L0,120 Z" opacity=".8"></path>
        </svg>
    </div>
</body>
</html>
