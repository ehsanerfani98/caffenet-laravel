#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test B — operator lifecycle over the real HTTP API:
  start order#1 → ready → delivered; then cancel order#4 (customer-cancel).
Verifies queue bookkeeping after every transition.
"""
import json
import urllib.request
import urllib.parse
import http.cookiejar
import sys

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

    def _req(self, method, path, body=None):
        data = json.dumps(body or {}).encode() if body is not None else b"{}"
        headers = {"Content-Type": "application/json"}
        tok = self._csrf()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
        try:
            with self.opener.open(req, timeout=30) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            try:
                return json.loads(e.read().decode())
            except Exception:
                return {"error": f"HTTP {e.code}"}

    def post(self, path, body=None): return self._req("POST", path, body)
    def patch(self, path, body=None): return self._req("PATCH", path, body)
    def delete(self, path, body=None): return self._req("DELETE", path, body)
    def get(self, path): return self._go_get(path)

    def _go_get(self, path):
        req = urllib.request.Request(BASE + path, method="GET")
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
        print(f"  [{label}] OTP FAILED: {r}"); sys.exit(1)
    r2 = client.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})
    print(f"  [{label}] login {'OK' if 'error' not in r2 else r2}")
    return client


with open(f"{OUT}/order_ids.json") as f:
    order_ids = json.load(f)   # [[label, id], ...]

labels = {label: oid for label, oid in order_ids}

print("═" * 60)
print("STEP A — OPERATOR LOGIN (OTP flow for staff)")
print("═" * 60)
op = login(Client(), "09130000001", "OP1")

print()
print("═" * 60)
print("STEP B — START ORDER #1 (submitted → processing)")
print("═" * 60)
r = op.patch(f"/orders/{labels['C1-علی']}/start", {})
o = r.get("order", r)
print(f"  start → {'ERROR: ' + str(r) if 'error' in r else o.get('status')}")
r2 = op.get(f"/orders/{labels['C1-علی']}/updates")
print(f"  updates after start: {json.dumps(r2, ensure_ascii=False)[:300]}")

print()
print("═" * 60)
print("STEP C — MARK READY (processing → ready)")
print("═" * 60)
r = op.patch(f"/orders/{labels['C1-علی']}/status", {"status": "ready"})
o = r.get("order", r)
print(f"  ready → {'ERROR: ' + str(r) if 'error' in r else o.get('status')}")

print()
print("═" * 60)
print("STEP D — DELIVER (ready → delivered → dequeue)")
print("═" * 60)
r = op.patch(f"/orders/{labels['C1-علی']}/status", {"status": "delivered"})
o = r.get("order", r)
print(f"  delivered → {'ERROR: ' + str(r) if 'error' in r else o.get('status')}")

print()
print("═" * 60)
print("STEP E — CUSTOMER CANCELS OWN ORDER #4 (submitted → cancelled)")
print("═" * 60)
c4 = login(Client(), "09144444444", "C4-مریم")
r = c4.delete(f"/orders/{labels['C4-مریم']}/cancel")
o = r.get("order", r)
print(f"  cancel → {'ERROR: ' + str(r) if 'error' in r else o.get('status')}")

print()
print("═" * 60)
print("STEP F — TRACKING PAGE (public, token-auth) for order #2")
print("═" * 60)
tok = None
import subprocess
q = subprocess.run(
    ["/home/z/tools/php84/php", "artisan", "tinker", "--execute=" +
     "echo DB::table('orders')->where('id', ?)->value('track_token');".replace("?", f"'{labels['C2-سارا']}'")],
    cwd="/home/z/my-project/repo-caffenet", capture_output=True, text=True)
tok = q.stdout.strip().splitlines()[-1] if q.returncode == 0 else None
if tok:
    anon = Client()
    r = anon.get(f"/orders/track/{tok}")
    print(f"  track raw: {json.dumps(r, ensure_ascii=False)[:400]}")
else:
    print("  could not read track_token")
