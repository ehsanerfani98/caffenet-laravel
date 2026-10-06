/* Caffenet Lite — screens/order-tracker.js
 * Order tracking sheet with timeline / files / chat tabs, rating and
 * payment retry. Port of customer/OrderTrackerModal.tsx (5s polling). */
(function () {
  'use strict';

  let pollTimer = null;

  window.Screens = window.Screens || {};

  Screens.openOrderTracker = function (orderId) {
    let current = null;
    let tab = 'timeline';

    const sheet = UI.sheet({
      title: 'پیگیری سفارش',
      subtitle: '',
      icon: 'folder',
      tone: 'indigo',
      size: 'tall',
      onClose: () => clearInterval(pollTimer),
    });

    async function refresh(silent) {
      try {
        current = await api.fetchOrderById(orderId);
        sheet.el.find('.sh-sub').text(PU.toPersianDigits(current.orderNumber));
        render();
      } catch (e) {
        if (!silent) UI.toast(e.message, 'error');
      }
    }

    function render() {
      const o = current;
      if (!o) return;

      const staff = ['admin', 'operator'].includes(api.state.currentUser?.role);
      const isOwner = o.customerId === api.state.currentUser?.id;
      const paid = o.paidAmount > 0;
      const retryable = ['pending_payment', 'failed'].includes(o.status) && isOwner;

      const tabs =
        '<div class="segmented" style="margin-bottom:14px">' +
          '<button class="seg ' + (tab === 'timeline' ? 'on' : '') + '" data-tab="timeline">روند سفارش</button>' +
          '<button class="seg ' + (tab === 'files' ? 'on' : '') + '" data-tab="files">فایل‌ها</button>' +
          '<button class="seg ' + (tab === 'chat' ? 'on' : '') + '" data-tab="chat">گفتگو</button>' +
        '</div>';

      let body = '';

      body +=
        '<div class="card" style="margin-bottom:12px">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">' +
            '<b style="font-size:14px">' + PU.escapeHtml(o.serviceTitle) + '</b>' + UI.statusBadge(o.status) +
          '</div>' +
          '<div style="font-size:11.5px;color:var(--ink-soft);line-height:2">' +
            'شماره سفارش: <b>' + PU.toPersianDigits(o.orderNumber) + '</b><br>' +
            'ثبت: ' + PU.toJalaliDate(o.createdAt) +
            (o.paymentTrackingCode ? '<br>کد پیگیری پرداخت: <b>' + PU.toPersianDigits(o.paymentTrackingCode) + '</b>' : '') +
            (o.assignedOperatorName ? '<br>اپراتور: ' + PU.escapeHtml(o.assignedOperatorName) : '') +
          '</div>' +
          '<div style="display:flex;justify-content:space-between;margin-top:10px;padding-top:9px;border-top:1px dashed var(--line)">' +
            '<span style="font-size:12px;color:var(--ink-soft)">' + (paid ? 'پرداخت‌شده' : 'قابل پرداخت') + '</span>' +
            '<span style="font-weight:800;color:var(--sky-deep)">' + PU.formatToman(paid ? o.paidAmount : Math.max(0, o.totalPrice - o.discountAmount)) + '</span>' +
          '</div>' +
        '</div>';

      if (retryable) {
        body +=
          '<div class="warn-box" style="margin-bottom:12px">این سفارش پرداخت نشده است. برای ورود به صف کار، پرداخت را انجام دهید.</div>' +
          '<button class="btn emerald block" id="retry-pay" style="margin-bottom:12px">' + icon('wallet') + 'پرداخت مجدد</button>';
      }

      if (tab === 'timeline') {
        body += '<div class="timeline">' +
          o.logs.map((l) =>
            '<div class="tl-item"><div class="tl-action">' + PU.escapeHtml(l.action) + '</div>' +
            '<div class="tl-meta">' + PU.escapeHtml(l.performedBy) + ' • ' + PU.toJalaliDate(l.timestamp) + '</div>' +
            (l.note ? '<div class="tl-note">' + PU.escapeHtml(l.note) + '</div>' : '') + '</div>'
          ).join('') + '</div>';

        // rating for delivered orders by owner
        if (o.status === 'delivered' && isOwner) {
          body += '<div class="section-title">' + icon('star') + 'امتیاز شما به این سفارش</div>' +
            '<div class="rating-stars" id="rating-stars">' +
              [1, 2, 3, 4, 5].map((i) =>
                '<button class="star ' + (o.rating >= i ? 'on' : '') + '" data-rate="' + i + '">' + icon('starFill') + '</button>'
              ).join('') + '</div>' +
            (o.rating ? '<div style="text-align:center;font-size:11px;color:var(--ink-soft)">با تشکر از بازخورد شما!</div>' : '') +
            '<textarea class="textarea" id="review-comment" rows="2" placeholder="نظر شما (اختیاری)">' + PU.escapeHtml(o.reviewComment || '') + '</textarea>' +
            '<button class="btn ghost block sm" id="save-review" style="margin-top:8px">ثبت نظر</button>';
        }
      } else if (tab === 'files') {
        body += '<div class="section-title" style="margin-top:0">' + icon('files') + 'فایل‌های شما</div>';
        body += (o.customerFiles || []).map((f) =>
          '<a class="file-chip" href="' + PU.escapeHtml(f.url) + '" target="_blank" style="text-decoration:none;color:inherit">' +
          '<div class="fc-icon">' + icon('file') + '</div>' +
          '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
          '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
          icon('download') + '</div>'
        ).join('') || '<div class="empty-state" style="padding:18px">' + icon('file') + '<div class="e2">فایلی پیوست نشده</div></div>';

        body += '<div class="section-title">' + icon('downloadcloud') + 'فایل‌های خروجی</div>';
        body += (o.resultFiles || []).map((f) =>
          '<a class="file-chip" href="' + PU.escapeHtml(f.url) + '" target="_blank" style="text-decoration:none;color:inherit">' +
          '<div class="fc-icon" style="color:var(--emerald)">' + icon('download') + '</div>' +
          '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
          '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
          icon('download') + '</div>'
        ).join('') || '<div class="empty-state" style="padding:18px">' + icon('clock') + '<div class="e2">هنوز فایل خروجی بارگذاری نشده</div></div>';
      } else if (tab === 'chat') {
        body += '<div id="chat-box" style="padding:2px">' +
          (o.ticketMessages || []).map((m) => {
            const mine = staff ? m.isOperatorResponse : !m.isOperatorResponse;
            return '<div class="chat-bubble ' + (mine ? 'me' : 'them') + '">' +
              '<div class="who">' + PU.escapeHtml(m.senderName) + '</div>' +
              PU.escapeHtml(m.message) +
              '<div class="when">' + PU.toJalaliDate(m.timestamp) + '</div></div>';
          }).join('') +
          (!(o.ticketMessages || []).length ? '<div class="empty-state" style="padding:18px">' + icon('sms') + '<div class="e2">پیامی وجود ندارد</div></div>' : '') +
          '</div>' +
          '<div style="display:flex;gap:8px;margin-top:10px">' +
            '<input class="input" id="chat-input" placeholder="پیام خود را بنویسید…">' +
            '<button class="btn" id="chat-send">' + icon('send') + '</button>' +
          '</div>';
      }

      sheet.body.html(tabs + body);

      sheet.body.find('[data-tab]').on('click', function () {
        tab = $(this).data('tab');
        render();
      });

      if (retryable) sheet.body.find('#retry-pay').on('click', async function () {
        const $btn = $(this).prop('disabled', true);
        try {
          await api.payOrderWithWallet(o.id);
          UI.toast('پرداخت از کیف پول انجام شد.', 'success');
          refresh(true);
        } catch (e) {
          try {
            const pay = await api.startPayment('order', { orderId: o.id });
            Screens.openPaymentRedirect(pay);
          } catch (e2) {
            UI.toast(e2.message, 'error');
          }
        } finally { $btn.prop('disabled', false); }
      });

      sheet.body.find('[data-rate]').on('click', async function () {
        const rating = Number($(this).data('rate'));
        try {
          current = await api.updateOrder(o.id, { rating });
          UI.toast('امتیاز شما ثبت شد.', 'success');
          render();
        } catch (e) { UI.toast(e.message, 'error'); }
      });

      sheet.body.find('#save-review').on('click', async function () {
        try {
          current = await api.updateOrder(o.id, { rating: o.rating || 5, reviewComment: sheet.body.find('#review-comment').val() });
          UI.toast('نظر شما ثبت شد.', 'success');
        } catch (e) { UI.toast(e.message, 'error'); }
      });

      sheet.body.find('#chat-send').on('click', sendChat);
      sheet.body.find('#chat-input').on('keydown', (e) => { if (e.key === 'Enter') sendChat(); });

      function sendChat() {
        const $input = sheet.body.find('#chat-input');
        const msg = ($input.val() || '').trim();
        if (!msg) return;
        $input.val('');
        api.addTicketMessage(o.id, msg)
          .then(() => refresh(true))
          .catch((e) => UI.toast(e.message, 'error'));
      }
    }

    refresh().then(() => {
      pollTimer = setInterval(() => refresh(true), 5000);
    });
  };
})();
