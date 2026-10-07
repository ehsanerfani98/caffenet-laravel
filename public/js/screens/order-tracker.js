/* Caffenet Lite — screens/order-tracker.js
 * Order tracking sheet with timeline / files tabs, rating and payment retry,
 * plus a dedicated (separate) chat page for messaging about an order.
 * Port of customer/OrderTrackerModal.tsx (5s polling). */
(function () {
  'use strict';

  let pollTimer = null;

  window.Screens = window.Screens || {};

  /* ---------------- dedicated chat page ---------------- */

  /* Standalone chat view, opened from the order tracker (customer) and from the
   * operator order detail. Kept separate so the conversation lives on its own
   * page instead of sharing the details view. Polls for new messages, but never
   * rebuilds the DOM while the user is typing (that is what made the mobile
   * keyboard close and the page jump). */
  Screens.openOrderChat = function (orderId, opts) {
    opts = opts || {};
    const isStaff = ['admin', 'operator'].includes(api.state.currentUser && api.state.currentUser.role);
    let chatTimer = null;

    const sheet = UI.sheet({
      title: opts.title || 'گفتگو با پشتیبانی',
      subtitle: opts.subtitle || '',
      icon: 'sms',
      tone: 'sky',
      size: 'tall',
      onClose: () => clearInterval(chatTimer),
    });

    /* True while focus sits in an input/textarea inside this sheet. */
    function isTyping() {
      const el = document.activeElement;
      const root = sheet.body[0];
      return !!el && !!root && root.contains(el) &&
        (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
    }

    function messageHtml(m) {
      const mine = isStaff ? m.isOperatorResponse : !m.isOperatorResponse;
      return '<div class="chat-bubble ' + (mine ? 'me' : 'them') + '">' +
        '<div class="who">' + PU.escapeHtml(m.senderName) + '</div>' +
        PU.escapeHtml(m.message) +
        '<div class="when">' + PU.toJalaliDate(m.timestamp) + '</div></div>';
    }

    function renderMessages(messages) {
      const $box = sheet.body.find('#chat-box');
      $box.html(
        (messages || []).map(messageHtml).join('') ||
        '<div class="empty-state" style="padding:18px">' + icon('sms') + '<div class="e2">پیامی وجود ندارد</div></div>'
      );
      // Keep the newest message in view.
      const body = sheet.body[0];
      if (body) body.scrollTop = body.scrollHeight;
    }

    sheet.body.html(
      '<div id="chat-box" style="padding:2px"></div>' +
      '<div style="display:flex;gap:8px;margin-top:10px">' +
        '<input class="input" id="chat-input" placeholder="پیام خود را بنویسید…">' +
        '<button class="btn" id="chat-send">' + icon('send') + '</button>' +
      '</div>'
    );
    renderMessages([]);

    async function load(silent) {
      try {
        const o = await api.fetchOrderById(orderId);
        // Never clobber the DOM (and steal focus / close the mobile keyboard)
        // while the user is composing a message.
        if (silent && isTyping()) return;
        renderMessages(o.ticketMessages);
      } catch (e) {
        if (!silent) UI.toast(e.message, 'error');
      }
    }

    function send() {
      const $input = sheet.body.find('#chat-input');
      const msg = ($input.val() || '').trim();
      if (!msg) return;
      $input.val('');
      api.addTicketMessage(orderId, msg)
        .then(() => load())
        .catch((e) => UI.toast(e.message, 'error'));
    }

    sheet.body.find('#chat-send').on('click', send);
    sheet.body.find('#chat-input').on('keydown', (e) => { if (e.key === 'Enter') send(); });

    load().then(() => { chatTimer = setInterval(() => load(true), 5000); });
  };

  /* ---------------- order tracker ---------------- */

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

    /* True while focus sits in an input/textarea inside this sheet. */
    function isTyping() {
      const el = document.activeElement;
      const root = sheet.body[0];
      return !!el && !!root && root.contains(el) &&
        (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
    }

    async function refresh(silent) {
      try {
        current = await api.fetchOrderById(orderId);
        sheet.el.find('.sh-sub').text(PU.toPersianDigits(current.orderNumber));
        // Skip the re-render while the user is typing so the review/comment
        // textarea is never wiped and the mobile keyboard is not dismissed.
        if (silent && isTyping()) return;
        render();
      } catch (e) {
        if (!silent) UI.toast(e.message, 'error');
      }
    }

    function render() {
      const o = current;
      if (!o) return;

      const isOwner = o.customerId === api.state.currentUser?.id;
      const paid = o.paidAmount > 0;
      const retryable = ['pending_payment', 'failed'].includes(o.status) && isOwner;

      const tabs =
        '<div class="segmented" style="margin-bottom:14px">' +
          '<button class="seg ' + (tab === 'timeline' ? 'on' : '') + '" data-tab="timeline">روند سفارش</button>' +
          '<button class="seg ' + (tab === 'files' ? 'on' : '') + '" data-tab="files">فایل‌ها</button>' +
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

      // Chat now lives on its own page; this is just the entry point.
      body += '<button class="btn ghost block sm" id="open-chat" style="margin-bottom:12px">' +
        icon('sms') + 'گفتگو با پشتیبانی</button>';

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
          icon('download') + '</a>'
        ).join('') || '<div class="empty-state" style="padding:18px">' + icon('file') + '<div class="e2">فایلی پیوست نشده</div></div>';

        body += '<div class="section-title">' + icon('downloadcloud') + 'فایل‌های خروجی</div>';
        body += (o.resultFiles || []).map((f) =>
          '<a class="file-chip" href="' + PU.escapeHtml(f.url) + '" target="_blank" style="text-decoration:none;color:inherit">' +
          '<div class="fc-icon" style="color:var(--emerald)">' + icon('download') + '</div>' +
          '<div class="fc-main"><div class="fc-name">' + PU.escapeHtml(f.name) + '</div>' +
          '<div class="fc-size">' + PU.formatBytes(f.size) + '</div></div>' +
          icon('download') + '</a>'
        ).join('') || '<div class="empty-state" style="padding:18px">' + icon('clock') + '<div class="e2">هنوز فایل خروجی بارگذاری نشده</div></div>';
      }

      // Preserve scroll position across the in-place repaint so polling does not
      // make the sheet visibly jump.
      const scrollTop = sheet.body.scrollTop();
      sheet.body.html(tabs + body);
      sheet.body.scrollTop(scrollTop);

      sheet.body.find('[data-tab]').on('click', function () {
        tab = $(this).data('tab');
        render();
      });

      sheet.body.find('#open-chat').on('click', function () {
        Screens.openOrderChat(o.id, {
          title: 'گفتگو با پشتیبانی',
          subtitle: PU.toPersianDigits(o.orderNumber),
        });
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
    }

    refresh().then(() => {
      pollTimer = setInterval(() => refresh(true), 5000);
    });
  };
})();
