/* Caffenet Lite — screens/admin-orders.js
 * Admin monitoring hub: every customer's orders (with the assigned operator and
 * full operation timeline) plus the per-user wallet ledger. The «User
 * Transactions» tab lists the users that have a wallet history; selecting one
 * opens a dedicated screen with that user's full, filterable transaction log.
 * Order-based refunds and free-form wallet adjustments stay available. */
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

  /* One ledger row. `showUser` (default true) appends the wallet owner id —
   * useful in a cross-user list, hidden on a single-user page. */
  function walletTxnRow(t, showUser) {
    const credit = t.direction === 'credit';
    return '<div class="list-row">' +
      '<div class="lr-icon ' + (credit ? 'tone-emerald' : 'tone-rose') + '">' + icon(credit ? 'plus' : 'arrowleft') + '</div>' +
      '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(t.typeTitle || TYPE_TITLES[t.type] || t.type) +
        (showUser !== false ? ' — <span style="font-size:11px;color:var(--ink-soft)">' + PU.escapeHtml(t.userId) + '</span>' : '') +
      '</div>' +
      '<div class="lr-sub">' + PU.toJalaliDate(t.createdAt) + ' • موجودی پس از تراکنش: ' + PU.formatToman(t.balanceAfter) +
        (t.performedByName ? ' • توسط: ' + PU.escapeHtml(t.performedByName) : '') +
        (t.note ? ' • ' + PU.escapeHtml(t.note) : '') + '</div></div>' +
      '<span style="font-weight:800;font-size:12.5px;color:' + (credit ? 'var(--emerald)' : 'var(--rose)') + '">' +
        (credit ? '+' : '−') + PU.formatToman(t.amount) + '</span>' +
      '</div>';
  }

  const TYPE_CHIPS =
    '<span class="chip active" data-type="all">همه</span>' +
    '<span class="chip" data-type="topup">شارژ درگاه</span>' +
    '<span class="chip" data-type="purchase">خرید خدمت</span>' +
    '<span class="chip" data-type="refund">عودت وجه</span>' +
    '<span class="chip" data-type="manual_credit">شارژ دستی</span>' +
    '<span class="chip" data-type="manual_debit">کسر دستی</span>';

  Screens.adminOrders = function (ctx) {
    if (!ctx.user || ctx.user.role !== 'admin') {
      return UI.emptyState('دسترسی محدود', 'این بخش برای مدیران است', 'lock');
    }
    const canViewUsers = api.hasPermission('wallet.users') || api.hasPermission('wallet.manage');
    return (
      '<div class="segmented" id="adm-view">' +
        '<button class="seg on" data-view="orders">سفارش‌ها</button>' +
        (canViewUsers ? '<button class="seg" data-view="users">تراکنش‌های کاربران</button>' : '') +
      '</div>' +
      '<div id="adm-body" style="margin-top:12px"></div>'
    );
  };

  Screens.bind_admin_orders = function ($host, ctx) {
    let view = 'orders';
    let walletUsers = [];
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

    /* ================= user transactions (roster) ================= */

    function usersRow(u) {
      return '<div class="list-row" data-user="' + u.id + '" style="cursor:pointer">' +
        '<div class="lr-icon tone-sky">' + icon('user') + '</div>' +
        '<div class="lr-main"><div class="lr-title">' + PU.escapeHtml(u.name || '—') + '</div>' +
        '<div class="lr-sub">' + PU.toPersianDigits(u.phone) +
          ' • موجودی: ' + PU.formatToman(u.walletBalance) +
          ' • ' + PU.toPersianDigits(u.txnCount) + ' تراکنش' +
          ' • آخرین: ' + PU.toJalaliDateShort(u.lastTransactionAt) + '</div></div>' +
        icon('arrowleft') + '</div>';
    }

    function renderUsers() {
      const $b = $host.find('#adm-body');
      $b.html(
        ((canAdjust || canRefund) ? '<button class="btn block sm" id="adm-adjust" style="margin-bottom:10px">' + icon('wallet') + 'عودت / شارژ / کسر دستی کیف پول</button>' : '') +
        '<div class="search-box">' + icon('search') +
          '<input class="input" id="adm-usearch" placeholder="جستجو: نام یا شماره کاربر…"></div>' +
        '<div id="adm-ulist" style="margin-top:12px"><div class="spinner" style="margin:20px auto"></div></div>'
      );
      $b.find('#adm-adjust').on('click', openWalletAdjustSheet);
      $b.find('#adm-usearch').on('input', PU.debounce(function () {
        loadUsers(($b.find('#adm-usearch').val() || '').trim());
      }, 250));
      loadUsers('');
    }

    function loadUsers(search) {
      const $list = $host.find('#adm-ulist');
      if ($list.length) $list.html('<div class="spinner" style="margin:20px auto"></div>');
      api.getWalletUsers(search)
        .then((users) => { walletUsers = users || []; paintUsers(); })
        .catch((e) => { if ($list.length) $list.html(UI.emptyState('خطا در بارگذاری کاربران', e.message, 'alert')); });
    }

    function paintUsers() {
      const $list = $host.find('#adm-ulist');
      if (!walletUsers.length) { $list.html(UI.emptyState('کاربری با تراکنش یافت نشد', '', 'users')); return; }
      $list.html('<div class="card">' + walletUsers.map(usersRow).join('') + '</div>');
      $list.find('[data-user]').on('click', function () {
        if (window.App) App.navigate('admin/user-transactions/' + $(this).data('user'));
      });
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
            view = 'users';
            renderUsers();
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
      if (v === 'orders') renderOrders();
      else renderUsers();
    }

    $host.find('#adm-view .seg').on('click', function () { switchView($(this).data('view')); });
    // The roster tab is only offered when the operator holds wallet.users; the
    // default view is always the orders list.
    switchView('orders');

    // In-place refresh on background sync (keeps filters/search intact).
    $host.data('caffenetRefresh', function () {
      if (view === 'orders') paintOrders();
      else paintUsers();
    });
  };

  /* ================= per-user transaction history ================= */

  Screens.adminUserTransactions = function (ctx) {
    if (!ctx.user || ctx.user.role !== 'admin') {
      return UI.emptyState('دسترسی محدود', 'این بخش برای مدیران است', 'lock');
    }
    return (
      '<button class="btn ghost block sm" id="ut-back" style="margin-bottom:10px">' + icon('arrowright') + 'بازگشت به فهرست کاربران</button>' +
      '<div id="ut-head"><div class="spinner" style="margin:16px auto"></div></div>' +
      '<div class="search-box" style="margin-top:10px">' + icon('search') +
        '<input class="input" id="ut-search" placeholder="جستجو: مرجع، توضیح، شناسه سفارش…"></div>' +
      '<div class="section-title">' + icon('search') + 'فیلترها</div>' +
      '<div class="chip-row" id="ut-type">' + TYPE_CHIPS + '</div>' +
      '<div class="chip-row" id="ut-dir">' +
        '<span class="chip active" data-dir="all">هر دو جهت</span>' +
        '<span class="chip" data-dir="credit">واریز</span>' +
        '<span class="chip" data-dir="debit">برداشت</span>' +
      '</div>' +
      '<div style="display:flex;gap:8px;margin-top:10px">' +
        '<div style="flex:1"><label class="field-label">از تاریخ</label>' +
          '<input class="input" id="ut-from-view" data-jdp data-jdp-target-value-input="#ut-from" data-jdp-target-value-type="gregorian" readonly placeholder="انتخاب تاریخ…">' +
          '<input type="hidden" id="ut-from"></div>' +
        '<div style="flex:1"><label class="field-label">تا تاریخ</label>' +
          '<input class="input" id="ut-to-view" data-jdp data-jdp-target-value-input="#ut-to" data-jdp-target-value-type="gregorian" readonly placeholder="انتخاب تاریخ…">' +
          '<input type="hidden" id="ut-to"></div>' +
      '</div>' +
      '<button class="btn ghost block sm" id="ut-apply" style="margin-top:8px">' + icon('search') + 'اعمال فیلترها</button>' +
      '<div id="ut-list" style="margin-top:12px"><div class="spinner" style="margin:20px auto"></div></div>'
    );
  };

  Screens.bind_admin_user_transactions = function ($host, ctx) {
    const userId = ctx.userId || '';
    const canRefund = api.hasPermission('wallet.refund') || api.hasPermission('wallet.manage');
    let user = null;

    $host.find('#ut-back').on('click', function () {
      if (window.App) App.navigate('admin/orders');
    });

    function collectFilters() {
      const type = $host.find('#ut-type .chip.active').data('type') || 'all';
      const dir = $host.find('#ut-dir .chip.active').data('dir') || 'all';
      const from = ($host.find('#ut-from').val() || '').trim();
      const to = ($host.find('#ut-to').val() || '').trim();
      const search = (($host.find('#ut-search').val()) || '').trim();
      const params = { userId: userId };
      if (type !== 'all') params.type = type;
      if (dir !== 'all') params.direction = dir;
      // `created_at` is a full ISO-8601 timestamp; widen the picked day to its
      // boundaries so the range is inclusive of both ends.
      if (from) params.from = from + 'T00:00:00';
      if (to) params.to = to + 'T23:59:59';
      if (search) params.search = search;
      return params;
    }

    function renderHead() {
      if (!user) { $host.find('#ut-head').html(UI.emptyState('کاربر یافت نشد', '', 'user')); return; }
      $host.find('#ut-head').html(
        '<div class="card">' +
          '<div style="display:flex;justify-content:space-between;align-items:center">' +
            '<b>' + PU.escapeHtml(user.name || '—') + '</b>' +
            '<span style="font-weight:800;color:var(--sky-deep)">' + PU.formatToman(user.walletBalance) + '</span></div>' +
          '<div style="font-size:11.5px;color:var(--ink-soft);margin-top:6px">' + PU.toPersianDigits(user.phone) +
            (user.txnCount != null ? ' • ' + PU.toPersianDigits(user.txnCount) + ' تراکنش' : '') + '</div>' +
          (canRefund ? '<button class="btn rose block sm" id="ut-refund" style="margin-top:10px">' + icon('wallet') + 'عودت وجه به کیف پول کاربر</button>' : '') +
        '</div>'
      );
      if (canRefund) $host.find('#ut-refund').on('click', openRefund);
    }

    function loadHead() {
      // Uses the wallet roster (wallet.users) rather than /api/users so an
      // operator without users.view can still see the header.
      api.getWalletUsers('').then((rows) => {
        user = (rows || []).find((u) => u.id === userId) || null;
        renderHead();
      }).catch((e) => { $host.find('#ut-head').html(UI.emptyState('خطا در بارگذاری کاربر', e.message, 'alert')); });
    }

    function loadList() {
      const $list = $host.find('#ut-list');
      $list.html('<div class="spinner" style="margin:20px auto"></div>');
      api.getWalletTransactions(collectFilters())
        .then((rows) => {
          const items = rows || [];
          $list.html(items.length
            ? '<div class="card">' + items.map((t) => walletTxnRow(t, false)).join('') + '</div>'
            : UI.emptyState('تراکنشی مطابق فیلتر نیست', '', 'wallet'));
        })
        .catch((e) => { $list.html(UI.emptyState('خطا در بارگذاری تراکنش‌ها', e.message, 'alert')); });
    }

    function openRefund() {
      if (!user) return;
      const sheet = UI.sheet({ title: 'عودت وجه', subtitle: user.name || '', icon: 'wallet', tone: 'amber' });
      sheet.body.html(
        '<div class="info-box">مبلغ به کیف پول «' + PU.escapeHtml(user.name || '') + '» واریز می‌شود.</div>' +
        '<label class="field-label">مبلغ (تومان) <span class="req">*</span></label>' +
        '<input class="input" id="utr-amount" inputmode="numeric">' +
        '<label class="field-label">دلیل (اختیاری)</label>' +
        '<input class="input" id="utr-reason">'
      );
      sheet.footer.html('<button class="btn rose block" id="utr-save">ثبت عودت</button>');
      sheet.footer.find('#utr-save').on('click', async function () {
        const amt = Number(PU.toLatinDigits(sheet.body.find('#utr-amount').val()).replace(/[^\d]/g, ''));
        if (!amt) { UI.toast('مبلغ معتبر وارد کنید.', 'warning'); return; }
        UI.busy(true);
        try {
          await api.refundWallet({ userId: user.id, amount: amt, reason: sheet.body.find('#utr-reason').val() });
          UI.toast('عودت وجه ثبت شد.', 'success');
          sheet.close();
          loadHead();
          loadList();
        } catch (e) { UI.toast(e.message, 'error'); }
        finally { UI.busy(false); }
      });
    }

    $host.find('#ut-type .chip').on('click', function () {
      $host.find('#ut-type .chip').removeClass('active');
      $(this).addClass('active');
      loadList();
    });
    $host.find('#ut-dir .chip').on('click', function () {
      $host.find('#ut-dir .chip').removeClass('active');
      $(this).addClass('active');
      loadList();
    });
    $host.find('#ut-apply').on('click', loadList);
    $host.find('#ut-search').on('input', PU.debounce(loadList, 250));
    $host.find('#ut-from-view, #ut-to-view').on('change', loadList);

    loadHead();
    loadList();

    // In-place refresh on background sync.
    $host.data('caffenetRefresh', loadList);
  };
})();
