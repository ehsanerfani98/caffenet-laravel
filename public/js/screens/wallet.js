/* Caffenet Lite — screens/wallet.js
 * Wallet page (customer): balance, presets, custom top-up.
 * Port of customer/WalletModal.tsx + WalletScreen. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  /* Wallet top-up presets come from business settings as [{amount, sku}] (each
   * carrying its Cafe Bazaar SKU), but legacy plain numbers [50000, …] are still
   * accepted; both reduce to a plain list of amounts for the customer UI. */
  const WALLET_DEFAULT_PRESETS = [50000, 100000, 200000, 500000];
  function walletPresetAmounts(raw) {
    const list = Array.isArray(raw) ? raw : WALLET_DEFAULT_PRESETS;
    const amounts = list
      .map((p) => (p && typeof p === 'object' ? Number(p.amount) : Number(p)))
      .filter((n) => n > 0);
    return amounts.length ? amounts : WALLET_DEFAULT_PRESETS.slice();
  }

  /* One ledger row: type, signed amount, running balance and date. */
  function walletTxnRow(t) {
    const credit = t.direction === 'credit';
    return '<div class="list-row">' +
      '<div class="lr-icon ' + (credit ? 'tone-emerald' : 'tone-rose') + '">' + icon(credit ? 'plus' : 'arrowleft') + '</div>' +
      '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(t.typeTitle || t.type) + '</div>' +
      '<div class="lr-sub">' + PU.toJalaliDate(t.createdAt) + ' • موجودی پس از تراکنش: ' + PU.formatToman(t.balanceAfter) +
      (t.note ? ' • ' + PU.escapeHtml(t.note) : '') + '</div></div>' +
      '<span style="font-weight:800;font-size:12.5px;color:' + (credit ? 'var(--emerald)' : 'var(--rose)') + '">' +
      (credit ? '+' : '−') + PU.formatToman(t.amount) + '</span>' +
      '</div>';
  }

  /* Loads and paints the customer's ledger into #wallet-txns (no-op if absent). */
  function loadWalletTransactions($host) {
    const $box = $host.find('#wallet-txns');
    if (! $box.length) return;
    api.getWalletTransactions()
      .then((txns) => {
        $box.html(txns.length
          ? '<div class="card">' + txns.map(walletTxnRow).join('') + '</div>'
          : UI.emptyState('هنوز تراکنشی ثبت نشده', '', 'wallet'));
      })
      .catch((e) => { $box.html(UI.emptyState('خطا در بارگذاری تراکنش‌ها', e.message, 'alert')); });
  }

  Screens.walletPage = function (ctx) {
    const user = ctx.user;
    if (!user) return UI.emptyState('برای مشاهده کیف پول وارد شوید', '', 'lock');
    if (user.role !== 'customer') return UI.emptyState('کیف پول فقط برای مشتریان فعال است', '', 'wallet');

    const settings = api.state.settings || {};
    const presets = walletPresetAmounts(settings.walletTopUpPresets);

    // Gateway priority for the WebView app:
    //  1) BazaarPay (بازارپی) — a real server-side checkout gateway, so ANY
    //     amount within bounds is payable (no SKU presets); presets stay as
    //     quick-picks and the free-form amount field is always available.
    //  2) Legacy Cafe Bazaar in-app IAP — fixed SKU prices only (deep links
    //     work solely inside the Bazaar app webview).
    //  3) Zarinpal — free amount.
    const bazaarPay = settings.bazaarPay || {};
    const bazaarPayGateway = !!bazaarPay.enabled;
    const bazaar = settings.bazaar;
    const bazaarGateway = !bazaarPayGateway && !!(bazaar && bazaar.enabled);

    const presetsGrid =
      '<div class="svc-grid" id="wallet-presets"' + (bazaarGateway ? ' data-bazaar="1"' : '') + '>' +
        presets.map((amount) =>
          '<div class="svc-card" data-amount="' + amount + '" style="align-items:center;text-align:center">' +
            '<div style="font-weight:800;font-size:14px;color:var(--sky-deep)" class="persian-nums">' + PU.toPersianDigits(Number(amount).toLocaleString('en-US')) + '</div>' +
            '<div style="font-size:10.5px;color:var(--ink-soft)">تومان</div>' +
          '</div>'
        ).join('') +
      '</div>';

    const customBlock = bazaarGateway
      ? ''
      : '<label class="field-label">مبلغ دلخواه (بین ۱۰ هزار تا ۵۰ میلیون تومان)</label>' +
        '<input class="input" id="wallet-amount" inputmode="numeric" placeholder="مثلاً ۳۰۰۰۰۰">' +
        '<button class="btn emerald block" id="wallet-topup" style="margin-top:12px">' + icon('wallet') +
        (bazaarPayGateway ? 'شارژ با بازارپی' : 'شارژ آنلاین') + '</button>';

    return (
      '<div class="card" style="background:linear-gradient(135deg,var(--sky),var(--sky-deep));color:#fff;border:none">' +
        '<div style="font-size:12px;opacity:.9">موجودی کیف پول</div>' +
        '<div id="wallet-balance-value" style="font-size:26px;font-weight:900;margin-top:6px" class="persian-nums">' + PU.formatToman(user.walletBalance) + '</div>' +
        '<div style="font-size:11px;opacity:.8;margin-top:8px">' + icon('shield', 'i-sm') + ' موجودی فقط با پرداخت آنلاین شارژ می‌شود</div>' +
      '</div>' +

      '<div class="section-title">' + icon('plus') + 'شارژ کیف پول</div>' +
      presetsGrid +
      customBlock +

      '<div class="info-box" style="margin-top:16px"> افزایش موجودی پس از تایید درگاه به‌صورت خودکار اعمال می‌شود.<br>پس از شارژ، می‌توانید سفارش خود را ثبت و از کیف پول پرداخت کنید.</div>' +

      '<div class="section-title">' + icon('receipt') + 'تاریخچه تراکنش‌ها</div>' +
      '<div id="wallet-txns"><div class="spinner" style="margin:16px auto"></div></div>'
    );
  };

  Screens.bind_wallet = function ($host, ctx) {
    const settings = api.state.settings || {};
    const bazaarPay = settings.bazaarPay || {};
    const bazaarPayGateway = !!bazaarPay.enabled;
    const bazaar = settings.bazaar;
    const bazaarGateway = !bazaarPayGateway && !!(bazaar && bazaar.enabled);

    // Single path shared by preset taps and the custom-amount button.
    // Gateway priority: BazaarPay → legacy Bazaar IAP → Zarinpal.
    const startTopUp = async (amount, useBazaar) => {
      if (!amount || amount < 10000) {
        UI.toast('حداقل مبلغ شارژ ۱۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      if (amount > 50000000) {
        UI.toast('حداکثر مبلغ شارژ ۵۰٬۰۰۰٬۰۰۰ تومان است.', 'warning');
        return;
      }
      UI.busy(true);
      try {
        const pay = await api.startPayment('wallet', {
          amount,
          gateway: useBazaar ? 'bazaar' : (bazaarPayGateway ? 'bazaarpay' : undefined),
        });
        Screens.openPaymentRedirect(pay);
      } catch (e) {
        UI.toast(e.message, 'error');
      } finally {
        UI.busy(false);
      }
    };

    $host.find('[data-amount]').on('click', function () {
      const amount = Number($(this).data('amount'));
      if (bazaarGateway) {
        // Legacy IAP has no free amount: tapping a pack charges it right away
        // (the custom-amount field is not rendered in that mode). The deep
        // link only works inside the Bazaar app, so guard against creating an
        // orphan payment when running in a plain browser.
        if (!(window.BazaarIap && window.BazaarIap.isInBazaar())) {
          UI.toast('پرداخت کافه‌بازار فقط داخل اپلیکیشن کافه‌بازار امکان‌پذیر است.', 'warning');
          return;
        }
        startTopUp(amount, true);
        return;
      }
      if (bazaarPayGateway) {
        // BazaarPay is a server-side checkout: tapping a pack charges it right
        // away with the exact amount — no fixed SKU price involved.
        startTopUp(amount, false);
        return;
      }
      $host.find('[data-amount]').removeClass('selected');
      $(this).addClass('selected');
      $host.find('#wallet-amount').val(amount).trigger('input');
    });

    $host.find('#wallet-topup').on('click', async function () {
      const raw = PU.toLatinDigits($host.find('#wallet-amount').val());
      const amount = Number(String(raw).replace(/[^\d]/g, ''));
      const $btn = $(this).prop('disabled', true);
      try {
        await startTopUp(amount, false);
      } finally {
        $btn.prop('disabled', false);
      }
    });

    loadWalletTransactions($host);
  };

  /* In-place refresh on background sync: repaint the balance + ledger without
   * rebuilding the screen (called by App.refreshCurrentScreen). */
  Screens.refresh_wallet = function ($host, ctx) {
    const user = ctx && ctx.user;
    if (user) $host.find('#wallet-balance-value').text(PU.formatToman(user.walletBalance));
    loadWalletTransactions($host);
  };

  /* Shared wallet-shortage prompt. Used by the order form and the order tracker
   * when the wallet cannot cover a service payment; it explains the shortfall
   * and guides the customer to the wallet page to top up. */
  Screens.openWalletShortage = function (required, balance) {
    const need = Number(required || 0);
    const have = Number(balance || 0);
    const shortfall = Math.max(0, need - have);

    const sheet = UI.sheet({
      title: 'موجودی کیف پول کافی نیست',
      subtitle: '',
      icon: 'wallet',
      tone: 'amber',
    });

    sheet.body.html(
      '<div class="warn-box" style="margin-bottom:12px">برای ثبت و پرداخت این سفارش، ابتدا کیف پول خود را شارژ کنید.</div>' +
      '<div class="price-summary">' +
        '<div class="ps-row"><span>مبلغ قابل پرداخت</span><span>' + PU.formatToman(need) + '</span></div>' +
        '<div class="ps-row"><span>موجودی فعلی کیف پول</span><span>' + PU.formatToman(have) + '</span></div>' +
        '<div class="ps-row total"><span>مبلغ کسری</span><span class="val">' + PU.formatToman(shortfall) + '</span></div>' +
      '</div>'
    );

    sheet.footer.html('<button class="btn emerald block" id="go-wallet">' + icon('plus') + 'شارژ کیف پول</button>');
    sheet.footer.find('#go-wallet').on('click', function () {
      // Close every open sheet (this shortage prompt plus any parent sheet such
      // as the order form or order tracker) so no popup lingers over the wallet
      // page after navigating there to top up.
      UI.closeAllSheets();
      if (window.App && window.App.navigate) window.App.navigate('wallet');
    });
  };
})();
