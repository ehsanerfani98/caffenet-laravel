#!/usr/bin/env node
/**
 * REAL device test for Caffenet push notifications (FCM web).
 *
 * Drives the actual SPA in headless Chromium with the `notifications`
 * permission granted (Playwright grantPermissions), logs in through the
 * REAL UI (phone → OTP, using the SMS test-mode autofill), lets the app
 * register a REAL FCM device token with the REAL caffenet-53608 project,
 * then — in `listen` mode — waits for a REAL push sent by
 * scripts-test/17_fcm_real.php and verifies physical delivery through
 * the service-worker trace (Cache 'fcm-debug-v1') + shown notifications.
 *
 * Modes:
 *   node 18_fcm_browser.mjs setup   → login + token registration
 *   node 18_fcm_browser.mjs listen  → wait for the push, report delivery
 *
 * Run (bash):
 *   php artisan serve --host=127.0.0.1 --port=8000 &
 *   NODE_PATH=$(npm root -g) node scripts-test/18_fcm_browser.mjs setup
 *   (in parallel: run listen in background, then tinker 17_fcm_real.php)
 */

import { chromium } from 'playwright';

const BASE = process.env.APP_URL || 'http://127.0.0.1:8000';
const ADMIN_PHONE = '09120000000';
const STATE_FILE = '/home/z/my-project/fcm-browser-state.json';
const mode = process.argv[2] || 'setup';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(page) {
  await page.goto(BASE, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-appbar-action="login"]', { timeout: 20000 });
  await page.click('[data-appbar-action="login"]');
  await page.waitForSelector('#auth-phone', { timeout: 10000 });

  await page.fill('#auth-phone', ADMIN_PHONE);
  // Terms checkbox must be accepted before the send button works.
  const terms = page.locator('#auth-terms');
  if (await terms.count()) {
    await terms.check({ force: true }).catch(() => {});
  }
  await page.click('#auth-send');

  // SMS test mode shows the code with an autofill button.
  await page.waitForSelector('#auth-autofill', { timeout: 20000 });
  await page.click('#auth-autofill');
  await page.click('#auth-verify');

  // Logged in when the app state has a currentUser (from /api/auth/me).
  await sleep(2500);
  const authed = await page.evaluate(() => !!(window.api && api.state && api.state.currentUser));
  if (!authed) throw new Error('login failed — api.state.currentUser missing');
  console.log('[login] OK as', ADMIN_PHONE);
}

async function ensureTokenRegistered(page) {
  // fcm.js auto-registers at boot when permission is already granted.
  await sleep(6000);
  const st = await page.evaluate(async () => {
    const r = await fetch('/api/fcm/token', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return r.json();
  });
  console.log('[token] server status:', JSON.stringify(st));
  if (!st.registered) {
    // Belt & suspenders: drive the manual enable flow explicitly.
    const res = await page.evaluate(async () => await window.FCM.enable());
    console.log('[token] FCM.enable() →', JSON.stringify(res));
    await sleep(4000);
    const st2 = await page.evaluate(async () => {
      const r = await fetch('/api/fcm/token', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      return r.json();
    });
    console.log('[token] server status after enable:', JSON.stringify(st2));
    if (!st2.registered) throw new Error('FCM token still not registered — see console/errors');
  }
  console.log('[token] ✔ device token registered with the real project');
}

async function pushTrace(page) {
  return page.evaluate(async () => {
    let trace = null;
    try {
      const c = await caches.open('fcm-debug-v1');
      const m = await c.match('/__fcm-push-trace');
      if (m) trace = await m.json();
    } catch (e) { /* cache may not exist yet */ }
    return {
      trace,
      permission: ('Notification' in window) ? Notification.permission : 'unsupported',
    };
  });
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  permissions: ['notifications'],
  origin: BASE,
  ignoreHTTPSErrors: true,
});

if (mode === 'setup') {
  const page = await context.newPage();
  page.on('console', (m) => {
    if (/FCM|push|error/i.test(m.text())) console.log('  [console]', m.text().slice(0, 160));
  });
  await login(page);
  await ensureTokenRegistered(page);
  await context.storageState({ path: STATE_FILE });
  console.log('[setup] ✔ state saved →', STATE_FILE);
} else if (mode === 'listen') {
  await context.storageState({ path: STATE_FILE });
  const page = await context.newPage();
  page.on('console', (m) => {
    if (/FCM|toast|push/i.test(m.text())) console.log('  [console]', m.text().slice(0, 160));
  });
  await page.goto(BASE, { waitUntil: 'domcontentloaded' });
  await sleep(5000);

  // Leave the app UI (plain same-origin page) so the message behaves like
  // a BACKGROUND push — the service worker must show the notification.
  await page.goto(BASE + '/api/fcm/config', { waitUntil: 'domcontentloaded' });
  console.log('[listen] waiting up to 60s for the real push …');

  let delivered = false;
  let shown = 0;
  for (let i = 0; i < 30; i++) {
    await sleep(2000);
    const t = await pushTrace(page);
    if (t.trace && t.trace.seen >= 1) {
      // Count notifications currently shown by the FCM service worker.
      shown = await page.evaluate(async () => {
        const regs = await navigator.serviceWorker.getRegistrations();
        let n = 0;
        for (const r of regs) {
          const list = await r.getNotifications();
          n += list.length;
        }
        return n;
      });
      console.log('[listen] ✔ PUSH ARRIVED — trace:', JSON.stringify(t.trace), '| notifications shown:', shown);
      console.log('[listen] permission:', t.permission);
      delivered = true;
      break;
    }
  }

  if (!delivered) {
    const t = await pushTrace(page);
    console.log('[listen] ✖ no push arrived within 60s — trace:', JSON.stringify(t.trace));
    process.exitCode = 2;
  } else if (shown === 0) {
    console.log('[listen] ⚠ push arrived but no notification object was found (check SW trace "by")');
  } else {
    console.log('[listen] ✔ END-TO-END OK: server → FCM v1 → device SW → system notification');
  }
}

await browser.close();
