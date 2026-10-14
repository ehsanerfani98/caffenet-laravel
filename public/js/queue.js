/* Caffenet Lite — queue.js
 * Smart queue / capacity polling manager.
 *
 * Responsibilities:
 *  - Polls /api/capacity/current every 30s (public; no auth needed) and
 *    drives the top-of-page capacity indicator (🟢🟡🟠🔴).
 *  - Polls /api/orders/{id}/updates every 15s when an order tracking page
 *    is open, with visibility-aware pausing (no requests while tab hidden).
 *  - Exponential backoff on failure (1s → 2s → 4s → 8s, capped at 30s).
 *  - Stops entirely when navigator.onLine is false.
 *
 * Exposes a single global: window.QueueUI
 */
(function () {
  'use strict';

  const CAPACITY_META = {
    green:  { dot: '🟢', label: 'ظرفیت عادی',            color: '#16a34a' },
    yellow: { dot: '🟡', label: 'صف در حال افزایش',     color: '#ca8a04' },
    orange: { dot: '🟠', label: 'صف شلوغ',               color: '#ea580c' },
    red:    { dot: '🔴', label: 'پذیرش امروز تکمیل شده', color: '#dc2626' },
  };

  class Poller {
    constructor(url, intervalMs, handler) {
      this.url = url;
      this.intervalMs = intervalMs;
      this.handler = handler;
      this.timer = null;
      this.backoff = 0;
      this.failures = 0;
      this.running = false;
    }

    start() {
      if (this.running) return;
      this.running = true;
      this._tick();
      document.addEventListener('visibilitychange', this._onVisibility);
      window.addEventListener('online', this._onOnline);
      window.addEventListener('offline', this._onOffline);
    }

    stop() {
      this.running = false;
      if (this.timer) { clearTimeout(this.timer); this.timer = null; }
      document.removeEventListener('visibilitychange', this._onVisibility);
      window.removeEventListener('online', this._onOnline);
      window.removeEventListener('offline', this._onOffline);
    }

    _onVisibility = () => {
      if (document.visibilityState === 'visible') {
        // Tab came back into view — poll immediately, then resume normal cadence.
        if (this.running) this._tick();
      }
    };

    _onOnline = () => { if (this.running) this._tick(); };
    _onOffline = () => { if (this.timer) { clearTimeout(this.timer); this.timer = null; } };

    async _tick() {
      if (!this.running) return;
      if (!navigator.onLine) return;
      if (document.visibilityState === 'hidden') {
        // Skip while hidden; try again soon.
        this.timer = setTimeout(() => this._tick(), 5000);
        return;
      }

      try {
        const res = await fetch(this.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        this.handler(data);
        this.failures = 0;
        this.backoff = this.intervalMs;
      } catch (e) {
        this.failures++;
        // Exponential backoff: 1s → 2s → 4s → 8s → cap 30s
        this.backoff = Math.min(30000, Math.max(1000, this.intervalMs * Math.pow(2, Math.min(this.failures, 5))));
      }

      this.timer = setTimeout(() => this._tick(), this.backoff);
    }
  }

  const QueueUI = {
    capacityPoller: null,
    orderPoller: null,

    /**
     * Boot the global capacity indicator. Call once on app load.
     * @param {HTMLElement} container  Where to render the pill.
     */
    startCapacityIndicator(container) {
      if (!container) return;
      this._capacityEl = container;
      if (this.capacityPoller) this.capacityPoller.stop();
      this.capacityPoller = new Poller('/api/capacity/current', 30000, (data) => this._renderCapacity(data));
      this.capacityPoller.start();
    },

    _renderCapacity(data) {
      if (!this._capacityEl) return;
      const meta = CAPACITY_META[data.tier] || CAPACITY_META.green;
      const eta = data.eta_minutes > 0 ? ` — شروع تقریبی ${this._formatDuration(data.eta_minutes)}` : '';
      const slot = data.first_slot_at ? ` — اولین نوبت آزاد: ${this._formatTime(data.first_slot_at)}` : '';

      this._capacityEl.innerHTML = `
        <span style="color:${meta.color}; font-weight:600;">${meta.dot} ${meta.label}</span>
        <span style="color:#475569; font-size:0.85em;">${eta}${slot}</span>
      `;
      this._capacityEl.dataset.tier = data.tier;
    },

    _formatDuration(mins) {
      if (mins <= 0) return 'اکنون';
      if (mins < 60) return `${mins} دقیقه`;
      const h = Math.floor(mins / 60);
      const m = mins % 60;
      return m === 0 ? `${h} ساعت` : `${h} ساعت و ${m} دقیقه`;
    },

    _formatTime(iso) {
      try {
        const d = new Date(iso);
        return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
      } catch (e) { return iso; }
    },

    /**
     * Start polling a specific order's updates.
     * @param {string} orderId
     * @param {function} onUpdate  Callback receiving {order, notifications, server_time}.
     */
    startOrderTracking(orderId, onUpdate) {
      if (this.orderPoller) this.orderPoller.stop();
      const url = `/api/orders/${orderId}/updates`;
      this.orderPoller = new Poller(url, 15000, (data) => {
        if (typeof onUpdate === 'function') onUpdate(data);
        this._maybeNotify(data);
      });
      this.orderPoller.start();
    },

    stopOrderTracking() {
      if (this.orderPoller) { this.orderPoller.stop(); this.orderPoller = null; }
    },

    /**
     * Fire a browser Notification API ping when a new "order_started" event arrives.
     * Tracks last-seen notification id to avoid duplicates.
     */
    _maybeNotify(data) {
      if (!data || !Array.isArray(data.notifications)) return;
      if (!('Notification' in window)) return;
      const latest = data.notifications[0];
      if (!latest) return;
      const seenKey = `queue:notified:${data.order?.id || ''}`;
      const lastSeen = sessionStorage.getItem(seenKey);
      if (lastSeen === latest.id) return;
      sessionStorage.setItem(seenKey, latest.id);

      if (latest.type === 'order_started') {
        this._browserNotify('کار روی سفارش شما شروع شد', latest.payload?.body || '');
      } else if (latest.type === 'order_ready') {
        this._browserNotify('سفارش شما آماده است', latest.payload?.body || '');
      } else if (latest.type === 'requeued') {
        this._browserNotify('نوبت شما منتقل شد', latest.payload?.body || '');
      }
    },

    _browserNotify(title, body) {
      try {
        if (Notification.permission === 'granted') {
          new Notification(title, { body, icon: '/icon.png', tag: 'caffenet-queue' });
        } else if (Notification.permission !== 'denied') {
          Notification.requestPermission().then(p => {
            if (p === 'granted') new Notification(title, { body, icon: '/icon.png', tag: 'caffenet-queue' });
          });
        }
      } catch (e) { /* notifications not available */ }
    },

    /**
     * Request notification permission up-front (call on user gesture).
     */
    requestNotificationPermission() {
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    },
  };

  window.QueueUI = QueueUI;
})();
