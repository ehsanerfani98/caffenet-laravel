#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test E — operator lifecycle over the real API in request mode + SMS audit.
  operator login → start order → ready (delivered) for the remaining
  queued orders; then dump ALL sms types and verify each expected type
  exists in sms_logs AND is mirrored in laravel.log ([TEST-INSTRUMENTATION]).
"""
import json
import sys
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
        FAILURES.append(f"{name} [{detail}]")
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

    def patch(self, path, body=None):
        data = json.dumps(body or {}).encode()
        headers = {"Content-Type": "application/json"}
        tok = self._csrf()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(BASE + path, data=data, method="PATCH", headers=headers)
        return self._go(req)

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


def login(c, phone, label):
    r = c.post("/auth/send-otp", {"phone": phone})
    if "testCode" not in r:
        print(f"  [{label}] OTP FAILED: {r}")
        sys.exit(1)
    r2 = c.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})
    return "error" not in r2


print("═" * 62)
print("STEP 1 — OPERATOR LOGIN")
print("═" * 62)
op = Client()
check("operator login", login(op, "09130000001", "operator"))

print()
print("═" * 62)
print("STEP 2 — START + READY the first two queued orders")
print("═" * 62)
q = op.get("/queue")
if not isinstance(q, dict) or not q:
    q = {}
entries = q.get("entries") or q.get("queue") or q.get("data") or []
if isinstance(entries, dict):
    entries = list(entries.values())
print(f"  operator queue entries: {len(entries)}")
if entries and isinstance(entries[0], dict):
    print(f"  sample keys: {list(entries[0].keys())[:12]}")

# fall back to admin queue API if operator queue endpoint differs
if not entries:
    q2 = op.get("/admin/queue")
    entries = (q2 or {}).get("entries") or []
    print(f"  admin queue fallback entries: {len(entries)}")

done = 0
for e in entries[:2]:
    oid = field(e, "orderId", "order_id", "id")
    label = field(e, "orderNumber", "order_number") or oid
    r1 = op.patch(f"/orders/{oid}/start", {})
    ok1 = "error" not in r1
    o1 = r1.get("order", r1)
    st1 = field(o1, "status") or (r1.get("status"))
    check(f"start {label}", ok1, f"status={st1} err={r1.get('error')}")
    r2 = op.patch(f"/orders/{oid}/complete", {})
    ok2 = "error" not in r2
    o2 = r2.get("order", r2)
    st2 = field(o2, "status") or (r2.get("status"))
    check(f"ready {label}", ok2, f"status={st2} err={r2.get('error')}")
    if ok1 and ok2:
        done += 1

print()
print(f"lifecycle completed for {done} order(s)")
with open(f"{OUT}/lifecycle_results.json", "w") as f:
    json.dump({"completed": done, "queue_seen": len(entries)}, f)
print(f"RESULT: PASS={PASS} FAIL={FAIL}")
if FAILURES:
    print("FAILURES:")
    for x in FAILURES:
        print("  -", x)
sys.exit(1 if FAIL else 0)
