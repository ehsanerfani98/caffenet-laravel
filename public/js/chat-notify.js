/* Caffenet Lite — chat-notify.js
 * «شما پیام جدید دارید» — global unread-chat notification layer.
 *
 * Two surfaces, one data source (GET /api/chat/unread):
 *   1. A slide-down bar right under the app bar. Tapping it opens the chat
 *      page of the order with the newest unread message.
 *   2. A red «پیام جدید» pill on every order card (admin / operator /
 *      customer panels) through [data-chat-badge-slot] placeholders that
 *      are painted in place — no screen rebuild, no lost input focus.
 *
 * Polling: one lightweight request every 15s while logged in, plus an
 * instant refresh on login/logout/sync events. Read receipts are sent by
 * the chat page itself (openOrderChat → POST /orders/{id}/ticket-read),
 * which clears both surfaces through onOrderRead().
 */
(function () {
  'use strict';

  const ChatNotify = {
    started: false,
    timer: null,
    busy: false,
    /* { orderId: unreadCount } — kept in sync with the server. */
    unreadMap: {},
    /* Latest /chat/unread payload: { total, orders: [...] }. */
    summary: { total: 0, orders: [] },

    start() {
      if (this.started) return;
      this.started = true;
      // Seed instantly from the orders already in state (each ticket message
      // now carries staffReadAt / customerReadAt) so badges light up without
      // waiting for the first poll.
      this.seedFromOrders();
      api.subscribe((state, event) => {
        if (event === 'auth' || event === 'logout' || event === 'sync') this.refresh();
      });
      this.refresh();
      this.timer = setInterval(() => this.refresh(), 15000);
    },

    /* Derive unread counts from api.state.orders[].ticketMessages — the same
     * rule the server applies (other side's messages without a read stamp). */
    seedFromOrders() {
      const user = api.state.currentUser;
      if (!user) return;
      const isStaff = ['admin', 'operator'].includes(user.role);
      (api.state.orders || []).forEach((o) => {
        const n = (o.ticketMessages || []).filter((m) =>
          isStaff ? (!m.isOperatorResponse && !m.staffReadAt)
                  : (m.isOperatorResponse && !m.customerReadAt)
        ).length;
        if (n > 0) this.unreadMap[o.id] = n;
      });
      this.render();
    },

    unreadFor(orderId) {
      return this.unreadMap[orderId] || 0;
    },

    async refresh() {
      if (this.busy) return;
      const user = api.state.currentUser;
      if (!user) {
        this.unreadMap = {};
        this.summary = { total: 0, orders: [] };
        this.render();
        this.paintBadges();
        return;
      }
      this.busy = true;
      try {
        const r = await api.getChatUnread();
        this.summary = r || { total: 0, orders: [] };
        this.unreadMap = {};
        (this.summary.orders || []).forEach((o) => { this.unreadMap[o.orderId] = o.unread; });
        // Mirror onto state.orders so card re-renders show the badge even
        // before paintBadges() runs (and after full screen repaints).
        (api.state.orders || []).forEach((o) => {
          o.unreadTicketCount = this.unreadMap[o.id] || 0;
        });
        this.render();
        this.paintBadges();
      } catch (e) { /* silent — notification must never break the app */ }
      finally { this.busy = false; }
    },

    /* Called by the chat page after it sends a read receipt: zero that order
     * locally, repaint everything, then let the server confirm. */
    onOrderRead(orderId) {
      const had = this.unreadMap[orderId] || 0;
      delete this.unreadMap[orderId];
      if (this.summary && Array.isArray(this.summary.orders)) {
        this.summary.orders = this.summary.orders.filter((o) => o.orderId !== orderId);
        this.summary.total = Math.max(0, this.summary.total - had);
      }
      (api.state.orders || []).forEach((o) => {
        if (o.id === orderId) o.unreadTicketCount = 0;
      });
      this.render();
      this.paintBadges();
      this.refresh();
    },

    /* ---------- order-card badge ---------- */

    /* Template helper — order cards embed this slot; paintBadges() fills or
     * clears it in place whenever unread state changes. */
    badgeSlotHtml(orderId) {
      const n = this.unreadFor(orderId);
      return '<span class="chat-badge-slot" data-chat-badge-slot="' + PU.escapeHtml(orderId) + '">' +
        (n > 0 ? '<span class="chat-badge">پیام جدید</span>' : '') +
      '</span>';
    },

    paintBadges() {
      const slots = document.querySelectorAll('[data-chat-badge-slot]');
      for (const slot of slots) {
        const oid = slot.getAttribute('data-chat-badge-slot');
        const want = this.unreadFor(oid) > 0;
        const has = !!slot.querySelector('.chat-badge');
        if (want && !has) slot.innerHTML = '<span class="chat-badge">پیام جدید</span>';
        else if (!want && has) slot.innerHTML = '';
      }
    },

    /* ---------- top notification bar ---------- */

    render() {
      const $bar = $('#chat-notify-bar');
      if (!$bar.length) return;
      const user = api.state.currentUser;
      const total = user ? (this.summary.total || 0) : 0;

      if (!total) {
        if ($bar.hasClass('show')) $bar.removeClass('show');
        return;
      }

      const isStaff = ['admin', 'operator'].includes(user.role);
      const top = (this.summary.orders || [])[0] || null;
      const sub = top
        ? (isStaff
          ? (PU.toPersianDigits(top.orderNumber) + ' — ' + (top.customerName || '') + ': ' + (top.lastMessage || ''))
          : (PU.toPersianDigits(top.orderNumber) + ' — ' + (top.serviceTitle || '') + ': ' + (top.lastMessage || '')))
        : '';

      if (!$bar.hasClass('show') || $bar.data('cnb-top') !== (top ? top.orderId : '')) {
        $bar.html(
          '<button class="cnb-inner" id="cnb-go" type="button">' +
            icon('sms') +
            '<span class="cnb-text"><b>شما پیام جدید دارید</b>' +
              (sub ? '<span class="cnb-sub">' + PU.escapeHtml(sub) + '</span>' : '') +
            '</span>' +
            '<span class="cnb-count">' + PU.toPersianDigits(total) + '</span>' +
            '<span class="cnb-arrow">' + icon('arrowleft') + '</span>' +
          '</button>'
        );
        $bar.data('cnb-top', top ? top.orderId : '');
        $('#cnb-go').off('click').on('click', () => this.openTop());
      } else {
        $bar.find('.cnb-count').text(PU.toPersianDigits(total));
      }
      $bar.addClass('show');
    },

    /* Bar tap → open the chat page of the order with the newest unread
     * message (the read receipt the chat sends then clears the bar). */
    openTop() {
      const top = (this.summary.orders || [])[0];
      if (!top) return;
      const user = api.state.currentUser;
      const isStaff = user && ['admin', 'operator'].includes(user.role);
      Screens.openOrderChat(top.orderId, {
        title: isStaff ? 'گفتگو با مشتری' : 'گفتگو با پشتیبانی',
        subtitle: PU.toPersianDigits(top.orderNumber) +
          (isStaff && top.customerName ? ' — ' + top.customerName : ''),
      });
    },
  };

  window.ChatNotify = ChatNotify;
})();
