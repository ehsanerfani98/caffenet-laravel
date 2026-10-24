#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test G — remaining SMS types over the real API:
  1. start → complete (the operator console path) → order_started + order_ready SMS
  2. markNoResponse strike clock via API → arms deadline (then cron sweep requeues)
  3. new_order_admin notification (admin phone configured)
Then prints the final SMS-type coverage table.
"""
import json, subprocess, sys, urllib.request, urllib.parse, http.cookiejar

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


def login(client, phone):
    r = client.post("/auth/send-otp", {"phone": phone})
    if "testCode" not in r:
        print(f"OTP FAILED {phone}: {r}"); sys.exit(1)
    r2 = client.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})
    return "error" not in r2


print("STEP 1 — operator login")
op = Client()
print("  operator login:", login(op, "09130000001"))

print("STEP 2 — start + complete the pipeline order (CN-PIPE-0001)")
r = op.patch("/orders/ord-pipeline-1/start")
print("  start →", r.get("order", r).get("status") if "error" not in r else r)
r = op.patch("/orders/ord-pipeline-1/complete")
print("  complete →", r.get("order", r).get("status") if "error" not in r else r)

print("STEP 3 — arm no-response clock via the REAL endpoint (on C3's order)")
# find C3's active order
import sqlite3
q = subprocess.run(
    ["/home/z/tools/php84/php", "artisan", "tinker", "--execute=" +
     "$o = DB::table('orders')->where('status','submitted')->where('customer_phone','09133333333')->first();"
     "echo $o ? $o->id : 'NONE';"],
    cwd="/home/z/my-project/repo-caffenet", capture_output=True, text=True)
oid = q.stdout.strip().splitlines()[-1] if q.returncode == 0 else "NONE"
print("  C3 order:", oid)
if oid != "NONE":
    r = op.patch(f"/orders/{oid}/no-response", {})
    print("  no-response arm →", json.dumps(r, ensure_ascii=False)[:160])
    # operator then starts it → strike clock should clear
    r = op.patch(f"/orders/{oid}/start")
    print("  operator start (customer answered) →", r.get("order", r).get("status") if "error" not in r else r)
    print("  deadline in DB:", subprocess.run(
        ["/home/z/tools/php84/php", "artisan", "tinker", "--execute=" +
         f"echo DB::table('queue_entries')->where('order_id','{oid}')->value('no_response_deadline') ?? 'NULL';"],
        cwd="/home/z/my-project/repo-caffenet", capture_output=True, text=True).stdout.strip().splitlines()[-1])
