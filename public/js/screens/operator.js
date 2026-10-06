/* Caffenet Lite — screens/operator.js
 * Operator work queue: paid orders only (never pending_payment/failed),
 * filters, detail sheet with status/result-file/assign/notes/chat.
 * Port of operator/OperatorDashboard.tsx. */
(function () {
  'use strict';

  const NON_QUEUE_STATUSES = ['pending_payment', 'failed'];

  window.Screens = window.Screens || {};

  Screens.operator = function (ctx) {
    if (!ctx.user || !['operator', 'admin'].includes(ctx.user.role)) {
      return UI.emptyState('این بخش برای اپراتورها است', 'وارد حساب اپراتوری شوید', 'lock');
    }

    let html =
      '<div class="search-box">' + icon('search') +
      '<input class="input" id="op-search" placeholder="جستجو: شماره سفارش، نام یا خدمت…"></div>' +
      '<div class="chip-row" id="op-chips">' +
        '<span class="chip active" data-st="queue">صف کار</span>' +
        '<span class="chip" data-st="processing">در حال انجام</span>' +
        '<span class="chip" data-st="ready">آماده تحویل</span>' +
        '<span class="chip" data-st="delivered">تحویل شده</span>' +
        '<span class="chip" data-st="all">همه</span>' +
      '</div>' +
      '<div id="op-stats" style="margin:12px 0"></div>' +
      '<div id="op-list"></div>';
    return html;
  };

  Screens.bind_operator = function ($host, ctx) {
    function filteredOrders() {
      const st = $host.find('#op-chips .chip.active').data('st') || 'queue';
      const q = ($('#op-search').val() || '').trim().toLowerCase();
      return api.state.orders.filter((o) => {
        if (st === 'queue') {
          if (NON_QUEUE_STATUSES.includes(o.status)) return false;
        } else if (st !== 'all') {
          if (o.status !== st) return false;
        }
        if (q) {
          const hay = (o.orderNumber + ' ' + o.customerName + ' ' + o.customerPhone + ' ' + o.serviceTitle).toLowerCase();
          if (!hay.includes(q)) return false;
        }
        return true;
      });
    }

    function renderStats() {
      const all = api.state.orders;
      const queue = all.filter((o) => !NON_QUEUE_STATUSES.includes(o.status));
      const processing = queue.filter((o) => o.status === 'processing').length;
      const ready = queue.filter((o) => o.status === 'ready').length;
      $host.find('#op-stats').html(
        '<div class="stat-grid">' +
          '<div class="stat-card"><div class="s-icon tone-sky">' + icon('folder') + '</div>' +
            '<div class="s-label">صف کار</div><div class="s-value">' + PU.toPersianDigits(queue.length) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-indigo">' + icon('clock') + '</div>' +
            '<div class="s-label">در حال انجام</div><div class="s-value">' + PU.toPersianDigits(processing) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-emerald">' + icon('checkcircle') + '</div>' +
            '<div class="s-label">آماده تحویل</div><div class="s-value">' + PU.toPersianDigits(ready) + '</div></div>' +
          '<div class="stat-card"><div class="s-icon tone-amber">' + icon('star') + '</div>' +
            '<div class="s-label">امتیاز مشتریان</div><div class="s-value">' + PU.toPersianDigits(avgRating(all)) + '</div></div>' +
        '</div>'
      );
    }

    function avgRating(orders) {
      const rated = orders.filter((o) => o.rating);
      if (!rated.length) return '—';
      return (rated.reduce((s, o) => s + o.rating, 0) / rated.length).toFixed(1);
    }

    function renderList() {
      const orders = filteredOrders();
      const $list = $host.find('#op-list');
      if (!orders.length) {
        $list.html(UI.emptyState('سفارشی در این دسته نیست', '', 'folder'));
        return;
      }
      $list.html(orders.map((o) =>
        '<div class="order-card" data-order="' + o.id + '" style="margin-bottom:10px">' +
          '<div class="oc-head"><span class="oc-num">' + PU.toPersianDigits(o.orderNumber) + '</span>' + UI.statusBadge(o.status) + '</div>' +
          '<div class="oc-title">' + PU.escapeHtml(o.serviceTitle) + ' — ' + PU.escapeHtml(o.customerName) + '</div>' +
          '<div class="oc-foot">' +
            '<span class="oc-price">' + PU.formatToman(o.paidAmount) + '</span>' +
            '<span style="font-size:11px;color:var(--ink-soft)">' + PU.toJalaliDateShort(o.createdAt) + '</span>' +
          '</div>' +
        '</div>'
      ).join(''));
      $list.find('[data-order]').on('click', function () {
        openDetail($(this).data('order'));
      });
    }

    function openDetail(orderId) {
      api.fetchOrderById(orderId).then((o) => {
        const sheet = UI.sheet({
          title: 'جزئیات سفارش',
          subtitle: o.orderNumber + ' — ' + o.customerName,
          icon: 'folder',
          tone: 'indigo',
          size: 'tall',
        });

        const STATUSES = [
          ['submitted', 'ثبت‌شده'], ['processing', 'در حال انجام'], ['ready', 'آماده تحویل'],
          ['delivered', 'تحویل داده شده'], ['cancelled', 'لغو شده'],
        ];

        sheet.body.html(
          '<div class="card" style="margin-bottom:12px">' +
            '<div style="display:flex;justify-content:space-between;align-items:center"><b>' + PU.escapeHtml(o.serviceTitle) + '</b>' + UI.statusBadge(o.status) + '</div>' +
            '<div style="font-size:11.5px;color:var(--ink-soft);line-height:2;margin-top:8px">' +
              'مشتری: <b>' + PU.escapeHtml(o.customerName) + '</b> — ' + PU.toPersianDigits(o.customerPhone) + '<br>' +
              'مبلغ پرداختی: <b>' + PU.formatToman(o.paidAmount) + '</b><br>' +
              'تخفیف: ' + PU.formatToman(o.discountAmount) + '<br>' +
              'روش دریافت: ' + ({ download: 'دانلود آنلاین', in_person: 'حضوری', courier: 'پیک' }[o.deliveryMethod] || o.deliveryMethod) +
              (o.deliveryAddress ? '<br>آدرس: ' + PU.escapeHtml(o.deliveryAddress) : '') +
            '</div>' +
          '</div>' +

          (Object.keys(o.formData || {}).length ?
            '<div class="section-title">' + icon('receipt') + 'مشخصات سفارش</div>' +
            '<div class="card">' + Object.entries(o.formData).map(([k, v]) =>
              '<div class="list-row" style="padding:7px 0"><div class="lr-main"><div class="lr-sub" style="margin:0">' + PU.escapeHtml(k) + '</div>' +
              '<div class="lr-title" style="font-size:12.5px">' + PU.escapeHtml(String(v)) + '</div></div></div>').join('') + '</div>' : '') +

          '<div class="section-title">' + icon('edit') + 'تغییر وضعیت</div>' +
          '<div class="chip-row" id="status-chips">' +
            STATUSES.map(([k, l]) => '<span class="chip ' + (o.status === k ? 'active' : '') + '" data-newstatus="' + k + '">' + l + '</span>').join('') +
          '</div>' +

          '<label class="field-label">یادداشت پرسنلی (داخلی)</label>' +
          '<textarea class="textarea" id="op-note" rows="2">' + PU.escapeHtml(o.internalNote || '') + '</textarea>' +
          '<button class="btn ghost block sm" id="save-note" style="margin-top:8px">ذخیره یادداشت</button>' +

          '<div class="section-title">' + icon('upload') + 'بارگذاری فایل خروجی</div>' +
          '<div id="result-files">' +
            (o.resultFiles || []).map((f) =>
              '<a class="file-chip" href="' + PU.escapeHtml(f.url) + '" target="_blank" style="text-decoration:none;color:inherit">' +
              '<div class="fc-icon">' + icon('file') + '</div>' +
              '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div><div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
              icon('download') + '</div>').join('') +
          '</div>' +
          '<button class="btn block sm" id="upload-result">' + icon('upload') + 'انتخاب فایل خروجی</button>' +
          '<input type="file" id="result-input" class="hidden">' +

          '<div class="section-title">' + icon('users') + 'گفتگو با مشتری</div>' +
          '<div id="chat-box">' +
            (o.ticketMessages || []).map((m) =>
              '<div class="chat-bubble ' + (m.isOperatorResponse ? 'me' : 'them') + '">' +
              '<div class="who">' + PU.escapeHtml(m.senderName) + '</div>' + PU.escapeHtml(m.message) +
              '<div class="when">' + PU.toJalaliDate(m.timestamp) + '</div></div>').join('') +
          '</div>' +
          '<div style="display:flex;gap:8px;margin-top:8px">' +
            '<input class="input" id="op-chat" placeholder="پاسخ به مشتری…">' +
            '<button class="btn" id="op-chat-send">' + icon('send') + '</button>' +
          '</div>'
        );

        sheet.body.find('[data-newstatus]').on('click', async function () {
          const status = $(this).data('newstatus');
          try {
            await api.updateOrderStatus(o.id, status);
            UI.toast('وضعیت به‌روزرسانی شد.', 'success');
            sheet.close();
          } catch (e) { UI.toast(e.message, 'error'); }
        });

        sheet.body.find('#save-note').on('click', async function () {
          try {
            await api.updateOrder(o.id, { internalNote: sheet.body.find('#op-note').val() });
            UI.toast('یادداشت ذخیره شد.', 'success');
          } catch (e) { UI.toast(e.message, 'error'); }
        });

        sheet.body.find('#upload-result').on('click', () => sheet.body.find('#result-input').trigger('click'));
        sheet.body.find('#result-input').on('change', async function () {
          const file = this.files[0];
          if (!file) return;
          UI.busy(true);
          try {
            await api.uploadOrderResult(o.id, file);
            UI.toast('فایل خروجی بارگذاری شد.', 'success');
            sheet.close();
          } catch (e) {
            UI.toast(e.message, 'error');
          } finally { UI.busy(false); }
          $(this).val('');
        });

        sheet.body.find('#op-chat-send').on('click', async () => {
          const msg = (sheet.body.find('#op-chat').val() || '').trim();
          if (!msg) return;
          try {
            await api.addTicketMessage(o.id, msg);
            UI.toast('پیام ارسال شد.', 'success');
            sheet.close();
          } catch (e) { UI.toast(e.message, 'error'); }
        });
      }).catch((e) => UI.toast(e.message, 'error'));
    }

    renderStats();
    renderList();
    $host.find('#op-search').on('input', PU.debounce(renderList, 200));
    $host.find('#op-chips .chip').on('click', function () {
      $host.find('#op-chips .chip').removeClass('active');
      $(this).addClass('active');
      renderList();
    });

    // Live refresh on background sync: repaint the queue in place (no rebuild).
    $host.data('caffenetRefresh', function () { renderStats(); renderList(); });
  };
})();
