#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test A — full order registration flow for 4 customers over the real HTTP API:
  login (OTP) → create order → wallet pay → enqueue
Then dumps the queue + estimates for manual verification of the math.
"""
import json
import urllib.request
import http.cookiejar
import sys
import urllib.parse

BASE = "http://127.0.0.1:8090/api"
OUT = "/home/z/my-project/repo-caffenet/scripts-test"


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        # prime session + XSRF-TOKEN cookie
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
        req = urllib.request.Request(BASE + path, data=data, method="POST", headers=headers)
        return self._go(req)

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


def login(client, phone, label):
    r = client.post("/auth/send-otp", {"phone": phone})
    if "testCode" not in r:
        print(f"  [{label}] OTP FAILED: {r}")
        sys.exit(1)
    code = r["testCode"]
    r2 = client.post("/auth/verify-otp", {"phone": phone, "code": code})
    ok = "error" not in r2
    user = r2.get("user", {})
    print(f"  [{label}] OTP={code} verify={'OK' if ok else r2}  role={user.get('role','-')}")
    return ok


def field(o, *names):
    for n in names:
        if n in o:
            return o[n]
    return None


ORDERS = [
    ("09111111111", "C1-علی",  "srv-print-doc",        {"pageCount": 10, "copies": 1, "printColor": "bw", "paperSize": "A4", "binding": "none"}),
    ("09122222222", "C2-سارا", "srv-student-register", {"university": "دانشگاه تهران", "serviceLevel": "full"}),
    ("09133333333", "C3-رضا",  "srv-gov-followup",     {"subject": "پیگیری نامه اداری"}),
    ("09144444444", "C4-مریم", "srv-tax-loan",         {"taxType": "loan"}),
]

print("═" * 60)
print("STEP 1 — LOGIN (OTP via real API)")
print("═" * 60)
clients = {}
for phone, label, _, _ in ORDERS:
    c = Client()
    login(c, phone, label)
    clients[label] = c

print()
print("═" * 60)
print("STEP 2 — CREATE ORDERS (wallet payment method)")
print("═" * 60)
order_ids = []
for phone, label, service, form in ORDERS:
    r = clients[label].post("/orders", {
        "serviceId": service,
        "formData": form,
        "contactMethods": ["phone"],
        "urgency": "normal",
        "deliveryMethod": "download",
        "paymentMethod": "wallet",
    })
    if "error" in r:
        print(f"  [{label}] CREATE FAILED: {r}")
        continue
    o = r.get("order", r)
    oid = field(o, "id", "orderId")
    order_ids.append((label, oid))
    print(f"  [{label}] created id={oid} number={field(o,'orderNumber','order_number')} "
          f"status={o.get('status')} total={field(o,'totalPrice','total_price')} "
          f"queueType={field(o,'queueType','queue_type')}")

print()
print("═" * 60)
print("STEP 3 — WALLET PAY → SUBMITTED → ENQUEUE")
print("═" * 60)
for label, oid in order_ids:
    r = clients[label].post(f"/orders/{oid}/pay-wallet", {})
    if "error" in r:
        print(f"  [{label}] PAY FAILED: {r}")
        continue
    o = r.get("order", r)
    print(f"  [{label}] order {oid}: status={o.get('status')} "
          f"pos={field(o,'queuePosition','queue_position')} "
          f"wait={field(o,'estimatedWaitMinutes','estimated_wait_minutes')}min "
          f"dur={field(o,'estimatedDurationMinutes','estimated_duration_minutes')}min "
          f"start={field(o,'estimatedStartAt','estimated_start_at')} "
          f"end={field(o,'estimatedEndAt','estimated_end_at')} "
          f"track={'yes' if field(o,'trackToken','track_token') else 'NO'}")

# persist ids for the next test scripts
with open(f"{OUT}/order_ids.json", "w") as f:
    json.dump(order_ids, f)
print(f"\nsaved → {OUT}/order_ids.json")
