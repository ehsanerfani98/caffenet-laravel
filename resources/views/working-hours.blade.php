{{-- Standalone «خارج از ساعت کاری هستیم» page — served by WorkingHoursGate
     with HTTP 503 to everyone except admins while workingHoursEnabled is on
     and the Tehran clock is outside the [from, to) window. Fully self-contained
     (inline CSS, self-hosted PeydaFa font) so it renders even with the SPA's
     JS unreachable. A meta refresh re-requests the page every 120s: the moment
     the window opens (e.g. 09:00), the tab lands back on the normal SPA
     without any user action. --}}
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#eaf1fd">
    <meta http-equiv="refresh" content="120">
    <meta name="robots" content="noindex">
    <title>{{ $siteTitle }} — خارج از ساعت کاری</title>
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

        html, body { height: 100%; }

        body {
            font-family: 'PeydaFa', Tahoma, Arial, sans-serif;
            background: #f7f8fa;
            color: #1c2b4a;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            padding: 24px 20px 110px;
        }

        .wh {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .badge {
            width: 96px;
            height: 96px;
            border-radius: 30px;
            background: linear-gradient(135deg, #6d8dfb, #4f6ef7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 14px 30px rgba(79, 110, 247, .35);
            animation: pop .5s cubic-bezier(.32, .72, .34, 1.2) both;
        }
        @keyframes pop {
            from { opacity: 0; transform: scale(.7); }
            to   { opacity: 1; transform: none; }
        }

        h1 {
            font-size: 25px;
            font-weight: 900;
            margin-top: 22px;
            letter-spacing: -.2px;
        }

        .sub {
            margin-top: 12px;
            font-size: 14.5px;
            line-height: 2.1;
            color: #7c8aa5;
        }

        .hours-card {
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
        .hours-clock {
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
        .hours-label {
            font-size: 12.5px;
            color: #6b7ba6;
        }
        .hours-value {
            margin-top: 3px;
            font-size: 17.5px;
            font-weight: 800;
            color: #22315a;
        }
        .hours-extra {
            margin-top: 4px;
            font-size: 12.5px;
            color: #6b7ba6;
            line-height: 1.9;
        }

        .back {
            margin-top: 26px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 26px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid #dbe5f5;
            color: #22315a;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(28, 43, 74, .08);
            -webkit-tap-highlight-color: transparent;
        }
        .back:active { transform: scale(.97); }

        .auto-note {
            margin-top: 14px;
            font-size: 12px;
            color: #a9b4c9;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .auto-note svg { animation: spin 3.2s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

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
    <div class="wh">
        <div class="badge" aria-hidden="true">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
        </div>

        <h1>خارج از ساعت کاری هستیم</h1>

        <p class="sub">سامانه در ساعات کاری پاسخ‌گوی شماست.<br>لطفاً در ساعات کاری دوباره سر بزنید.</p>

        <div class="hours-card">
            <div class="hours-clock">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3.2 1.8"></path>
                </svg>
            </div>
            <div>
                <div class="hours-label">ساعت کاری ما</div>
                <div class="hours-value">از ساعت {{ $from }} تا ساعت {{ $to }} می‌باشد</div>
                @if ($hoursText !== '')
                    <div class="hours-extra">{{ $hoursText }}</div>
                @endif
            </div>
        </div>

        <button type="button" class="back" onclick="window.location.reload()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            تلاش مجدد
        </button>

        <div class="auto-note">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            <span>با شروع ساعت کاری، همین صفحه به‌صورت خودکار باز می‌شود</span>
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
