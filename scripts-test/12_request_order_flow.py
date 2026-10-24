#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test B — «حالت ثبت درخواست» (paymentsDisabled=1) order flow over the real HTTP API.

6 customers register orders with paymentMethod='request'. No payment step exists:
  - order must be born `submitted`, paid_amount=0, payment_method='request'
  - wallet balance must remain untouched (0)
  - order must enter the operator queue IMMEDIATELY (position 1..6)
  - ETA math with 2 active operators and per-order duration 10 min:
      wait_k = ceil((k-1)*10 / 2) → 0, 5, 10, 15, 20, 25
  - public /track/{token} must work in request mode
"""
import json
import sys
import time
import urllib.request
import urllib.error
import urllib.parse
import http.cookiejar

BASE = "http://127.0.0.1:8090/api"
OUT = "/home/z/my-project/repo-caffenet/scripts-test"

PASS, FAIL = 0, 0
FAILURES = []


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print(f"  ✅ {name}" + (f"  [{detail}]" if detail else ""))
    else:
        FAIL += 1
        FAILURES.append(f"{name} {detail}")
        print(f"  ❌ {name}  [{detail}]")


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.get("/health")

    def _csrf(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def post(self, path, body=None):
        data = json.dumps(body or {}).encode()
        headers = {"Content-Type": "application/json"}
        tok = self._csrf()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        return self._go(urllib.request.Request(BASE + path, data=data, method="POST", headers=headers))

    def get(self, path):
        return self._go(urllib.request.Request(BASE + path, method="GET"))

    def _go(self, req):
        try:
            with self.opener.open(req, timeout=30) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            try:
                return json.loads(e.read().decode())
            except Exception:
                return {"error": f"HTTP {e.code}"}


def field(o, *names):
    for n in names:
        if n in o:
            return o[n]
    return None


CUSTOMERS = [
    ("09111111111", "C1-علی",   "srv-print-doc",        {"pageCount": 10, "copies": 1, "printColor": "bw", "paperSize": "A4", "binding": "none"}),
    ("09122222222", "C2-سارا",  "srv-student-register", {"university": "دانشگاه تهران", "serviceLevel": "full"}),
    ("09133333333", "C3-رضا",   "srv-gov-followup",     {"subject": "پیگیری نامه اداری"}),
    ("09144444444", "C4-مریم",  "srv-tax-loan",         {"taxType": "loan"}),
    ("09155555555", "C5-حسین",  "srv-print-doc",        {"pageCount": 4, "copies": 2, "printColor": "color", "paperSize": "A4", "binding": "plastic_coil"}),
    ("09166666666", "C6-نگار",  "srv-student-register", {"university": "دانشگاه شیراز", "serviceLevel": "basic"}),
]
EXPECTED_WAIT = [0, 5, 10, 15, 20, 25]  # ceil((k-1)*10/2), 2 active operators, dur=10

print("═" * 62)
print("STEP 1 — LOGIN 6 CUSTOMERS (OTP)")
print("═" * 62)
clients = {}
for phone, label, _, _ in CUSTOMERS:
    c = Client()
    r = c.post("/auth/send-otp", {"phone": phone})
    if "testCode" not in r:
        print(f"  [{label}] OTP FAILED: {r}")
        sys.exit(1)
    r2 = c.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})
    check(f"login {label}", "error" not in r2, f"role={r2.get('user',{}).get('role','-')}")
    clients[label] = c

print()
print("═" * 62)
print("STEP 2 — REGISTER ORDERS with paymentMethod='request' (NO payment)")
print("═" * 62)
orders = []
for i, (phone, label, service, form) in enumerate(CUSTOMERS):
    r = clients[label].post("/orders", {
        "serviceId": service,
        "formData": form,
        "contactMethods": ["phone"],
        "urgency": "normal",
        "deliveryMethod": "download",
        "paymentMethod": "request",
    })
    if "error" in r:
        check(f"create {label}", False, str(r))
        continue
    o = r.get("order", r)
    oid = field(o, "id", "orderId")
    status = o.get("status")
    paid = field(o, "paidAmount", "paid_amount")
    pmethod = field(o, "paymentMethod", "payment_method")
    pos = field(o, "queuePosition", "queue_position")
    wait = field(o, "estimatedWaitMinutes", "estimated_wait_minutes")
    dur = field(o, "estimatedDurationMinutes", "estimated_duration_minutes")
    track = field(o, "trackToken", "track_token")
    print(f"  [{label}] id={oid} number={field(o,'orderNumber','order_number')} status={status} "
          f"paid={paid} pmethod={pmethod} pos={pos} wait={wait} dur={dur}")
    orders.append({
        "label": label, "phone": phone, "id": oid, "number": field(o, "orderNumber", "order_number"),
        "status": status, "paid": paid, "pmethod": pmethod, "pos": pos, "wait": wait,
        "dur": dur, "track": track,
    })

print()
print("═" * 62)
print("STEP 3 — VERIFY REQUEST-MODE INVARIANTS")
print("═" * 62)
for i, o in enumerate(orders):
    check(f"{o['label']} born submitted", o["status"] == "submitted", f"status={o['status']}")
    check(f"{o['label']} paid_amount=0", o["paid"] == 0, f"paid={o['paid']}")
    check(f"{o['label']} paymentMethod=request", o["pmethod"] == "request", f"pmethod={o['pmethod']}")
    check(f"{o['label']} track token issued", bool(o["track"]))
    check(f"{o['label']} position={i+1}", o["pos"] == i + 1, f"pos={o['pos']} expected={i+1}")
    check(f"{o['label']} wait={EXPECTED_WAIT[i]}", o["wait"] == EXPECTED_WAIT[i],
          f"wait={o['wait']} expected={EXPECTED_WAIT[i]}")
    check(f"{o['label']} duration=10", o["dur"] == 10, f"dur={o['dur']}")

print()
print("═" * 62)
print("STEP 4 — WALLETS UNTOUCHED + PUBLIC TRACK")
print("═" * 62)
for phone, label, *_ in CUSTOMERS:
    me = clients[label].get("/auth/me") or clients[label].get("/me")
    bal = field(me.get("user", me), "walletBalance", "wallet_balance") if me else None
    check(f"{label} wallet still 0", bal == 0, f"balance={bal}")

o1 = orders[0]
tr = Client().get(f"/orders/track/{o1['track']}")
track_ok = "error" not in tr and (field(tr, "orderNumber", "order_number") == o1["number"] or tr.get("order", {}).get("orderNumber") == o1["number"])
check(f"public /api/orders/track for {o1['label']}", track_ok, str({k: tr.get(k) for k in ('error', 'orderNumber', 'status')})[:120])

print()
print(f"RESULT: PASS={PASS} FAIL={FAIL}")
if FAILURES:
    print("FAILURES:")
    for f in FAILURES:
        print("  -", f)
with open(f"{OUT}/request_order_ids.json", "w") as f:
    json.dump(orders, f, ensure_ascii=False, indent=1)
print(f"saved → {OUT}/request_order_ids.json")
sys.exit(1 if FAIL else 0)
