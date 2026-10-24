#!/usr/bin/env node
/**
 * scripts-test/19_sw_push_sim.mjs
 *
 * Service-worker push-delivery simulation (Node + vm) — regression tests
 * for the "push sent=1 but the device shows NOTHING" bug fixed after
 * comparing this app with the working ehsanerfani98/yadapp implementation.
 *
 * Simulated scenarios:
 *  T1  firebase-messaging-sw.js COLD START (the old killer bug):
 *      a push event wakes the worker; the async SDK init (config fetch)
 *      has NOT finished, so the SDK's own push listener is not attached
 *      yet. The OLD code saw `self.firebase.messaging` (already defined
 *      by importScripts) and returned → message dropped. The FIXED code
 *      checks __sdkReady and shows the notification itself.
 *  T2  firebase-messaging-sw.js WARM start: SDK really attached
 *      (__sdkReady=true) + notification payload → SDK handles it, the
 *      manual handler must NOT double-show.
 *  T3  firebase-messaging-sw.js warm start with a DATA-ONLY payload →
 *      SDK never auto-displays data-only messages → manual show.
 *  T4  sw.js (PWA worker) safety net: notification payload → shown.
 *  T5  sw.js safety net: data-only payload → shown with default title.
 *  T6  fcm.js static guarantee: no fallback to a push-less SW
 *      (`serviceWorker.ready` must not be used to grab a registration).
 *
 * Run: node scripts-test/19_sw_push_sim.mjs
 */
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const fcmSwPath = path.join(root, 'public', 'firebase-messaging-sw.js');
const pwaSwPath = path.join(root, 'public', 'sw.js');
const fcmJsPath = path.join(root, 'public', 'js', 'fcm.js');

let pass = 0;
let fail = 0;
const ok = (cond, name) => {
  if (cond) { pass++; console.log(`  PASS  ${name}`); }
  else { fail++; console.log(`  FAIL  ${name}`); }
};

/** Build a fake ServiceWorkerGlobalScope in a fresh vm context. */
function makeSandbox({ fetchImpl, importScriptsImpl }) {
  const listeners = {};
  const shown = [];
  const waitUntils = [];

  const cachesMock = {
    _store: new Map(),
    async open(name) {
      if (!this._store.has(name)) this._store.set(name, new Map());
      const store = this._store.get(name);
      return {
        match: async (key) => store.get(key) ?? undefined,
        put: async (key, val) => { store.set(key, val); },
      };
    },
  };

  const sandbox = {
    console: { log: () => {}, warn: () => {}, error: () => {}, info: () => {} },
    caches: cachesMock,
    fetch: fetchImpl,
    Response,
    setTimeout,
    clearTimeout,
    importScripts: importScriptsImpl,
    location: { origin: 'https://caffenet.test' },
    addEventListener: (type, fn) => { (listeners[type] ??= []).push(fn); },
    registration: {
      showNotification: async (title, options) => { shown.push({ title, options }); },
      // notificationclick uses clients
    },
    clients: {
      matchAll: async () => [],
      openWindow: async () => {},
    },
    skipWaiting: async () => {},
  };
  sandbox.self = sandbox;
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  return { sandbox, listeners, shown, waitUntils };
}

function dispatch(listeners, type, eventExtras = {}) {
  const bucket = listeners[type] ?? [];
  const pending = [];
  const event = {
    waitUntil(p) { pending.push(Promise.resolve(p)); },
    ...eventExtras,
  };
  for (const fn of bucket) fn(event);
  return pending;
}

const tick = () => new Promise((r) => setTimeout(r, 5));
const PAYLOAD = {
  notification: { title: 'سفارش شما شروع شد', body: 'کار روی سفارش CN-1234 آغاز شد.' },
  data: { url: '/orders/123/track', type: 'order_started' },
};
const payloadData = {
  json: () => PAYLOAD,
  text: () => JSON.stringify(PAYLOAD),
};

// Fake of the Firebase compat SDK *as importScripts leaves it*: the global
// exists immediately, but its push listener only exists after the script
// calls firebase.messaging() (warm init). We emulate "listener attached"
// by letting the sw set __sdkReady itself, like the real flow does.

// ─────────────────────────────────────────────────────────────────────
console.log('\nT1 — firebase-messaging-sw.js COLD START (push wakes worker, config fetch still pending)');
{
  const never = () => new Promise(() => {}); // network hang — like a real cold start
  let wire; // importScripts writes the fake compat SDK onto THIS sandbox
  const importImpl = (...urls) => {
    if (urls.some((u) => String(u).includes('firebase-messaging-compat'))) {
      wire.firebase = {
        apps: [],
        initializeApp(cfg) { this.apps.push(cfg); },
        messaging() { return {}; },
      };
    }
  };
  const s = makeSandbox({ fetchImpl: never, importScriptsImpl: importImpl });
  wire = s.sandbox.self;
  vm.runInContext(readFileSync(fcmSwPath, 'utf8'), s.sandbox, { filename: 'firebase-messaging-sw.js' });
  await tick(); // let the async init IIFE start — it hangs on the config fetch
  ok(s.sandbox.self.__sdkReady === undefined, 'SDK init still pending at push time (__sdkReady unset)');
  ok(typeof s.sandbox.self.firebase?.messaging === 'function',
    'self.firebase.messaging EXISTS (the exact condition that made the old code drop the message)');
  const pend = dispatch(s.listeners, 'push', { data: payloadData });
  await Promise.all(pend).catch(() => {});
  await tick();
  ok(s.shown.length === 1, 'notification SHOWN exactly once (old code: 0 — silently dropped)');
  ok(s.shown[0]?.title === 'سفارش شما شروع شد', 'notification title correct');
  ok(s.shown[0]?.options?.body === 'کار روی سفارش CN-1234 آغاز شد.', 'notification body correct');
  ok(s.shown[0]?.options?.data?.url === '/orders/123/track', 'click-through url passed in data');
  ok(s.sandbox.self.__fw === 'manual', 'delivered through the deterministic manual path');
}

