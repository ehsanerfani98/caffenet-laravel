/* Caffenet Lite — api.js
 * Client data layer — port of services/storageService.ts.
 * Observer pattern, no user data in localStorage. Data is refreshed on load and
 * after each mutating action only — there is no polling or background timer.
 * The session lives in Laravel's httpOnly session cookie (Fortify) and is
 * recovered from /api/auth/me after reload; every state-changing request
 * carries the X-CSRF-TOKEN header. */
(function () {
  'use strict';

  class ApiError extends Error {
    constructor(message, status) {
      super(message);
      this.name = 'ApiError';
      this.status = status;
    }
  }

  class Api {
    constructor() {
      this.state = {
        currentUser: null,
        categories: [],
        services: [],
        orders: [],
        coupons: [],
        settings: null,
        slides: [],
        smsInbox: [],
      };
      this._listeners = [];
      this._syncing = false;      // in-flight guard: one batch at a time
    }

    subscribe(listener) {
      this._listeners.push(listener);
    }

    notify(event) {
      this._listeners.forEach((fn) => {
        try { fn(this.state, event); } catch (e) { /* listener errors never break the app */ }
      });
    }

    startSync() {
      // Intentionally registers no timer and no focus/visibility listener: the
      // app refreshes only on initial load and after user actions, so switching
      // browser tabs or sitting idle produces zero requests. Kept so the boot
      // sequence in app.js stays unchanged.
    }

    stopSync() {
      // Nothing to tear down — there is no timer or visibility listener.
    }

    /* ---------------- low-level request ---------------- */

    async request(method, path, body, opts) {
      opts = opts || {};
      const init = {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      };
      // Laravel CSRF protection: every non-GET must echo the session token
      // exposed by the Blade shell's boot-data block.
      if (method !== 'GET' && window.__APP_BOOT && window.__APP_BOOT.csrf) {
        init.headers['X-CSRF-TOKEN'] = window.__APP_BOOT.csrf;
      }
      if (body !== undefined && !(body instanceof FormData)) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      } else if (body instanceof FormData) {
        init.body = body;
      }
      if (opts.skipOrigin !== true) {
        // browsers set Origin automatically for same-origin fetch; nothing to do
      }

      let res;
      try {
        res = await fetch(path, init);
      } catch (networkErr) {
        throw new ApiError('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.', 0);
      }

      let data = null;
      try { data = await res.json(); } catch (e) { data = null; }

      if (!res.ok) {
        const message = (data && data.error) || 'خطای پردازش درخواست.';
        const err = new ApiError(message, res.status);
        // Preserve the structured server payload (e.g. code=INSUFFICIENT_WALLET
        // carrying required/balance/shortfall) so screens can react precisely
        // instead of only showing a generic message.
        if (data && typeof data === 'object') {
          err.code = data.code;
          err.data = data;
        }
        throw err;
      }
      return data;
    }

    /* ---------------- session ---------------- */

    async requestOtp(phone) {
      const p = PU.normalizeIranianMobile(phone);
      const r = await this.request('POST', '/api/auth/send-otp', { phone: p });
      return { testCode: r.testCode || null, message: r.message || '' };
    }

    async verifyOtp(phone, code) {
      const p = PU.normalizeIranianMobile(phone);
      const user = await this.request('POST', '/api/auth/verify-otp', { phone: p, code });
      // Laravel rotates the CSRF token with the session at login — keep the
      // fresh one that ships with the login response.
      if (user.csrf && window.__APP_BOOT) window.__APP_BOOT.csrf = user.csrf;
      delete user.csrf;
      this.state.currentUser = user;
      this.notify('auth');
      await this.syncWithBackend();
      // Initialize Firebase Cloud Messaging for the logged-in user.
      if (window.FCM && typeof window.FCM.init === 'function') {
        window.FCM.init();
      }
      return user;
    }

    async logout() {
      // Deactivate FCM tokens before the session is invalidated.
      if (window.FCM && typeof window.FCM.unregister === 'function') {
        try { await window.FCM.unregister(); } catch (e) { /* ignore */ }
      }
      try {
        const r = await this.request('POST', '/api/auth/logout', {});
        // Laravel invalidates the session and rotates the CSRF token on logout
        // (AuthController::logout). The SPA never reloads, so the freshly minted
        // token that ships with the response must replace the stale one — else
        // the next login (send-otp/verify-otp) fails with a 419 CSRF mismatch
        // until the page is refreshed.
        if (r && r.csrf && window.__APP_BOOT) window.__APP_BOOT.csrf = r.csrf;
      } catch (e) { /* ignore */ }
      this.state.currentUser = null;
      this.state.orders = [];
      this.notify('logout');
    }

    async getCurrentUser() {
      try {
        this.state.currentUser = await this.request('GET', '/api/auth/me');
        // If the session was restored (page reload while logged in), boot
        // FCM so push notifications keep working.
        if (this.state.currentUser && window.FCM && typeof window.FCM.init === 'function') {
          window.FCM.init();
        }
      } catch (e) {
        this.state.currentUser = null;
      }
      return this.state.currentUser;
    }

    hasPermission(permission) {
      const u = this.state.currentUser;
      if (!u) return false;
      return PERM.has(u.permissions || [], permission);
    }

    /* ---------------- sync ---------------- */

    async syncWithBackend() {
      // Collapse overlapping batches: a focus event and a mutation can land
      // together — only one request set should run. There is no timer anymore.
      if (this._syncing) return;

      this._syncing = true;
      const jobs = [
        this.request('GET', '/api/categories').then((v) => { this.state.categories = v; }),
        this.request('GET', '/api/services').then((v) => { this.state.services = v; }),
        this.request('GET', '/api/settings').then((v) => { this.state.settings = v; }),
        this.request('GET', '/api/slider').then((v) => { this.state.slides = v; }),
      ];
      if (this.state.currentUser) {
        // Re-read the session user too. This is the single fix for the
        // "wallet balance never changes after a purchase/refund" bug: this
        // method previously never re-read /api/auth/me, so the header, the
        // wallet page and the order form kept showing the pre-payment balance.
        jobs.push(this.request('GET', '/api/auth/me').then((u) => { this.state.currentUser = u; }));
        jobs.push(this.request('GET', '/api/orders').then((v) => { this.state.orders = v; }));
      }
      try {
        await Promise.all(jobs);
        this.notify('sync');
      } catch (e) {
        this.notify('sync-error');
      } finally {
        this._syncing = false;
      }
    }

    /* ---------------- catalog admin ---------------- */

    async saveCategory(data) {
      const r = await this.request('POST', '/api/categories', data);
      await this.syncWithBackend();
      return r;
    }

    async deleteCategory(id) {
      const r = await this.request('DELETE', '/api/categories/' + id);
      await this.syncWithBackend();
      return r;
    }

    async saveService(data) {
      const r = await this.request('POST', '/api/services', data);
      await this.syncWithBackend();
      return r;
    }

    async deleteService(id) {
      const r = await this.request('DELETE', '/api/services/' + id);
      await this.syncWithBackend();
      return r;
    }

    /* ---------------- orders ---------------- */

    async fetchOrderById(id) {
      return this.request('GET', '/api/orders/' + id);
    }

    /**
     * Lightweight polling endpoint — returns only the queue-relevant fields
     * + recent notifications. Use this for the live tracker instead of the
     * full fetchOrderById, since it's much cheaper on the server.
     */
    async fetchOrderUpdates(id) {
      return this.request('GET', '/api/orders/' + id + '/updates');
    }

    /** Register "notify me when work starts on this order". */
    async notifyMeOnStart(id) {
      return this.request('POST', '/api/orders/' + id + '/notify-me', {});
    }

    /** Cancel an order — calls DELETE /api/orders/{id}/cancel. */
    async cancelOrder(id) {
      const r = await this.request('DELETE', '/api/orders/' + id + '/cancel', {});
      await this.syncWithBackend();
      return r;
    }

    async createOrder(payload) {
      const order = await this.request('POST', '/api/orders', payload);
      this.state.orders.unshift(order);
      this.notify('orders');
      return order;
    }

    async payOrderWithWallet(id) {
      const order = await this.request('POST', '/api/orders/' + id + '/pay-wallet', {});
      await this.syncWithBackend();
      return order;
    }

    /* BazaarPay Direct Pay (دایرکت‌پی): pays the order from the user's Cafe
     * Bazaar wallet/bank through a signed contract. Response shapes:
     *  {status:'paid'} — paid right away
     *  {status:'pending'} — executed, final state settles via polling
     *  {status:'contract_pending', contractRedirectUrl} — user must sign the
     *    contract first; redirect them there, the callback finishes payment.
     *  {status:'failed', error} */
    async payOrderDirect(id) {
      const result = await this.request('POST', '/api/orders/' + id + '/pay-bazaarpay', {});
      await this.syncWithBackend();
      return result;
    }

    async updateOrderStatus(id, status, note) {
      const order = await this.request('PATCH', '/api/orders/' + id + '/status', { status, note });
      this._replaceOrder(order);
      return order;
    }

    async updateOrder(id, fields) {
      const order = await this.request('PATCH', '/api/orders/' + id, fields);
      this._replaceOrder(order);
      return order;
    }

    async addTicketMessage(id, message) {
      const ticket = await this.request('POST', '/api/orders/' + id + '/ticket', { message });
      try {
        const order = await this.fetchOrderById(id);
        this._replaceOrder(order);
      } catch (e) { /* local-only update */ }
      return ticket;
    }

    async deleteOrder(id) {
      const r = await this.request('DELETE', '/api/orders/' + id);
      this.state.orders = this.state.orders.filter((o) => o.id !== id);
      this.notify('orders');
      return r;
    }

    async uploadOrderResult(id, file, replaceUrl) {
      const fd = new FormData();
      fd.append('file', file);
      // Replace mode: the server drops the old file (metadata + disk) together
      // with the new upload, so the operator never sees a stale duplicate.
      if (replaceUrl) fd.append('replaceUrl', replaceUrl);
      return this.request('POST', '/api/orders/' + id + '/result', fd);
    }

    async deleteOrderResult(id, url) {
      const order = await this.request('DELETE', '/api/orders/' + id + '/result', { url });
      this._replaceOrder(order);
      return order;
    }

    async deleteOrderCustomerFile(id, url) {
      const order = await this.request('DELETE', '/api/orders/' + id + '/customer-file', { url });
      this._replaceOrder(order);
      return order;
    }

    async fetchDashboardStats() {
      return this.request('GET', '/api/dashboard/stats');
    }

    _replaceOrder(order) {
      const idx = this.state.orders.findIndex((o) => o.id === order.id);
      if (idx >= 0) this.state.orders[idx] = order;
      else this.state.orders.unshift(order);
      this.notify('orders');
    }

    /* ---------------- uploads ---------------- */

    async uploadRealFile(file) {
      const fd = new FormData();
      fd.append('file', file);
      const r = await this.request('POST', '/api/upload', fd);
      return r.file;
    }

    async deleteUploadedFile(url) {
      return this.request('DELETE', '/api/upload', { url });
    }

    /* ---------------- users / permissions ---------------- */

    async getUsers() { return this.request('GET', '/api/users'); }
    async addUser(data) { return this.request('POST', '/api/users', data); }
    async updateUser(id, fields) { return this.request('PATCH', '/api/users/' + id, fields); }
    async deleteUser(id) { return this.request('DELETE', '/api/users/' + id); }
    async getPermissionCatalog() { return this.request('GET', '/api/permissions'); }

    /* ---------------- coupons ---------------- */

    async getCoupons() { return this.request('GET', '/api/coupons'); }
    async validateCoupon(code, totalPrice) {
      return this.request('POST', '/api/coupons/validate', { code, totalPrice });
    }
    async saveCoupon(data) { return this.request('POST', '/api/coupons', data); }
    async updateCoupon(id, data) { return this.request('PATCH', '/api/coupons/' + id, data); }
    async deleteCoupon(id) { return this.request('DELETE', '/api/coupons/' + id); }

    /* ---------------- settings / sms ---------------- */

    async saveSettings(fields) { return this.request('PUT', '/api/settings', fields); }
    async fetchSystemSettings() { return this.request('GET', '/api/settings/system'); }
    async updateSystemSettings(settings) {
      return this.request('PUT', '/api/settings/system', { settings });
    }
    async getSmsInbox() { return this.request('GET', '/api/sms'); }
    async deleteSmsLog(id) { return this.request('DELETE', '/api/sms/' + id); }
    async clearSmsLogs() { return this.request('DELETE', '/api/sms'); }

    /* ---------------- slider ---------------- */

    async uploadSliderImage(file) {
      const fd = new FormData();
      fd.append('image', file);
      const r = await this.request('POST', '/api/slider/upload', fd);
      return r.imageUrl;
    }
    async saveSlide(data) { return this.request('POST', '/api/slider', data); }
    async reorderSlides(ids) { return this.request('PUT', '/api/slider/reorder', { ids }); }
    async deleteSlide(id) { return this.request('DELETE', '/api/slider/' + id); }

    /* ---------------- payments ---------------- */

    async startPayment(purpose, payload) {
      const body = Object.assign({ purpose }, payload || {});
      return this.request('POST', '/api/payments/request', body);
    }

    async getPaymentStatus(authority) {
      return this.request('GET', '/api/payments/status?authority=' + encodeURIComponent(authority));
    }

    /* ---------------- wallet ledger / refunds ---------------- */

    async getWalletTransactions(params) {
      // Accepts a plain userId string (legacy) or a filter object:
      // {userId, type, direction, from, to, search, limit}.
      const opts = typeof params === 'string' ? { userId: params } : (params || {});
      const qs = Object.keys(opts)
        .filter((k) => opts[k] !== undefined && opts[k] !== null && opts[k] !== '')
        .map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(opts[k]))
        .join('&');
      return this.request('GET', '/api/wallet/transactions' + (qs ? '?' + qs : ''));
    }

    async getWalletUsers(search) {
      const qs = search ? '?search=' + encodeURIComponent(search) : '';
      return this.request('GET', '/api/wallet/users' + qs);
    }

    async refundWallet(payload) {
      return this.request('POST', '/api/wallet/refund', payload);
    }

    async adjustWallet(payload) {
      return this.request('POST', '/api/wallet/adjust', payload);
    }
  }

  window.ApiError = ApiError;
  window.api = new Api();
})();
