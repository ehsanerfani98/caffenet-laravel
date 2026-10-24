#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
End-to-end test for the three new chat/contact features:
  A. unread-chat API: /api/chat/unread + POST /orders/{id}/ticket-read
     - customer sends a message → staff unread goes up, customer stays 0
     - staff read-receipt → staff unread back to 0
     - staff reply → customer unread goes up → customer read → 0
  B. vCard: GET /orders/{id}/customer-vcard (staff 200 text/vcard, customer 403)
  C. order payload exposes staffReadAt/customerReadAt (badge data source)
Run: python3 scripts-test/16_chat_notify.py  (server on :8090, seeded DB)
"""
import json, sys, urllib.request, urllib.parse, http.cookiejar

BASE = "http://127.0.0.1:8090/api"
PASS = 0
FAIL = 0

def ok(name, cond, detail=""):
    global PASS, FAIL
    if cond: PASS += 1; print(f"  ✅ {name}" + (f"  [{detail}]" if detail else ""))
    else: FAIL += 1; print(f"  ❌ {name}  [{detail}]")

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

    def _req(self, method, path, body=None, raw=False):
        data = json.dumps(body or {}).encode() if body is not None else b"{}"
        headers = {"Content-Type": "application/json"}
        tok = self._csrf()
        if tok: headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(BASE + path, data=data if method != "GET" else None,
                                     method=method, headers=headers)
        try:
            with self.opener.open(req, timeout=30) as r:
                b = r.read().decode()
                return (b, dict(r.headers)) if raw else json.loads(b)
        except urllib.error.HTTPError as e:
            b = e.read().decode()
            if raw: return b, dict(e.headers)
            try: return json.loads(b)
            except Exception: return {"error": f"HTTP {e.code}"}

    def post(self, path, body=None): return self._req("POST", path, body)
    def get(self, path): return self._req("GET", path)
    def get_raw(self, path): return self._req("GET", path, raw=True)

def login(c, phone):
    r = c.post("/auth/send-otp", {"phone": phone})
    if "testCode" not in r:
        print(f"OTP FAILED {phone}: {r}"); sys.exit(1)
    r2 = c.post("/auth/verify-otp", {"phone": phone, "code": r["testCode"]})
    return "error" not in r2

print("═" * 62)
print("SETUP — login customer + operator, create order, wallet pay")
print("═" * 62)
cust = Client(); print("  customer login:", login(cust, "09111111111"))
op = Client();   print("  operator login:", login(op, "09130000001"))

r = cust.post("/orders", {"serviceId": "srv-print-doc",
    "formData": {"pageCount": 3, "copies": 1, "printColor": "bw", "paperSize": "A4", "binding": "none"},
    "contactMethods": ["phone"], "urgency": "normal", "deliveryMethod": "download",
    "paymentMethod": "wallet"})
o = r.get("order", r)
oid = o.get("id")
print("  order:", o.get("orderNumber"))
r2 = cust.post(f"/orders/{oid}/pay-wallet", {})
print("  paid:", r2.get("order", r2).get("status"))

print()
print("═" * 62)
print("A) UNREAD FLOW — customer writes → operator sees «پیام جدید»")
print("═" * 62)
u0c = cust.get("/chat/unread"); u0s = op.get("/chat/unread")
ok("baseline: customer unread=0", u0c.get("total") == 0, f"total={u0c.get('total')}")
ok("baseline: staff unread=0", u0s.get("total") == 0, f"total={u0s.get('total')}")

r = cust.post(f"/orders/{oid}/ticket", {"message": "سلام، فایل پیوست را تغییر دادم؛ لطفاً بررسی کنید."})
ok("customer sends chat message", "error" not in r and r.get("id"), json.dumps(r, ensure_ascii=False)[:80])

u1s = op.get("/chat/unread")
ok("staff unread=1 after customer message", u1s.get("total") == 1, f"total={u1s.get('total')}")
top = (u1s.get("orders") or [{}])[0]
ok("unread summary carries the right order", top.get("orderId") == oid, f"order={top.get('orderId','')[:13]}…")
ok("unread summary carries orderNumber", bool(top.get("orderNumber")), str(top.get("orderNumber")))
ok("unread summary carries lastMessage", "بررسی" in (top.get("lastMessage") or ""), (top.get("lastMessage") or "")[:40])
ok("unread summary carries customerName", bool(top.get("customerName")), str(top.get("customerName")))
u1c = cust.get("/chat/unread")
ok("customer own message is NOT unread for them", u1c.get("total") == 0, f"total={u1c.get('total')}")

# staff read receipt
r = op.post(f"/orders/{oid}/ticket-read", {})
ok("staff read-receipt marks 1 message", r.get("marked") == 1, f"marked={r.get('marked')}")
u2s = op.get("/chat/unread")
ok("staff unread=0 after read", u2s.get("total") == 0, f"total={u2s.get('total')}")

# second customer message while staff reading → still counts once after re-read
cust.post(f"/orders/{oid}/ticket", {"message": "پیام دوم"})
u3s = op.get("/chat/unread")
ok("second customer message → staff unread=1", u3s.get("total") == 1, f"total={u3s.get('total')}")
op.post(f"/orders/{oid}/ticket-read", {})

print()
print("═" * 62)
print("A2) REPLY DIRECTION — operator replies → customer sees badge")
print("═" * 62)
r = op.post(f"/orders/{oid}/ticket", {"message": "دریافت شد؛ تا ۱۰ دقیقه دیگر آماده می‌شود."})
ok("operator reply sent", "error" not in r)
u4c = cust.get("/chat/unread")
ok("customer unread=1 after staff reply", u4c.get("total") == 1, f"total={u4c.get('total')}")
u4s = op.get("/chat/unread")
ok("staff does not see own reply as unread", u4s.get("total") == 0, f"total={u4s.get('total')}")
r = cust.post(f"/orders/{oid}/ticket-read", {})
ok("customer read-receipt works", r.get("marked") == 1, f"marked={r.get('marked')}")
u5c = cust.get("/chat/unread")
ok("customer unread=0 after read", u5c.get("total") == 0, f"total={u5c.get('total')}")

# access control: another customer cannot read someone else's chat
cust2 = Client(); login(cust2, "09122222222")
r = cust2.post(f"/orders/{oid}/ticket-read", {})
ok("other customer cannot read-receipt foreign order", "error" in r, str(r)[:60])

print()
print("═" * 62)
print("B) VCARD — operator taps the customer phone")
print("═" * 62)
body, headers = op.get_raw(f"/orders/{oid}/customer-vcard")
ct = headers.get("Content-Type", "")
ok("staff gets vCard 200", "BEGIN:VCARD" in body and "END:VCARD" in body, f"ctype={ct[:30]}")
ok("Content-Type is text/vcard", "text/vcard" in ct, ct[:40])
ok("Content-Disposition attachment .vcf", "attachment" in headers.get("Content-Disposition", "") and ".vcf" in headers.get("Content-Disposition", ""))
ok("vCard FN has customer name", "FN:مشتری علی" in body, "FN:مشتری علی")
ok("vCard TEL has phone", "TEL;TYPE=CELL:09111111111" in body, "TEL row")
ok("vCard NOTE has order number", "NOTE:" in body and "CN-" in body, "note row")
body_c, _ = cust.get_raw(f"/orders/{oid}/customer-vcard")
try: jc = json.loads(body_c)
except Exception: jc = {}
ok("customer gets 403 on vCard", jc.get("error") is not None, str(jc)[:60])

print()
print("═" * 62)
print("C) ORDER PAYLOAD — badge data source (read stamps exposed)")
print("═" * 62)
o_full = op.get(f"/orders/{oid}")
msgs = o_full.get("ticketMessages") or []
ok("order payload has 3 messages", len(msgs) == 3, f"n={len(msgs)}")
staff_view_ok = all(("staffReadAt" in m and "customerReadAt" in m) for m in msgs)
ok("messages expose staffReadAt/customerReadAt", staff_view_ok)
cust_msgs = [m for m in msgs if not m.get("isOperatorResponse")]
ok("customer messages carry staffReadAt (read by staff)", all(m.get("staffReadAt") for m in cust_msgs),
   f"{len(cust_msgs)} customer msgs")
op_msgs = [m for m in msgs if m.get("isOperatorResponse")]
ok("staff replies carry customerReadAt only after read", all(m.get("customerReadAt") for m in op_msgs),
   f"{len(op_msgs)} staff msgs")

# fresh unread from a brand-new message reflects on /chat/unread instantly
cust.post(f"/orders/{oid}/ticket", {"message": "پیام سوم — بعد از خواندن قبلی"})
u6s = op.get("/chat/unread")
ok("new message re-lights staff unread", u6s.get("total") == 1, f"total={u6s.get('total')}")
op.post(f"/orders/{oid}/ticket-read", {})
u7s = op.get("/chat/unread")
ok("and clears again", u7s.get("total") == 0, f"total={u7s.get('total')}")

print()
print("═" * 62)
print(f"RESULT: PASS={PASS} FAIL={FAIL}")
print("═" * 62)
sys.exit(1 if FAIL else 0)
