<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>درگاه پرداخت آزمایشی — کافی‌نت احسان</title>
    <style>
        body { font-family: system-ui, sans-serif; direction: rtl; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .gw { background: #fff; border-radius: 22px; padding: 34px 28px; max-width: 380px; width: 92%; box-shadow: 0 20px 60px rgba(2,8,23,.15); text-align: center; }
        .gw .logo { width: 64px; height: 64px; border-radius: 18px; background: #e3eafc; color: #1147b2; display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 14px; }
        .gw h1 { font-size: 17px; margin: 0 0 6px; }
        .gw .sub { font-size: 12px; color: #64748b; margin-bottom: 18px; }
        .gw .amount { font-size: 26px; font-weight: 800; color: #1147b2; margin-bottom: 20px; }
        .gw .btns { display: flex; gap: 10px; }
        .gw button { flex: 1; padding: 13px; border: none; border-radius: 13px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .gw .ok { background: #059669; color: #fff; }
        .gw .cancel { background: #fee2e2; color: #b91c1c; }
        .gw .note { font-size: 10.5px; color: #94a3b8; margin-top: 16px; line-height: 1.9; }
    </style>
</head>
<body data-callback="{{ $callback }}" data-authority="{{ $authority }}">
    <div class="gw">
        <div class="logo">💳</div>
        <h1>درگاه پرداخت آزمایشی</h1>
        <div class="sub">این درگاه صرفاً برای حالت آزمایشی سامانه است</div>
        <div class="amount">{{ number_format($amount) }} تومان</div>
        <div class="btns">
            <button class="ok" id="pay-ok">پرداخت موفق</button>
            <button class="cancel" id="pay-cancel">انصراف</button>
        </div>
        <div class="note">پرداخت موفق، همان مسیر تایید (verify) درگاه واقعی را طی می‌کند و سفارش را به صف کار می‌فرستد.</div>
    </div>
    <script src="/js/test-gateway.js"></script>
</body>
</html>
