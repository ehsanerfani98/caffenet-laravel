/* Caffenet Lite — permissions.js
 * Client-side mirror of App\Services\PermissionService (server = source of
 * truth; this copy drives navigation/UI gating exactly like the original
 * shared utils/permissions.ts module). */
(function () {
  'use strict';

  const ACTIONS = ['view', 'create', 'edit', 'delete', 'permissions', 'manage'];

  const ACTION_LABELS = {
    view: 'مشاهده', create: 'ایجاد', edit: 'ویرایش',
    delete: 'حذف', permissions: 'مجوزها', manage: 'مدیریت کامل',
  };

  const ROLE_LABELS = { admin: 'مدیر سیستم', operator: 'اپراتور', customer: 'مشتری' };

  const RESOURCES = {
    dashboard: { title: 'داشبورد و گزارش‌ها', actions: ['view'] },
    orders: { title: 'سفارش‌ها و صف کار', actions: ['view', 'create', 'edit', 'delete', 'manage'] },
    services: { title: 'خدمات و فرم‌ساز', actions: ['view', 'create', 'edit', 'delete', 'manage'] },
    slider: { title: 'اسلایدر صفحه اصلی', actions: ['view', 'create', 'edit', 'delete', 'manage'] },
    coupons: { title: 'کدهای تخفیف', actions: ['view', 'create', 'edit', 'delete', 'manage'] },
    users: { title: 'کاربران و مجوزها', actions: ['view', 'create', 'edit', 'permissions', 'delete', 'manage'] },
    settings: { title: 'تنظیمات سیستم', actions: ['view', 'edit'] },
    sms: { title: 'لاگ پیامک‌ها', actions: ['view'] },
    wallet: { title: 'کیف پول و تراکنش‌ها', actions: ['view', 'users', 'refund', 'adjust', 'manage'] },
  };

  function allKeys() {
    const keys = [];
    Object.keys(RESOURCES).forEach((r) => RESOURCES[r].actions.forEach((a) => keys.push(r + '.' + a)));
    return keys;
  }

  const ALL = allKeys();

  function roleDefaults(role) {
    if (role === 'admin') return ALL.slice();
    if (role === 'operator') return ['dashboard.view', 'orders.manage', 'sms.view', 'wallet.view', 'wallet.users'];
    return [];
  }

  /** Same hierarchy rules as the server. */
  function satisfies(granted, required) {
    if (!granted || !granted.length) return false;
    const set = new Set(granted);
    if (set.has(required)) return true;
    const dot = required.indexOf('.');
    const resource = required.slice(0, dot);
    const action = required.slice(dot + 1);
    if (set.has(resource + '.manage')) return true;
    if (action === 'view' && RESOURCES[resource]) {
      return RESOURCES[resource].actions.some((a) => a !== 'view' && set.has(resource + '.' + a));
    }
    return false;
  }

  window.PERM = {
    ACTION_LABELS,
    ROLE_LABELS,
    ALL,
    roleDefaults,
    satisfies,
    has(permissions, permission) { return satisfies(permissions, permission); },
    hasAny(permissions, required) { return required.some((p) => satisfies(permissions, p)); },
    normalize(raw) {
      if (raw == null) return null;
      let list = raw;
      if (typeof raw === 'string') {
        try { list = JSON.parse(raw); } catch (e) { return null; }
      }
      if (!Array.isArray(list)) return null;
      const valid = new Set(ALL);
      return list.map(String).filter((p) => valid.has(p));
    },
    resolve(role, rawOverride) {
      if (role === 'admin') return ALL.slice();
      const custom = PERM.normalize(rawOverride);
      if (custom === null) return roleDefaults(role);
      return Array.from(new Set(custom));
    },
  };
})();
