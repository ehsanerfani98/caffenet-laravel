/* Caffenet Lite — order-draft.js
 * Client-side order draft persistence (پیش‌نویس سفارش).
 *
 * When a customer fills the order form but cannot pay from the wallet yet
 * (insufficient balance → wallet top-up → gateway round-trip), the SPA
 * used to lose the entire form: the gateway return is a full page load, so
 * the customer had to find the service again and re-type every field.
 * This module keeps per-service form snapshots in localStorage so the order
 * form restores them and the customer resumes exactly where they left off.
 *
 * Storage shape (one key, many drafts — a customer may juggle more than
 * one service):  caffenet.orderDrafts.v1 →
 *   { v: 1, drafts: { [serviceId]: { savedAt, userKey, state } } }
 *
 * Guarantees:
 *  - Per-user: a draft is only ever restored for the account that created
 *    it (userKey), so sharing a device can never leak form data.
 *  - TTL: drafts expire after 24h (uploaded files retention horizon is far
 *    longer) and are pruned lazily on every access.
 *  - Best-effort: private-browsing/quota errors degrade to "no draft",
 *    never to a broken form.
 *
 * Lifecycle:
 *  - save()   right before the wallet-shortage prompt leads the customer
 *             away to top up (order-form.js).
 *  - load()   when the same service's form reopens — manually, or
 *             automatically after a successful payment return
 *             (App.resumeOrderDraft → Screens.openOrderForm).
 *  - clear()  once the order is actually created, or all drafts via
 *             clear() without arguments.
 */
(function () {
  'use strict';

  const KEY = 'caffenet.orderDrafts.v1';
  const VERSION = 1;
  const TTL_MS = 24 * 60 * 60 * 1000; // one day

  /** localStorage when writable, null when unavailable (private mode etc.). */
  function safeStorage() {
    try {
      const probe = KEY + '.probe';
      window.localStorage.setItem(probe, '1');
      window.localStorage.removeItem(probe);
      return window.localStorage;
    } catch (e) {
      return null;
    }
  }

  /** Parse + validate the whole store; prunes expired/corrupt entries. */
  function readStore(store) {
    let data;
    try {
      data = JSON.parse(store.getItem(KEY) || '{}');
    } catch (e) {
      store.removeItem(KEY);
      return { v: VERSION, drafts: {} };
    }
    if (!data || data.v !== VERSION || typeof data.drafts !== 'object' || data.drafts === null) {
      store.removeItem(KEY);
      return { v: VERSION, drafts: {} };
    }
    const now = Date.now();
    Object.keys(data.drafts).forEach((id) => {
      const d = data.drafts[id];
      if (!d || !d.savedAt || (now - d.savedAt) > TTL_MS || !d.state || typeof d.state !== 'object') {
        delete data.drafts[id];
      }
    });
    return data;
  }

  function writeStore(store, data) {
    try {
      // Empty store → drop the key entirely instead of keeping tombstones.
      if (Object.keys(data.drafts).length === 0) store.removeItem(KEY);
      else store.setItem(KEY, JSON.stringify(data));
      return true;
    } catch (e) {
      return false; // quota exceeded etc. — draft is best-effort
    }
  }

  /** Valid draft for this user, or null (absent / other account / expired). */
  function pick(store, serviceId, userKey) {
    const data = readStore(store);
    const entry = serviceId != null ? data.drafts[String(serviceId)] : null;
    if (entry && String(entry.userKey) === String(userKey == null ? '' : userKey)) {
      return { serviceId: String(serviceId), state: entry.state, savedAt: entry.savedAt };
    }
    return null;
  }

  window.OrderDraft = {
    /** Snapshot one service's form state. Overwrites that service's draft. */
    save(serviceId, userKey, state) {
      const store = safeStorage();
      if (!store) return false;
      const data = readStore(store);
      data.drafts[String(serviceId)] = {
        savedAt: Date.now(),
        userKey: String(userKey == null ? '' : userKey),
        state: state,
      };
      return writeStore(store, data);
    },

    /**
     * Draft for one service (and this exact user). Returns
     * {serviceId, state, savedAt} or null. Never throws.
     */
    load(serviceId, userKey) {
      const store = safeStorage();
      if (!store) return null;
      try { return pick(store, serviceId, userKey); } catch (e) { return null; }
    },

    /**
     * Most recent draft belonging to this user, across every service —
     * used after a successful payment return to reopen "the" form that
     * led the customer to the wallet. Returns the same shape as load().
     */
    latestForUser(userKey) {
      const store = safeStorage();
      if (!store) return null;
      try {
        const data = readStore(store);
        const wanted = String(userKey == null ? '' : userKey);
        let bestId = null;
        let bestAt = -1;
        Object.keys(data.drafts).forEach((id) => {
          const d = data.drafts[id];
          if (String(d.userKey) === wanted && d.savedAt > bestAt) {
            bestId = id;
            bestAt = d.savedAt;
          }
        });
        return bestId === null ? null : { serviceId: bestId, state: data.drafts[bestId].state, savedAt: bestAt };
      } catch (e) {
        return null;
      }
    },

    /** Drop one service's draft — or every draft when serviceId is omitted. */
    clear(serviceId) {
      const store = safeStorage();
      if (!store) return;
      try {
        if (serviceId == null) {
          store.removeItem(KEY);
          return;
        }
        const data = readStore(store);
        delete data.drafts[String(serviceId)];
        writeStore(store, data);
      } catch (e) { /* best-effort */ }
    },
  };
})();
