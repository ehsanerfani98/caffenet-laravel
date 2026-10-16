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
      onClose: () => {
        clearInterval(pollTimer);
        // Stop the QueueUI tracker so its 15s polling loop doesn't leak.
        if (window.QueueUI && typeof window.QueueUI.stopOrderTracking === 'function') {
          window.QueueUI.stopOrderTracking();
        }
      },
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

    /**
     * Smart queue status card. Shown for paid orders (submitted / processing /
     * ready). Renders two zones:
     *
     *   ⏳ انتظار تقریبی: 35 دقیقه       (when waiting — submitted)
     *   👥 نفرهای جلوی شما: ۲
     *   ⏱ شروع تقریبی: ۱۴:۳۰
     *
     *   ⚙️ مدت انجام تقریبی: ۲۰ دقیقه
     *   ⏱ تکمیل تقریبی: ۱۴:۵۰           (when processing or ready)
     *
     * Plus a "خبرم کن هنگام شروع" button (POST /api/orders/{id}/notify-me) and
     * a "لینک پیگیری عمومی" share button (uses trackToken).
     */
    function queueStatusCardHtml(o, isOwner) {
      // Only show for paid orders that are still in the queue lifecycle.
      const ACTIVE = ['submitted', 'processing', 'ready'];
      if (ACTIVE.indexOf(o.status) === -1) return '';

      const isWaiting = o.status === 'submitted';
      const isProcessing = o.status === 'processing';
      const isReady = o.status === 'ready';

      // Hero stat — what the customer cares most about right now.
      let heroLabel, heroValue, heroIcon, heroColor;
      if (isWaiting) {
        const pos = o.queuePosition != null ? o.queuePosition : '—';
        const ahead = (typeof pos === 'number' && pos > 1) ? (pos - 1) : 0;
        heroLabel = 'نفرهای جلوی شما';
        heroValue = PU.toPersianDigits(ahead);
        heroIcon = 'users';
        heroColor = '#1559de';
      } else if (isProcessing) {
        heroLabel = 'در حال انجام';
        heroValue = '⚙️';
        heroIcon = 'gear';
        heroColor = '#f08d37';
      } else { // ready
        heroLabel = 'آماده تحویل';
        heroValue = '✓';
        heroIcon = 'check';
        heroColor = '#0ca67d';
      }

      // Build the two-row ETA grid. We always show wait + duration + start + end
      // (when present) so the customer sees the FULL picture per the spec.
      const rows = [];
      if (isWaiting) {
        if (o.estimatedWaitMinutes != null) {
          rows.push({ icon: 'clock', label: '⏳ انتظار تقریبی', value: PU.formatDuration(o.estimatedWaitMinutes) });
        }
        if (o.queuePosition != null && o.queuePosition > 0) {
          rows.push({ icon: 'users', label: '👥 نوبت شما در صف', value: PU.toPersianDigits(o.queuePosition) });
        }
        if (o.estimatedStartAt) {
          rows.push({ icon: 'play', label: '⏱ شروع تقریبی', value: PU.formatTime(o.estimatedStartAt) });
        }
      }
      if (o.estimatedDurationMinutes != null) {
        rows.push({ icon: 'cog', label: '⚙️ مدت انجام تقریبی', value: PU.formatDuration(o.estimatedDurationMinutes) });
      }
      if (o.estimatedEndAt) {
        rows.push({ icon: 'flag', label: '⏱ تکمیل تقریبی', value: PU.formatTime(o.estimatedEndAt) });
      }

      // The "no response deadline" warning when the order is at position 1
      // and waiting for the customer to confirm something.
      let warnHtml = '';
      if (o.noResponseDeadline && isWaiting) {
        const deadline = new Date(o.noResponseDeadline);
        const now = new Date();
        const minsLeft = Math.max(0, Math.round((deadline - now) / 60000));
        if (minsLeft > 0 && minsLeft <= 15) {
          warnHtml = '<div class="queue-warn">' + icon('alert') +
            ' پاسخ شما تا ' + PU.toPersianDigits(minsLeft) + ' دقیقه دیگر نیاز است؛ ' +
            'در غیر این صورت سفارش به انتهای صف منتقل می‌شود.</div>';
        }
      }

      // Action buttons: "notify me on start" + "share tracking link"
      const showNotify = isWaiting && isOwner;
      const showShare = !!o.trackToken && isOwner;
      let actionsHtml = '';
      if (showNotify || showShare) {
        actionsHtml = '<div class="queue-actions">';
        if (showNotify) {
          actionsHtml += '<button class="btn ghost sm" id="queue-notify">' + icon('bell') + 'خبرم کن هنگام شروع</button>';
        }
        if (showShare) {
          actionsHtml += '<button class="btn ghost sm" id="queue-share">' + icon('share') + 'لینک پیگیری</button>';
        }
        actionsHtml += '</div>';
      }

      // Render the rows grid (2 columns).
      const rowsHtml = rows.length
        ? '<div class="queue-eta-grid">' + rows.map((r) => (
            '<div class="queue-eta-cell">' +
              '<div class="queue-eta-label">' + r.label + '</div>' +
              '<div class="queue-eta-value">' + PU.escapeHtml(r.value) + '</div>' +
            '</div>'
          )).join('') + '</div>'
        : '';

      return (
        '<div class="card queue-card" style="margin-bottom:12px">' +
          '<div class="queue-hero" style="background:' + heroColor + '1a;color:' + heroColor + ';">' +
            '<div class="queue-hero-icon">' + icon(heroIcon) + '</div>' +
            '<div class="queue-hero-body">' +
              '<div class="queue-hero-value">' + heroValue + '</div>' +
              '<div class="queue-hero-label">' + PU.escapeHtml(heroLabel) + '</div>' +
            '</div>' +
            '<div class="queue-hero-type">' + PU.escapeHtml(PU.queueTypeLabel(o.queueType)) + '</div>' +
          '</div>' +
          rowsHtml +
          warnHtml +
          actionsHtml +
        '</div>'
      );
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
            (o.contactMethods && o.contactMethods.length ? '<br>راه ارتباطی: <b>' + PU.escapeHtml(PU.contactMethodsText(o.contactMethods)) + '</b>' : '') +
          '</div>' +
          '<div style="display:flex;justify-content:space-between;margin-top:10px;padding-top:9px;border-top:1px dashed var(--line)">' +
            '<span style="font-size:12px;color:var(--ink-soft)">' + (paid ? 'پرداخت‌شده' : 'قابل پرداخت') + '</span>' +
            '<span style="font-weight:800;color:var(--sky-deep)">' + PU.formatToman(paid ? o.paidAmount : Math.max(0, o.totalPrice - o.discountAmount)) + '</span>' +
          '</div>' +
        '</div>';

      // ── Smart queue status card ──────────────────────────────────
      // Shown when the order is paid and either waiting in the queue or
      // being worked on. Mirrors the spec:
      //   ⏳ انتظار تقریبی: 35 دقیقه
      //   ⚙️ مدت انجام تقریبی: 20 دقیقه
      //   ⏱ شروع تقریبی: 14:30
      //   ⏱ تکمیل تقریبی: 14:50
      //   👥 نفرهای جلوی شما: 2
      body += queueStatusCardHtml(o, isOwner);

      // Chat now lives on its own page; this is just the entry point.
      body += '<button class="btn ghost block sm" id="open-chat" style="margin-bottom:12px">' +
        icon('sms') + 'گفتگو با پشتیبانی</button>';

      if (retryable) {
        // BazaarPay Direct Pay adds a second way to settle an unpaid order.
        const directPayEnabled = !!(api.state.settings && api.state.settings.bazaarPay && api.state.settings.bazaarPay.directEnabled);
        body +=
          '<div class="warn-box" style="margin-bottom:12px">این سفارش پرداخت نشده است. برای ورود به صف کار، پرداخت را انجام دهید.</div>' +
          '<button class="btn emerald block" id="retry-pay" style="margin-bottom:12px">' + icon('wallet') + 'پرداخت از کیف پول</button>' +
          (directPayEnabled
            ? '<button class="btn block" id="retry-pay-direct" style="margin-bottom:12px">' + icon('zap') + 'پرداخت مستقیم با بازارپی</button>'
            : '');
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

      // ── Smart queue buttons ───────────────────────────────────────
      // "Notify me on start" — registers a per-order flag in cache so the
      // SendStartReminder job will push a browser notification when the
      // estimated start is ~5 min away.
      sheet.body.find('#queue-notify').on('click', async function () {
        const $btn = $(this).prop('disabled', true);
        try {
          await api.notifyMeOnStart(o.id);
          UI.toast('هنگام شروع کار روی سفارش، به شما اطلاع می‌دهیم.', 'success');
          // Request browser notification permission at the same time so
          // the SendStartReminder dispatcher can actually ping the user.
          if (window.QueueUI && typeof window.QueueUI.requestNotificationPermission === 'function') {
            window.QueueUI.requestNotificationPermission();
          }
        } catch (e) {
          UI.toast(e.message, 'error');
        } finally {
          $btn.prop('disabled', false);
        }
      });

      // "Share tracking link" — uses the public track_token so the customer
      // can open the order status on a second device without logging in.
      sheet.body.find('#queue-share').on('click', function () {
        if (!o.trackToken) {
          UI.toast('لینک پیگیری در دسترس نیست.', 'info');
          return;
        }
        const url = window.location.origin + '/api/orders/track/' + o.trackToken;
        // Try the Web Share API first (mobile), fall back to clipboard.
        if (navigator.share) {
          navigator.share({
            title: 'پیگیری سفارش ' + PU.toPersianDigits(o.orderNumber),
            text: 'وضعیت سفارش شما در کافی‌نت احسان',
            url: url,
          }).catch(() => {});
        } else if (navigator.clipboard) {
          navigator.clipboard.writeText(url).then(
            () => UI.toast('لینک پیگیری کپی شد.', 'success'),
            () => UI.toast(url, 'info')
          );
        } else {
          UI.toast(url, 'info');
        }
      });

      if (retryable) sheet.body.find('#retry-pay').on('click', async function () {
        const $btn = $(this).prop('disabled', true);
        try {
          // Services are paid from the wallet or via BazaarPay Direct Pay.
          await api.payOrderWithWallet(o.id);
          UI.toast('پرداخت از کیف پول انجام شد.', 'success');
          refresh(true);
        } catch (e) {
          if (e.code === 'INSUFFICIENT_WALLET') {
            Screens.openWalletShortage(e.data && e.data.required, e.data && e.data.balance);
          } else {
            UI.toast(e.message, 'error');
          }
        } finally { $btn.prop('disabled', false); }
      });

      // BazaarPay Direct Pay retry — pays through the user's signed contract
      // or (first use) redirects to the contract signing page.
      if (retryable) sheet.body.find('#retry-pay-direct').on('click', async function () {
        const $btn = $(this).prop('disabled', true);
        try {
          const r = await api.payOrderDirect(o.id);
          if (r.status === 'paid') {
            UI.toast('پرداخت مستقیم با بازارپی انجام شد.', 'success');
            refresh(true);
          } else if (r.status === 'contract_pending') {
            UI.toast('برای فعال‌سازی پرداخت مستقیم، قرارداد را در بازارپی تایید کنید.', 'info');
            Screens.openPaymentRedirect({ gateway: 'bazaarpay', redirectUrl: r.contractRedirectUrl });
          } else if (r.status === 'pending') {
            UI.toast(r.message || 'پرداخت در حال بررسی است.', 'info');
            refresh(true);
          } else {
            UI.toast(r.error || 'پرداخت مستقیم ناموفق بود.', 'error');
          }
        } catch (e) {
          UI.toast(e.message, 'error');
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
      // 5-second polling keeps the queue status card live: position changes,
      // status transitions (submitted → processing), ETA recalculations.
      pollTimer = setInterval(() => refresh(true), 5000);
      // Hook the QueueUI tracker so the browser notification fires when
      // the order transitions to "processing" or "ready".
      if (window.QueueUI && typeof window.QueueUI.startOrderTracking === 'function') {
        window.QueueUI.startOrderTracking(orderId, function (data) {
          // The poller already calls refresh(); we just react to status
          // changes for the browser notification, which QueueUI handles
          // internally via _maybeNotify().
        });
      }
    });
  };
})();