// ─────────────────────────────────────────────────────────────────────
console.log('\nT2 — WARM start: SDK attached (__sdkReady) + notification payload → SDK path, no double show');
{
  const cfg = { enabled: true, apiKey: 'k', projectId: 'p', authDomain: 'a', messagingSenderId: 's', appId: 'i', vapidKey: 'v' };
  let wire;
  const s = makeSandbox({
    fetchImpl: async () => ({ ok: true, json: async () => cfg }),
    importScriptsImpl: (...urls) => {
      if (urls.some((u) => String(u).includes('firebase-messaging-compat'))) {
        wire.firebase = {
          apps: [],
          initializeApp(cfg2) { this.apps.push(cfg2); },
          messaging() { return {}; },
        };
      }
    },
  });
  wire = s.sandbox.self;
  vm.runInContext(readFileSync(fcmSwPath, 'utf8'), s.sandbox, { filename: 'firebase-messaging-sw.js' });
  await tick();
  ok(s.sandbox.self.__sdkReady === true, 'SDK marked ready after firebase.messaging()');
  ok(s.sandbox.self.firebase.apps.length === 1, 'fake app initialized with cached config');
  const pend = dispatch(s.listeners, 'push', { data: payloadData });
  await Promise.all(pend).catch(() => {});
  await tick();
  ok(s.shown.length === 0, 'manual handler did NOT double-show (SDK owns delivery)');
  ok(s.sandbox.self.__fw === 'sdk', 'sdk path taken');
}

// ─────────────────────────────────────────────────────────────────────
console.log('\nT3 — WARM start + DATA-ONLY payload → SDK never auto-displays → manual show');
{
  const cfg = { enabled: true, apiKey: 'k', projectId: 'p', authDomain: 'a', messagingSenderId: 's', appId: 'i' };
  let wire;
  const s = makeSandbox({
    fetchImpl: async () => ({ ok: true, json: async () => cfg }),
    importScriptsImpl: (...urls) => {
      if (urls.some((u) => String(u).includes('firebase-messaging-compat'))) {
        wire.firebase = {
          apps: [],
          initializeApp(c) { this.apps.push(c); },
          messaging() { return {}; },
        };
      }
    },
  });
  wire = s.sandbox.self;
  vm.runInContext(readFileSync(fcmSwPath, 'utf8'), s.sandbox, { filename: 'firebase-messaging-sw.js' });
  await tick();
  const dataOnly = { json: () => ({ data: { type: 'silent-sync' } }), text: () => '{}' };
  const pend = dispatch(s.listeners, 'push', { data: dataOnly });
  await Promise.all(pend).catch(() => {});
  await tick();
  ok(s.shown.length === 1, 'data-only message still surfaces (default title)');
  ok(s.shown[0]?.title === 'اعلان جدید', 'default title used for data-only');
}

// ─────────────────────────────────────────────────────────────────────
console.log('\nT4 — sw.js (PWA worker) safety net: notification payload');
{
  const s = makeSandbox({ fetchImpl: async () => { throw new Error('no network'); }, importScriptsImpl: () => { throw new Error('none'); } });
  vm.runInContext(readFileSync(pwaSwPath, 'utf8'), s.sandbox, { filename: 'sw.js' });
  const pend = dispatch(s.listeners, 'push', { data: payloadData });
  await Promise.all(pend).catch(() => {});
  await tick();
  ok(s.shown.length === 1, 'PWA worker shows the notification (old code: handler missing → dropped)');
  ok(s.shown[0]?.title === 'سفارش شما شروع شد', 'title correct in PWA worker');
}

// ─────────────────────────────────────────────────────────────────────
console.log('\nT5 — sw.js safety net: data-only + non-JSON payloads');
{
  const s = makeSandbox({ fetchImpl: async () => { throw new Error('no network'); }, importScriptsImpl: () => { throw new Error('none'); } });
  vm.runInContext(readFileSync(pwaSwPath, 'utf8'), s.sandbox, { filename: 'sw.js' });
  const dataOnly = { json: () => { throw new Error('not json'); }, text: () => 'plain text ping' };
  const pend = dispatch(s.listeners, 'push', { data: dataOnly });
  await Promise.all(pend).catch(() => {});
  await tick();
  ok(s.shown.length === 1, 'non-JSON push still shown (fallback text)');
  ok(s.shown[0]?.options?.body === 'plain text ping', 'fallback body = raw text');
}

// ─────────────────────────────────────────────────────────────────────
console.log('\nT6 — fcm.js static guarantees');
{
  const src = readFileSync(fcmJsPath, 'utf8');
  ok(!/await\s+navigator\.serviceWorker\.ready/.test(src), 'no navigator.serviceWorker.ready fallback (the deaf-worker trap)');
  ok(src.includes("getRegistration('/fcm/')") && src.includes("scope: '/fcm/'"), 'token registration pinned to the /fcm/ Firebase worker');
  ok(/if \(!swReg\)/.test(src), 'getToken aborted when the Firebase worker is unavailable');
}

console.log(`\n───── RESULT: ${pass} passed, ${fail} failed ─────`);
process.exit(fail === 0 ? 0 : 1);
