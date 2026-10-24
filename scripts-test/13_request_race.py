#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test C — concurrent request-mode registration race.
6 customers login first (sequentially), then fire their order POST at the
SAME instant (thread barrier).
Expectation: positions continue 1..6 with no duplicates/gaps.
"""
import json
import sys
import threading
import urllib.request
import urllib.error
import urllib.parse
import http.cookiejar

BASE = "http://127.0.0.1:8090/api"
OUT = "/home/z/my-project/repo-caffenet/scripts-test"


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
            with self.opener.open(req, timeout=60) as r:
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


PHONES = ["09111111111", "09122222222", "09133333333",
          "09144444444", "09155555555", "09166666666"]
SERVICES = ["srv-print-doc", "srv-student-register", "srv-gov-followup",
            "srv-tax-loan", "srv-print-doc", "srv-student-register"]
FORMS = [
    {"pageCount": 1, "copies": 1, "printColor": "bw", "paperSize": "A4", "binding": "none"},
    {"university": "دانشگاه تهران", "serviceLevel": "basic"},
    {"subject": "پیگیری"},
    {"taxType": "loan"},
    {"pageCount": 2, "copies": 1, "printColor": "color", "paperSize": "A4", "binding": "none"},
    {"university": "دانشگاه شیراز", "serviceLevel": "basic"},
]

# 1) sequential login for all six (OTPs out of the race window)
clients = []
for ph in PHONES:
    c = Client()
    r = c.post("/auth/send-otp", {"phone": ph})
    code = r.get("testCode")
    if not code:
        print("OTP FAILED", ph, r)
        sys.exit(1)
    c.post("/auth/verify-otp", {"phone": ph, "code": code})
    clients.append(c)
print(f"logged in {len(clients)} customers")

# 2) simultaneous POST
barrier = threading.Barrier(len(clients) + 1)
results = [None] * len(clients)


def worker(i):
    barrier.wait()
    res = clients[i].post("/orders", {
        "serviceId": SERVICES[i],
        "formData": FORMS[i],
        "contactMethods": ["phone"],
        "urgency": "normal",
        "deliveryMethod": "download",
        "paymentMethod": "request",
    })
    o = res.get("order", res)
    results[i] = {"phone": PHONES[i], "id": field(o, "id"), "status": o.get("status"),
                  "pos": field(o, "queuePosition", "queue_position"),
                  "wait": field(o, "estimatedWaitMinutes", "estimated_wait_minutes"),
                  "err": res.get("error")}


threads = [threading.Thread(target=worker, args=(i,)) for i in range(len(clients))]
for t in threads:
    t.start()
barrier.wait()  # fire!
for t in threads:
    t.join()

print("── race results ──")
positions = []
ok = True
for r in sorted([x for x in results if x], key=lambda x: (x.get("pos") or 999)):
    print(f"  phone={r.get('phone')} id={r.get('id')} status={r.get('status')} pos={r.get('pos')} wait={r.get('wait')} err={r.get('err')}")
    if r.get("err"):
        ok = False
    if r.get("pos"):
        positions.append(r["pos"])

uniq = len(set(positions)) == len(positions)
all_submitted = all(r.get("status") == "submitted" for r in results if r and r.get("id"))
print()
print(f"positions={sorted(positions)}")
print(f"UNIQUE={uniq}  ALL_SUBMITTED={all_submitted}")
verdict = ok and uniq and all_submitted and len(positions) == len(clients)
print("RACE:", "PASS ✅" if verdict else "FAIL ❌")
with open(f"{OUT}/race_results.json", "w") as f:
    json.dump(results, f, ensure_ascii=False, indent=1)
sys.exit(0 if verdict else 1)
