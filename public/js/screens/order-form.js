/* Caffenet Lite — screens/order-form.js
 * Dynamic form builder + live pricing preview + file upload + coupon +
 * payment method choice. Port of customer/OrderFormModal.tsx. */
(function () {
  'use strict';

  const MAX_FILE_MB = 25;

  window.Screens = window.Screens || {};

  // Values coming back from the API may be numbers while option values are
  // strings (or vice-versa); compare loosely so a configured default still
  // matches its option when we build the markup.
  function sameValue(a, b) {
    return a != null && b != null && String(a) === String(b);
  }

  function fieldInput(f, value) {
    const v = value != null ? value : (f.defaultValue != null ? f.defaultValue : '');
    const req = f.required ? ' <span class="req">*</span>' : '';
    let html = '<label class="field-label">' + PU.escapeHtml(f.label) + req + '</label>';

    switch (f.type) {
      case 'text':
      case 'number':
        html += '<input class="input" data-field="' + f.id + '" type="' + f.type + '" value="' + PU.escapeHtml(v) + '"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>';
        break;
      case 'textarea':
        html += '<textarea class="textarea" data-field="' + f.id + '" rows="3"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>' + PU.escapeHtml(v) + '</textarea>';
        break;
      case 'select':
        html += '<select class="select" data-field="' + f.id + '">';
        html += '<option value="">انتخاب کنید…</option>';
        (f.options || []).forEach((o) => {
          const extra = o.extraPrice ? ' (+' + PU.toPersianDigits(Number(o.extraPrice).toLocaleString('en-US')) + ' تومان)' : '';
          html += '<option value="' + PU.escapeHtml(o.value) + '"' + (sameValue(v, o.value) ? ' selected' : '') + '>' +
            PU.escapeHtml(o.label) + extra + '</option>';
        });
        html += '</select>';
        break;
      case 'radio':
        (f.options || []).forEach((o) => {
          const sel = sameValue(v, o.value);
          html += '<div class="radio-card ' + (sel ? 'sel' : '') + '" data-field-radio="' + f.id + '" data-value="' + PU.escapeHtml(o.value) + '">' +
            '<span class="rc-dot"></span><span>' + PU.escapeHtml(o.label) + '</span>' +
            (o.extraPrice ? '<span class="rc-price">+' + PU.toPersianDigits(Number(o.extraPrice).toLocaleString('en-US')) + ' ت</span>' : '') +
            '</div>';
        });
        break;
      case 'checkbox':
        html += '<label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;margin-bottom:8px">' +
          '<input type="checkbox" data-field="' + f.id + '"' + (v ? ' checked' : '') + '> ' + PU.escapeHtml(f.label) + '</label>';
        break;
      case 'date':
        // Jalali date field — the shared datepicker.js watcher binds any
        // input[data-jdp] through a delegated focusin listener, so it works
        // inside this dynamically rendered sheet without extra wiring.
        html += '<input class="input" data-jdp data-field="' + f.id + '" readonly value="' + PU.escapeHtml(v) + '"' +
          (f.placeholder ? ' placeholder="' + PU.escapeHtml(f.placeholder) + '"' : '') + '>';
        break;
      default:
        html += '<input class="input" data-field="' + f.id + '" value="' + PU.escapeHtml(v) + '">';
    }

    if (f.helpText) html += '<div class="help-text">' + PU.escapeHtml(f.helpText) + '</div>';
    return html;
  }

  /* Sheet entry — guards + popup shell. The form itself lives in
   * mountOrderForm so the dedicated service page (#/service/{slug}) can host
   * the identical form without a popup. */
  Screens.openOrderForm = function (service) {
    const user = api.state.currentUser;
    if (!user) { Screens.openAuth(); return; }
    // Only customers place orders for themselves; operators/admins are staff
    // accounts and are not the customer of an order.
    if (user.role !== 'customer') {
      UI.toast('ثبت سفارش تنها با حساب مشتری امکان‌پذیر است.', 'warning');
      return;
    }
    // سرویس بسته (notice_type = 'lock') — the red popup replaces the form.
    // This guard also covers the draft-resume path (App.resumeOrderDraft),
    // where a service locked after the draft was saved must not reopen.
    if (Screens.openServiceNotice && Screens.openServiceNotice(service)) return;
    const popup = UI.sheet({
      title: 'ثبت سفارش',
      subtitle: service.title,
      icon: 'receipt',
      tone: 'emerald',
      size: 'tall',
    });
    Screens.mountOrderForm(service, { body: popup.body, footer: popup.footer, close: function () { popup.close(); } });
  };

  /* Mounts the full order form (fields, files, coupon, pricing, submit flow)
   * into any host exposing {body, footer, close} — the bottom sheet above or
   * the dedicated service page. close() runs after a real order was created,
   * right before the tracker / acknowledgment popup opens. */
  Screens.mountOrderForm = function (service, host) {
    const user = api.state.currentUser;

    const settings = api.state.settings || {};
    // BazaarPay gateway (بازارپی) — when available the footer shows two
    // explicit pay buttons: «پرداخت آنلاین» goes to the BazaarPay gateway
    // page (checkout redirect — same pipeline as a wallet top-up) and
    // «پرداخت از کیف پول» debits the in-app balance. Direct Pay (silent,
    // contract-based) is resolved automatically inside the gateway button
    // by the server when its contract is active.
    const bazaarPayAvailable = !!(settings.bazaarPay && settings.bazaarPay.available);
    // حالت «غیرفعال کردن پرداخت‌ها» (حالت ثبت درخواست): the admin turned every
    // payment path off — the footer becomes a single «ثبت درخواست» button and
    // the order is created with no payment step at all.
    const paymentsDisabled = !!settings.paymentsDisabled;
    const state = {
      formData: {},
      urgency: 'normal',
      deliveryMethod: 'download', // روش دریافت از فرم حذف شده — همیشه دانلود آنلاین
      customerNote: '',
      contactMethods: [],
      files: [],
      couponCode: '',
      couponDiscount: 0,
      couponError: null,
    };

    // Pre-populate formData from each field's configured default value. Without
    // this, a select/radio that already renders with a default selection is not
    // reflected in state.formData, so required-field validation fails until the
    // customer manually re-picks the option (which is what fires the handlers
    // that normally populate formData).
    (service.fields || []).forEach((f) => {
      const def = f.defaultValue;
      if (def !== undefined && def !== null && def !== '') {
        state.formData[f.id] = def;
      } else if (f.type === 'checkbox') {
        state.formData[f.id] = false;
      }
    });

    // Restore a saved draft (پیش‌نویس سفارش): the snapshot taken when the
    // wallet shortage led the customer away to top up. The draft overrides
    // the configured defaults so the customer resumes exactly where they
    // left off — same service, all fields, files and coupon intact.
    try {
      const draft = window.OrderDraft && OrderDraft.load(service.id, user.id);
      if (draft && draft.state) {
        Object.assign(state, draft.state);
        UI.toast('اطلاعات قبلی این سفارش بازیابی شد.', 'info');
      }
    } catch (e) { /* draft is best-effort — never block the form */ }

    // A draft saved by an older build may carry 'courier' + address; the
    // delivery section no longer exists, so force the only remaining method.
    state.deliveryMethod = 'download';

    // A restored draft may also carry a coupon from the payment era — in
    // request mode there is no price/discount at all (the coupon UI is hidden
    // above), so it must not silently leak into the order the server prices.
    if (paymentsDisabled) {
      // فوریت و کد تخفیف هر دو عامل قیمت‌اند — در حالت «ثبت درخواست» نباید از
      // پیش‌نویس‌های دوران پرداخت به سفارش بی‌پرداخت سرریز کنند.
      state.couponCode = '';
      state.couponDiscount = 0;
      state.urgency = 'normal';
    }

    function renderForm() {
      const couponsEnabled = settings.enableCoupons !== false;

      host.body.html(
        /* service dynamic fields */
        '<div class="form-fields">' +
          service.fields.map((f) => fieldInput(f, state.formData[f.id])).join('') +
        '</div>' +

        /* files */
        '<label class="field-label">فایل‌های پیوست (اختیاری — حداکثر ' + PU.toPersianDigits(MAX_FILE_MB) + ' مگابایت)</label>' +
        '<div id="files-list"></div>' +
        '<button class="btn ghost block sm" id="add-file">' + icon('upload') + 'افزودن فایل</button>' +
        '<input type="file" id="file-input" class="hidden" multiple>' +

        /* customer note */
        '<label class="field-label">توضیحات سفارش</label>' +
        '<textarea class="textarea" id="customer-note" rows="2" placeholder="هر نکته‌ای برای اپراتور…">' + PU.escapeHtml(state.customerNote) + '</textarea>' +

        /* urgency — فوریت یک عامل قیمت است (ضریب)؛ در حالت «ثبت درخواست» کل بخش مخفی است */
        (paymentsDisabled ? '' :
        '<label class="field-label">سطح فوریت <span class="req">*</span></label>' +
        '<div class="segmented" id="urgency-seg">' +
          [['normal', 'معمولی'], ['urgent', 'فوری (×' + PU.toPersianDigits(settings.urgentPriceMultiplier || 1.3) + ')'], ['immediate', 'آنی (×' + PU.toPersianDigits(settings.immediatePriceMultiplier || 1.6) + ')']]
            .map(([k, l]) => '<button class="seg ' + (state.urgency === k ? 'on' : '') + '" data-urgency="' + k + '">' + l + '</button>').join('') +
        '</div>') +

        /* contact methods — multi-select */
        '<label class="field-label">راه‌های ارتباط با شما <span class="req">*</span></label>' +
        '<div class="help-text">می‌توانید چند گزینه را انتخاب کنید تا اپراتور از همان راه با شما در تماس باشد.</div>' +
        '<div class="contact-grid" id="contact-methods">' +
          PU.contactMethods.map(([k, l]) =>
            '<span class="chip ' + (state.contactMethods.includes(k) ? 'active' : '') + '" data-contact="' + k + '">' + l + '</span>'
          ).join('') +
        '</div>' +

        /* coupon — در حالت ثبت درخواست پرداختی در کار نیست، پس کد تخفیف هم بی‌معناست */
        (couponsEnabled && !paymentsDisabled ?
          '<label class="field-label">کد تخفیف</label>' +
          '<div style="display:flex;gap:8px">' +
            '<input class="input" id="coupon-code" placeholder="مثلاً WELCOME" style="text-transform:uppercase">' +
            '<button class="btn slate" id="apply-coupon">اعمال</button>' +
          '</div>' +
          '<div id="coupon-msg"></div>' : '') +

        /* live price */
        '<div id="price-summary" style="margin-top:16px"></div>'
      );

      renderFiles();
      renderPrice();
    }

    function renderFiles() {
      const $list = host.body.find('#files-list');
      $list.html(state.files.map((f) =>
        '<div class="file-chip"><div class="fc-icon">' + icon('file') + '</div>' +
        '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
        '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
        '<button class="fc-del" data-del="' + PU.escapeHtml(f.url) + '">' + icon('trash') + '</button></div>'
      ).join(''));
      $list.find('[data-del]').on('click', async function () {
        const url = $(this).data('del');
        state.files = state.files.filter((f) => f.url !== url);
        try { await api.deleteUploadedFile(url); } catch (e) { /* best effort */ }
        renderFiles();
      });
    }

    function livePricing() {
      return Pricing.calculate({
        service,
        formData: state.formData,
        urgency: state.urgency,
        deliveryMethod: state.deliveryMethod,
        multipliers: {
          normal: 1,
          urgent: Number(settings.urgentPriceMultiplier || 1.3),
          immediate: Number(settings.immediatePriceMultiplier || 1.6),
        },
        courierCost: Number(settings.courierCost || 35000),
        discountAmount: state.couponDiscount,
      });
    }

    function renderPrice() {
      // قرارداد جدید: قیمت در هر شرایطی دیده می‌شود — حتی در حالت «ثبت درخواست»
      // (درخواست صاحب برنامه). فوریت/تخفیف در این حالت مخفی‌اند و خودبه‌خود
      // خنثی می‌شوند، پس خلاصه همان قیمت پایه + اضافه‌پول فیلدهاست.
      const p = livePricing();
      host.body.find('#price-summary').html(
        '<div class="price-summary">' +
          '<div class="ps-row"><span>جمع خدمات</span><span>' + PU.formatToman(p.subtotal) + '</span></div>' +
          (p.urgencyMultiplier > 1 ? '<div class="ps-row"><span>ضریب فوریت</span><span>×' + PU.toPersianDigits(p.urgencyMultiplier) + '</span></div>' : '') +
          (p.discountAmount > 0 ? '<div class="ps-row" style="color:var(--emerald)"><span>تخفیف</span><span>−' + PU.formatToman(p.discountAmount) + '</span></div>' : '') +
          '<div class="ps-row total"><span>' + (paymentsDisabled ? 'مبلغ سفارش' : 'مبلغ قابل پرداخت') + '</span><span class="val">' + PU.formatToman(p.payable) + '</span></div>' +
        '</div>'
      );
    }

    /* ---- events (delegated on sheet body) ---- */

    host.body.on('input change', '[data-field]', function () {
      const id = $(this).data('field');
      const field = service.fields.find((f) => f.id === id);
      if (field && field.type === 'checkbox') state.formData[id] = this.checked;
      else if (field && field.type === 'number') state.formData[id] = this.value;
      else state.formData[id] = this.value;
      renderPrice();
    });

    host.body.on('click', '[data-field-radio]', function () {
      const id = $(this).data('field-radio');
      state.formData[id] = $(this).data('value');
      host.body.find('[data-field-radio="' + id + '"]').removeClass('sel');
      $(this).addClass('sel');
      renderPrice();
    });

    host.body.on('click', '[data-urgency]', function () {
      state.urgency = $(this).data('urgency');
      host.body.find('[data-urgency]').removeClass('on');
      $(this).addClass('on');
      renderPrice();
    });

    host.body.on('click', '[data-contact]', function () {
      const key = $(this).data('contact');
      const idx = state.contactMethods.indexOf(key);
      if (idx === -1) state.contactMethods.push(key);
      else state.contactMethods.splice(idx, 1);
      $(this).toggleClass('active', state.contactMethods.includes(key));
    });

    host.body.on('click', '#add-file', () => host.body.find('#file-input').trigger('click'));

    host.body.on('change', '#file-input', async function () {
      const files = Array.from(this.files || []);
      for (const file of files) {
        if (file.size > MAX_FILE_MB * 1024 * 1024) {
          UI.toast('حجم فایل «' + file.name + '» بیش از ' + MAX_FILE_MB + ' مگابایت است.', 'error');
          continue;
        }
        try {
          UI.busy(true);
          const uploaded = await api.uploadRealFile(file);
          state.files.push(uploaded);
          renderFiles();
        } catch (e) {
          UI.toast(e.message, 'error');
        } finally {
          UI.busy(false);
        }
      }
      $(this).val('');
    });

    host.body.on('click', '#apply-coupon', async function () {
      const code = ($('#coupon-code').val() || '').trim();
      const p = Pricing.calculate({
        service, formData: state.formData, urgency: state.urgency, deliveryMethod: state.deliveryMethod,
        multipliers: { normal: 1, urgent: Number(settings.urgentPriceMultiplier || 1.3), immediate: Number(settings.immediatePriceMultiplier || 1.6) },
        courierCost: Number(settings.courierCost || 35000),
      });
      if (!code) { state.couponDiscount = 0; state.couponError = null; renderPrice(); return; }
      try {
        const r = await api.validateCoupon(code, p.totalBeforeDiscount);
        state.couponCode = code;
        state.couponDiscount = r.discount;
        state.couponError = null;
        host.body.find('#coupon-msg').html('<div class="info-box" style="margin-top:8px;color:var(--emerald);background:var(--emerald-soft)">کد تخفیف اعمال شد: ' + PU.formatToman(r.discount) + ' تخفیف</div>');
      } catch (e) {
        state.couponCode = '';
        state.couponDiscount = 0;
        host.body.find('#coupon-msg').html('<div class="warn-box" style="margin-top:8px">' + PU.escapeHtml(e.message) + '</div>');
      }
      renderPrice();
    });

    /* ---- footer: pay buttons — or a single «ثبت درخواست» in no-payment mode ---- */
    host.footer.html(paymentsDisabled ?
      '<button class="btn emerald block" id="order-submit-request">' + icon('check') + 'ثبت درخواست</button>'
      :
      (bazaarPayAvailable ?
        '<button class="btn emerald block" id="order-pay-gateway" style="margin-bottom:8px">' + icon('zap') + 'پرداخت آنلاین</button>' +
        '<button class="btn slate block" id="order-pay-wallet">' + icon('wallet') + 'پرداخت از کیف پول</button>'
        :
        '<button class="btn emerald block" id="order-pay-wallet">' + icon('check') + 'ثبت و پرداخت از کیف پول</button>'));

    const $footerButtons = host.footer.find('button');

    /* Acknowledgment popup for «ثبت درخواست» — centered modal with the
     * tracking number, the admin-configured notice text (orderRequestNotice)
     * and a single «متوجه شدم» button. Dismissing the modal lands the
     * customer on the live queue tracker. */
    function openRequestConfirmation(order) {
      const notice = String(settings.orderRequestNotice || '').trim() ||
        'درخواست شما ثبت شد. همکاران ما به‌زودی برای ادامه کار با شما تماس می‌گیرند.';
      UI.modal({
        title: 'درخواست شما ثبت شد',
        icon: 'checkcircle',
        tone: 'emerald',
        body:
          '<div class="info-box" style="margin-bottom:10px">شماره پیگیری: <b class="persian-nums">' +
          PU.toPersianDigits(String(order.orderNumber || order.id || '')) + '</b></div>' +
          '<div style="white-space:pre-wrap">' + PU.escapeHtml(notice) + '</div>',
        buttonText: 'متوجه شدم',
        onClose: function () {
          Screens.openOrderTracker(order.id);
        },
      });
    }

    async function submitOrder(method) {
      const p = livePricing();

      // client-side validation mirrors the server
      for (const f of service.fields) {
        if (f.required) {
          const v = state.formData[f.id];
          if (v === undefined || v === '' || (f.type === 'checkbox' && !v)) {
            UI.toast('تکمیل فیلد «' + f.label + '» الزامی است.', 'warning');
            return;
          }
        }
      }
      if (!state.contactMethods.length) {
        UI.toast('حداقل یک راه ارتباطی را انتخاب کنید.', 'warning');
        return;
      }

      // Wallet payments require sufficient balance up-front — never persist an
      // order the customer cannot pay for. The BazaarPay gateway has no such
      // requirement (money comes from the gateway page or the user's Bazaar
      // wallet/bank), so the balance check is skipped for it.
      const balance = Number((api.state.currentUser && api.state.currentUser.walletBalance) || 0);
      if (method === 'wallet' && balance < p.payable) {
        // Snapshot the filled form BEFORE leading the customer to the top-up:
        // the gateway round-trip is a full page reload, and without this draft
        // every field would have to be re-entered from scratch. The draft is
        // restored automatically when this service's form reopens (after the
        // payment return or a manual reopen) and is dropped once the order is
        // actually created.
        let draftSaved = false;
        try {
          draftSaved = !!(window.OrderDraft && OrderDraft.save(service.id, user.id, state));
        } catch (e) { /* storage unavailable — flow continues as before */ }
        Screens.openWalletShortage(p.payable, balance, { draftSaved: draftSaved });
        return;
      }

      const payload = {
        serviceId: service.id,
        formData: state.formData,
        urgency: state.urgency,
        deliveryMethod: state.deliveryMethod,
        customerNote: state.customerNote || undefined,
        contactMethods: state.contactMethods,
        customerFiles: state.files,
        couponCode: state.couponCode || undefined,
        // Payment intent — the server only skips its wallet-balance guard for
        // 'bazaarpay' when the BazaarPay gateway is actually available.
        paymentMethod: method,
      };

      $footerButtons.prop('disabled', true);
      UI.busy(true);
      try {
        const order = await api.createOrder(payload);

        // A real order now exists — the saved draft has served its purpose.
        if (window.OrderDraft) OrderDraft.clear(service.id);

        // ── ثبت درخواست (payments disabled): no payment step — just acknowledge ──
        if (method === 'request') {
          host.close();
          openRequestConfirmation(order);
          return;
        }

        // ── BazaarPay gateway (checkout redirect + optional direct pay) ──
        if (method === 'bazaarpay') {
          host.close();
          try {
            const r = await api.payOrderWithBazaarPay(order.id);
            // Navigating outcomes: open the redirect interstitial and let the
            // browser leave — nothing else may render underneath (an order
            // tracker opened here used to flash until the BazaarPay page
            // loaded). On return from the gateway the payment callback
            // (App.handlePaymentReturn) lands on the order tracker itself.
            if (r.status === 'redirect') {
              Screens.openPaymentRedirect({ gateway: 'bazaarpay', redirectUrl: r.redirectUrl });
              return;
            }
            if (r.status === 'contract_pending') {
              // First use of direct pay: the user must sign the contract on
              // BazaarPay; the callback finishes the payment after signing.
              UI.toast('برای فعال‌سازی پرداخت مستقیم، قرارداد را در بازارپی تایید کنید.', 'info');
              Screens.openPaymentRedirect({ gateway: 'bazaarpay', redirectUrl: r.contractRedirectUrl });
              return;
            }
            // Non-navigating outcomes — show the live status on the tracker.
            if (r.status === 'paid') {
              UI.toast('پرداخت با بازارپی انجام شد و سفارش به صف کار اضافه شد.', 'success');
            } else if (r.status === 'pending') {
              UI.toast(r.message || 'پرداخت در حال بررسی است؛ وضعیت سفارش به‌صورت خودکار به‌روز می‌شود.', 'info');
            } else {
              UI.toast(r.error || 'پرداخت ناموفق بود — از صفحه سفارش دوباره تلاش کنید.', 'error');
            }
            Screens.openOrderTracker(order.id);
          } catch (e) {
            // Order exists but its payment could not start — the tracker is
            // where the retry buttons live.
            UI.toast(e.message + ' — می‌توانید از صفحه سفارش‌ها دوباره تلاش کنید.', 'error');
            Screens.openOrderTracker(order.id);
          }
          return;
        }

        // ── Wallet payment ──
        try {
          await api.payOrderWithWallet(order.id);
          host.close();
          UI.toast('پرداخت از کیف پول انجام شد و سفارش به صف کار اضافه شد.', 'success');
          Screens.openOrderTracker(order.id);
        } catch (e) {
          if (e.code === 'INSUFFICIENT_WALLET') {
            // Rare race: the balance changed between the check and the debit.
            host.close();
            Screens.openWalletShortage(e.data && e.data.required, e.data && e.data.balance);
            Screens.openOrderTracker(order.id);
            return;
          }
          UI.toast(e.message + ' — می‌توانید از صفحه سفارش‌ها دوباره تلاش کنید.', 'error');
          host.close();
          Screens.openOrderTracker(order.id);
        }
      } catch (e) {
        if (e.code === 'INSUFFICIENT_WALLET') {
          // Server-side guard: no order was created.
          Screens.openWalletShortage(e.data && e.data.required, e.data && e.data.balance);
          return;
        }
        UI.toast(e.message, 'error');
      } finally {
        $footerButtons.prop('disabled', false);
        UI.busy(false);
      }
    }

    host.footer.find('#order-pay-wallet').on('click', function () { submitOrder('wallet'); });
    host.footer.find('#order-pay-gateway').on('click', function () { submitOrder('bazaarpay'); });
    host.footer.find('#order-submit-request').on('click', function () { submitOrder('request'); });

    // keep note in sync
    host.body.on('input', '#customer-note', function () { state.customerNote = this.value; });

    renderForm();
  };
})();
