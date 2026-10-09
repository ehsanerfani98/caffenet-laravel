/* Caffenet Lite — screens/admin-orders.js
 * Admin monitoring hub: every customer's orders (with the assigned operator and
 * full operation timeline) plus the wallet ledger across all users, with
 * order-based refunds and free-form wallet adjustments. */
(function () {
  'use strict';

  window.Screens = window.Screens || {};

  /* Ledger type titles (mirror App\Models\WalletTransaction::TYPE_TITLES). */
  const TYPE_TITLES = {
    topup: 'شارژ کیف پول (درگاه)',
    purchase: 'پرداخت خدمت',
    refund: 'عودت وجه',
    manual_credit: 'شارژ دستی توسط مدیریت',
    manual_debit: 'کسر دستی توسط مدیریت',
  };

  Screens.adminOrders = function (ctx) {
    if (!ctx.user || ctx.user.role !== 'admin') {
      return UI.emptyState('دسترسی محدود', 'این بخش برای مدیران است', 'lock');
    }
    return (
      '<div class="segmented" id="adm-view">' +
        '<button class="seg on" data-view="orders">سفارش‌ها</button>' +
        '<button class="seg" data-view="wallet">تراکنش‌های کیف پول</button>' +
      '</div>' +
      '<div id="adm-body" style="margin-top:12px"></div>'
    );
  };

  Screens.bind_admin_orders = function ($host, ctx) {
    let view = 'orders';
    let walletTxns = [];
    const canRefund = api.hasPermission('wallet.refund') || api.hasPermission('wallet.manage');
    const canAdjust = api.hasPermission('wallet.adjust') || api.hasPermission('wallet.manage');

    /* ================= orders ================= */

    function renderOrders() {
      const $b = $host.find('#adm-body');
      $b.html(
        '<div class="search-box">' + icon('search') +
          '<input class="input" id="adm-search" placeholder="جستجو: شماره سفارش، مشتری، تلفن، خدمت یا اپراتور…"></div>' +
        '<div class="chip-row" id="adm-status">' +
          '<span class="chip active" data-st="all">همه</span>' +
          '<span class="chip" data-st="pending_payment">در انتظار پرداخت</span>' +
          '<span class="chip" data-st="submitted">ثبت‌شده</span>' +
          '<span class="chip" data-st="processing">در حال انجام</span>' +
          '<span class="chip" data-st="ready">آماده تحویل</span>' +
          '<span class="chip" data-st="delivered">تحویل شده</span>' +
          '<span class="chip" data-st="refunded">عودت شده</span>' +
          '<span class="chip" data-st="cancelled">لغو شده</span>' +
        '</div>' +
        '<div id="adm-list" style="margin-top:12px"></div>'
      );
      paintOrders();
      $b.find('#adm-search').on('input', PU.debounce(paintOrders, 200));
      $b.find('#adm-status .chip').on('click', function () {
        $b.find('#adm-status .chip').removeClass('active');
        $(this).addClass('active');
        paintOrders();
      });
    }

    function orderCard(o) {
      return '<div class="order-card" data-order="' + o.id + '" style="margin-bottom:10px">' +
        '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(o.orderNumber) + '</span>' + UI.statusBadge(o.status) + '</div>' +
        '<div class="oc-title">' + PU.escapeHtml(o.serviceTitle) + ' — ' + PU.escapeHtml(o.customerName) + '</div>' +
        '<div class="oc-foot">' +
          '<span class="oc-price">' + PU.formatToman(o.paidAmount) + '</span>' +
          '<span style="font-size:11px;color:var(--ink-soft)">' +
            (o.assignedOperatorName ? 'اپراتور: ' + PU.escapeHtml(o.assignedOperatorName) : 'بدون اپراتور') +
            ' • ' + PU.toJalaliDateShort(o.createdAt) + '</span>' +
        '</div>' +
        (o.refundAmount ? '<div class="help-text">مبلغ عودت‌شده: ' + PU.formatToman(o.refundAmount) + '</div>' : '') +
      '</div>';
    }

    function paintOrders() {
      const st = $host.find('#adm-status .chip.active').data('st') || 'all';
      const q = (($host.find('#adm-search').val()) || '').trim().toLowerCase();
      const items = (api.state.orders || []).filter((o) => {
        if (st !== 'all' && o.status !== st) return false;
        if (q) {
          const hay = (o.orderNumber + ' ' + o.customerName + ' ' + o.customerPhone + ' ' +
            o.serviceTitle + ' ' + (o.assignedOperatorName || '')).toLowerCase();
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const $list = $host.find('#adm-list');
      if (!items.length) { $list.html(UI.emptyState('سفارشی مطابق فیلتر نیست', '', 'folder')); return; }
      $list.html(items.map(orderCard).join(''));
      $list.find('[data-order]').on('click', function () { openOrderDetail($(this).data('order')); });
    }

    function openOrderDetail(orderId) {
      api.fetchOrderById(orderId).then((o) => {
        api.getUsers().then((users) => {
          const operators = (users || []).filter((u) => u.role === 'operator' || u.role === 'admin');
          // `refunded` is intentionally NOT a manual status choice: it is only
          // reached through the refund action, which also credits the wallet.
          const STATUSES = [
            ['submitted', 'ثبت‌شده'], ['processing', 'در حال انجام'], ['ready', 'آماده تحویل'],
            ['delivered', 'تحویل شده'], ['cancelled', 'لغو شده'],
          ];
          const sheet = UI.sheet({
            title: 'جزئیات سفارش',
            subtitle: o.orderNumber + ' — ' + o.customerName,
            icon: 'folder', tone: 'indigo', size: 'tall',
          });

          sheet.body.html(
            '<div class="card" style="margin-bottom:12px">' +
              '<div style="display:flex;justify-content:space-between;align-items:center"><b>' + PU.escapeHtml(o.serviceTitle) + '</b>' + UI.statusBadge(o.status) + '</div>' +
              '<div style="font-size:11.5px;color:var(--ink-soft);line-height:2;margin-top:8px">' +
                'مشتری: <b>' + PU.escapeHtml(o.customerName) + '</b> — ' + PU.toPersianDigits(o.customerPhone) + '<br>' +
                'مبلغ پرداختی: <b>' + PU.formatToman(o.paidAmount) + '</b>' +
                (o.refundAmount ? '<br>مبلغ عودت‌شده: <b>' + PU.formatToman(o.refundAmount) + '</b>' : '') + '<br>' +
                'اپراتور انجام‌دهنده: <b>' + PU.escapeHtml(o.assignedOperatorName || '—') + '</b>' +
              '</div>' +
            '</div>' +

            '<div class="section-title">' + icon('refresh') + 'تغییر وضعیت</div>' +
            '<div class="chip-row" id="od-status">' +
              STATUSES.map(([k, l]) => '<span class="chip ' + (o.status === k ? 'active' : '') + '" data-newstatus="' + k + '">' + l + '</span>').join('') +
            '</div>' +

            '<div class="section-title">' + icon('user') + 'ارجاع به اپراتور</div>' +
            '<select class="select" id="od-op">' +
              '<option value="">بدون تغییر</option>' +
              operators.map((u) => '<option value="' + u.id + '"' + (o.assignedOperatorId === u.id ? ' selected' : '') + '>' + PU.escapeHtml(u.name) + '</option>').join('') +
            '</select>' +
            '<button class="btn ghost block sm" id="od-assign" style="margin-top:8px">ثبت اپراتور</button>' +

            '<div class="section-title">' + icon('clock') + 'روند سفارش (ردیابی عملیات)</div>' +
            '<div class="timeline">' + (o.logs || []).map((l) =>
              '<div class="tl-item"><div class="tl-action">' + PU.escapeHtml(l.action) + '</div>' +
              '<div class="tl-meta">' + PU.escapeHtml(l.performedBy) + ' • ' + PU.toJalaliDate(l.timestamp) + '</div>' +
              (l.note ? '<div class="tl-note">' + PU.escapeHtml(l.note) + '</div>' : '') + '</div>').join('') + '</div>' +

            (canRefund ? '<button class="btn rose block" id="od-refund" style="margin-top:14px">' + icon('wallet') + 'عودت وجه به کیف پول مشتری</button>' : '')
          );

          sheet.body.find('[data-newstatus]').on('click', async function () {
            const status = $(this).data('newstatus');
            try {
              await api.updateOrderStatus(o.id, status);
              UI.toast('وضعیت به‌روزرسانی شد.', 'success');
              sheet.close();
            } catch (e) { UI.toast(e.message, 'error'); }
          });

          sheet.body.find('#od-assign').on('click', async function () {
            const opId = sheet.body.find('#od-op').val();
            if (!opId) { UI.toast('اپراتوری انتخاب نشده است.', 'warning'); return; }
            try {
              await api.updateOrder(o.id, { assignedOperatorId: opId });
              UI.toast('اپراتور ثبت شد.', 'success');
              sheet.close();
            } catch (e) { UI.toast(e.message, 'error'); }
          });

          if (canRefund) sheet.body.find('#od-refund').on('click', function () {
            const amount = Math.max(0, o.paidAmount || (o.totalPrice - o.discountAmount));
            openOrderRefund(o, amount);
          });
        }).catch((e) => UI.toast(e.message, 'error'));
      }).catch((e) => UI.toast(e.message, 'error'));
    }

    function openOrderRefund(order, amount) {
      const sheet = UI.sheet({ title: 'عودت وجه سفارش', subtitle: order.orderNumber, icon: 'wallet', tone: 'amber' });
      sheet.body.html(
        '<div class="info-box">مبلغ به کیف پول «' + PU.escapeHtml(order.customerName) + '» واریز می‌شود و سفارش «عودت شده» علامت می‌خورد.</div>' +
        '<label class="field-label">مبلغ عودت (تومان) <span class="req">*</span></label>' +
        '<input class="input" id="or-amount" inputmode="numeric" value="' + amount + '">' +
        '<label class="field-label">دلیل (اختیاری)</label>' +
        '<input class="input" id="or-reason">'
      );
      sheet.footer.html('<button class="btn rose block" id="or-save">ثبت عودت وجه</button>');
      sheet.footer.find('#or-save').on('click', async function () {
        const amt = Number(PU.toLatinDigits(sheet.body.find('#or-amount').val()).replace(/[^\d]/g, ''));
        if (!amt) { UI.toast('مبلغ معتبر وارد کنید.', 'warning'); return; }
        UI.busy(true);
        try {
          await api.refundWallet({ userId: order.customerId, orderId: order.id, amount: amt, reason: sheet.body.find('#or-reason').val() });
          UI.toast('عودت وجه ثبت شد.', 'success');
          sheet.close();
          api.syncWithBackend();
        } catch (e) { UI.toast(e.message, 'error'); }
        finally { UI.busy(false); }
      });
    }

    /* ================= wallet ledger ================= */

    function walletTxnRow(t) {
      const credit = t.direction === 'credit';
      return '<div class="list-row">' +
        '<div class="lr-icon ' + (credit ? 'tone-emerald' : 'tone-rose') + '">' + icon(credit ? 'plus' : 'arrowleft') + '</div>' +
        '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(t.typeTitle || TYPE_TITLES[t.type] || t.type) +
          ' — <span style="font-size:11px;color:var(--ink-soft)">' + PU.escapeHtml(t.userId) + '</span></div>' +
        '<div class="lr-sub">' + PU.toJalaliDate(t.createdAt) + ' • موجودی پس از تراکنش: ' + PU.formatToman(t.balanceAfter) +
          (t.performedByName ? ' • توسط: ' + PU.escapeHtml(t.performedByName) : '') +
          (t.note ? ' • ' + PU.escapeHtml(t.note) : '') + '</div></div>' +
        '<span style="font-weight:800;font-size:12.5px;color:' + (credit ? 'var(--emerald)' : 'var(--rose)') + '">' +
          (credit ? '+' : '−') + PU.formatToman(t.amount) + '</span>' +
        '</div>';
    }

    function renderWallet() {
      const $b = $host.find('#adm-body');
      $b.html(
        ((canAdjust || canRefund) ? '<button class="btn block sm" id="adm-adjust" style="margin-bottom:10px">' + icon('wallet') + 'عودت / شارژ / کسر دستی کیف پول</button>' : '') +
        '<div class="search-box">' + icon('search') +
          '<input class="input" id="adm-wsearch" placeholder="جستجو: شناسه کاربر، مرجع یا توضیح…"></div>' +
        '<div class="chip-row" id="adm-wtype">' +
          '<span class="chip active" data-type="all">همه</span>' +
          '<span class="chip" data-type="topup">شارژ درگاه</span>' +
          '<span class="chip" data-type="purchase">خرید خدمت</span>' +
          '<span class="chip" data-type="refund">عودت وجه</span>' +
          '<span class="chip" data-type="manual_credit">شارژ دستی</span>' +
          '<span class="chip" data-type="manual_debit">کسر دستی</span>' +
        '</div>' +
        '<div id="adm-wlist" style="margin-top:12px"><div class="spinner" style="margin:20px auto"></div></div>'
      );
      loadWallet();
      $b.find('#adm-adjust').on('click', openWalletAdjustSheet);
      $b.find('#adm-wsearch').on('input', PU.debounce(paintWallet, 200));
      $b.find('#adm-wtype .chip').on('click', function () {
        $b.find('#adm-wtype .chip').removeClass('active');
        $(this).addClass('active');
        paintWallet();
      });
    }

    function loadWallet() {
      const $list = $host.find('#adm-wlist');
      if ($list.length) $list.html('<div class="spinner" style="margin:20px auto"></div>');
      api.getWalletTransactions()
        .then((txns) => { walletTxns = txns || []; paintWallet(); })
        .catch((e) => { if ($list.length) $list.html(UI.emptyState('خطا در بارگذاری تراکنش‌ها', e.message, 'alert')); });
    }

    function paintWallet() {
      const type = $host.find('#adm-wtype .chip.active').data('type') || 'all';
      const q = (($host.find('#adm-wsearch').val()) || '').trim().toLowerCase();
      const items = walletTxns.filter((t) => {
        if (type !== 'all' && t.type !== type) return false;
        if (q) {
          const hay = (t.userId + ' ' + (t.reference || '') + ' ' + (t.note || '') + ' ' + (t.typeTitle || '')).toLowerCase();
          if (!hay.includes(q)) return false;
        }
        return true;
      });
      const $list = $host.find('#adm-wlist');
      if (!items.length) { $list.html(UI.emptyState('تراکنشی یافت نشد', '', 'wallet')); return; }
      $list.html('<div class="card">' + items.map(walletTxnRow).join('') + '</div>');
    }

    function openWalletAdjustSheet() {
      const sheet = UI.sheet({ title: 'گردش مالی کیف پول', subtitle: 'عودت / شارژ / کسر دستی', icon: 'wallet', tone: 'amber', size: 'tall' });
      sheet.body.html('<div class="spinner" style="margin:20px auto"></div>');
      api.getUsers().then((users) => {
        const customers = (users || []).filter((u) => u.role === 'customer');
        if (!customers.length) { sheet.body.html(UI.emptyState('مشتری‌ای ثبت نشده', '', 'users')); return; }
        const modes = [];
        if (canRefund) modes.push(['refund', 'عودت وجه']);
        if (canAdjust) modes.push(['credit', 'شارژ دستی'], ['debit', 'کسر دستی']);
        sheet.body.html(
          '<label class="field-label">کاربر <span class="req">*</span></label>' +
          '<select class="select" id="wa-user">' +
            customers.map((u) => '<option value="' + u.id + '">' + PU.escapeHtml(u.name) + ' — ' + PU.toPersianDigits(u.phone) + ' (' + PU.formatToman(u.walletBalance) + ')</option>').join('') +
          '</select>' +
          '<label class="field-label">نوع عملیات</label>' +
          '<div class="segmented" id="wa-mode">' +
            modes.map(([k, l], i) => '<button type="button" class="seg ' + (i === 0 ? 'on' : '') + '" data-mode="' + k + '">' + l + '</button>').join('') +
          '</div>' +
          '<label class="field-label">مبلغ (تومان) <span class="req">*</span></label>' +
          '<input class="input" id="wa-amount" inputmode="numeric">' +
          '<label class="field-label">دلیل / توضیح</label>' +
          '<input class="input" id="wa-reason">'
        );
        let mode = modes.length ? modes[0][0] : 'refund';
        sheet.body.find('#wa-mode .seg').on('click', function () {
          sheet.body.find('#wa-mode .seg').removeClass('on');
          $(this).addClass('on');
          mode = $(this).data('mode');
        });
        sheet.footer.html('<button class="btn emerald block" id="wa-save">ثبت</button>');
        sheet.footer.find('#wa-save').on('click', async function () {
          const userId = sheet.body.find('#wa-user').val();
          const amount = Number(PU.toLatinDigits(sheet.body.find('#wa-amount').val()).replace(/[^\d]/g, ''));
          const reason = sheet.body.find('#wa-reason').val();
          if (!userId) { UI.toast('کاربر را انتخاب کنید.', 'warning'); return; }
          if (!amount) { UI.toast('مبلغ معتبر وارد کنید.', 'warning'); return; }
          UI.busy(true);
          try {
            if (mode === 'refund') {
              await api.refundWallet({ userId, amount, reason });
            } else {
              await api.adjustWallet({ userId, amount, direction: mode, reason });
            }
            UI.toast('عملیات با موفقیت ثبت شد.', 'success');
            sheet.close();
            view = 'wallet';
            loadWallet();
            api.syncWithBackend();
          } catch (e) { UI.toast(e.message, 'error'); }
          finally { UI.busy(false); }
        });
      }).catch((e) => { sheet.body.html(UI.emptyState('خطا', e.message, 'alert')); });
    }

    /* ================= view switching ================= */

    function switchView(v) {
      view = v;
      $host.find('#adm-view .seg').removeClass('on').filter('[data-view="' + v + '"]').addClass('on');
      if (v === 'orders') renderOrders(); else renderWallet();
    }

    $host.find('#adm-view .seg').on('click', function () { switchView($(this).data('view')); });
    switchView('orders');

    // In-place refresh on background sync (keeps filters/search intact).
    $host.data('caffenetRefresh', function () {
      if (view === 'orders') paintOrders(); else paintWallet();
    });
  };
})();
