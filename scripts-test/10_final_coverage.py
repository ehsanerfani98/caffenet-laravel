#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Test H — real no-response endpoint cycle + new_order_admin SMS:
  1. Configure admin phone → wallet order → 'new_order_admin' SMS fires
  2. Operator presses «پاسخ نداد» (PATCH /no-response) on a live order
     → deadline armed → cron sweep requeues → customer sees tail position
  3. Final SMS coverage table from sms_logs
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
    def get(self, path):
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
    client.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})


def tinker(code):
    q = subprocess.run(["/home/z/tools/php84/php", "artisan", "tinker", "--execute=" + code],
                       cwd="/home/z/my-project/repo-caffenet", capture_output=True, text=True)
    return q.stdout.strip().splitlines()[-1] if q.returncode == 0 else f"ERR:{q.stderr[:120]}"


print("STEP 1 — configure smsNewOrderAdminPhone + wallet order by C1")
print("  set admin phone:", tinker("App\\Services\\SettingsService::set('smsNewOrderAdminPhone','09120000000'); echo 'ok';"))
admin_sms_before = tinker("echo DB::table('sms_logs')->where('type','new_order_admin')->count();")
c1 = Client(); login(c1, "09111111111")
r = c1.post("/orders", {"serviceId": "srv-print-doc",
                        "formData": {"pageCount": 5, "copies": 1, "printColor": "bw", "paperSize": "A4", "binding": "none"},
                        "contactMethods": ["phone"], "urgency": "normal",
                        "deliveryMethod": "download", "paymentMethod": "wallet"})
oid = r.get("order", {}).get("id") if "error" not in r else None
print("  create →", r.get("order", {}).get("orderNumber") if oid else r)
r2 = c1.post(f"/orders/{oid}/pay-wallet", {})
print("  pay →", r2.get("order", {}).get("status") if "error" not in r2 else r2)
admin_sms_after = tinker("echo DB::table('sms_logs')->where('type','new_order_admin')->count();")
print(f"  new_order_admin SMS: {admin_sms_before} → {admin_sms_after}")

print()
print("STEP 2 — real no-response flow on C1's new order")
op = Client(); login(op, "09130000001")
r = op.patch(f"/orders/{oid}/start")
print("  operator start →", r.get("order", r).get("status") if "error" not in r else r)
r = op.patch(f"/orders/{oid}/no-response", {})
print("  «پاسخ نداد» arm →", json.dumps(r, ensure_ascii=False)[:200])
dl = tinker(f"echo DB::table('queue_entries')->where('order_id','{oid}')->value('no_response_deadline') ?? 'NULL';")
print("  deadline armed:", dl)
# expire it artificially (15 minutes pass)
print("  expire deadline:", tinker(f"DB::table('queue_entries')->where('order_id','{oid}')->update(['no_response_deadline'=>now()->subMinutes(16)->toIso8601String()]); echo 'ok';"))
# cron sweep (real job)
print("  sweep:", tinker("(new App\\Jobs\\CheckNoResponseOrders)->handle(app(App\\Contracts\\AutoRequeueServiceInterface::class)); echo 'done';"))
st = tinker(f"echo DB::table('orders')->where('id','{oid}')->value('status');")
pos = tinker(f"echo DB::table('queue_entries')->where('order_id','{oid}')->value('position') ?? 'TAIL-DEQ';")
cnt = tinker(f"echo DB::table('queue_entries')->where('order_id','{oid}')->value('requeue_count') ?? '-';")
print(f"  after sweep → status={st} pos={pos} requeue_count={cnt}")
requeued_sms = tinker("echo DB::table('sms_logs')->where('type','requeued')->where('timestamp','>',now()->subMinutes(2)->toIso8601String())->count();")
print(f"  requeued SMS in last 2min: {requeued_sms} (expect 1)")

print()
print("STEP 3 — final SMS coverage table")
print(tinker("""
$types = DB::table('sms_logs')->select('type', DB::raw('count(*) as c'))->groupBy('type')->pluck('c','type');
foreach ($types as $t => $c) { echo str_pad($t, 18).' x'.$c.PHP_EOL; }
"""))
