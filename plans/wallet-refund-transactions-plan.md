# Plan: Refunds, wallet ledger, the balance bug, and admin/order monitoring

## Goals (from the request)

1. **Refund capability** — money can be refunded back to a user's **wallet**.
2. **Only admin/manager** may refund (never operators/customers).
3. Refunded amounts are **credited to the user's wallet**.
4. **Every** money movement is recorded in the DB and traceable in the admin panel:
   wallet top-up, service purchase / wallet debit, refund, wallet credit,
   manual wallet charge by management, etc.
5. **Bug**: after charging the wallet and paying for a service, the wallet
   balance appears unchanged (nothing is deducted). Investigate and fix.
6. **New admin section**: see every customer's orders, which operator performed
   the service, and monitor all order/service statuses.
7. **Customer panel**: the customer can see all of their own transactions.

Chosen design (confirmed with the user): **both** an order-based refund and a
free-form credit to any customer's wallet; add a `refunded` order status; add a
dedicated «سفارش‌ها» tab to the admin bottom nav.

---

## Root-cause of the wallet bug (#5)

The debit itself is correct — [`OrderController::payWithWallet()`](../../app/Http/Controllers/Api/OrderController.php:237)
runs `DB::table('users')->where('id', $user->id)->decrement('wallet_balance', $payable)`.

The bug is **client-side state**: after paying, [`api.payOrderWithWallet()`](../../public/js/api.js:222)
only calls `syncWithBackend()`, which fetches categories/services/settings/slider/orders
but **never `/api/auth/me`**. So `api.state.currentUser.walletBalance` stays stale,
and every balance display reads that stale value:

- [`ui.js`](../../public/js/ui.js:114) — app-bar wallet chip
- [`wallet.js`](../../public/js/screens/wallet.js:56) — wallet page
- [`landing.js`](../../public/js/screens/landing.js:270) — profile
- [`order-form.js`](../../public/js/screens/order-form.js:219) — order form balance check

**Fix**: make `syncWithBackend()` refresh `currentUser` (re-read `/api/auth/me`)
when a session exists. This is the single, global point that also fixes the
display after top-up, refund, and manual admin credit, and matches the earlier
note in [`wallet-only-order-payments-plan.md`](wallet-only-order-payments-plan.md:74).

---

## Backend

### New table: `wallet_transactions` (the ledger)

Single source of truth for every balance movement.

| column | type | notes |
|---|---|---|
| `id` | string PK | prefix `wtx` |
| `user_id` | string | wallet owner |
| `order_id` | string null | linked order (purchase/refund) |
| `payment_id` | string null | linked gateway payment (top-up) |
| `type` | string | `topup` \| `purchase` \| `refund` \| `manual_credit` \| `manual_debit` |
| `direction` | string | `credit` \| `debit` |
| `amount` | integer | always positive magnitude |
| `balance_after` | integer | snapshot for reconciliation/traceability |
| `reference` | string null | tracking code / ref id / gateway token |
| `note` | string/text null | reason, human readable |
| `performed_by_id` | string null | admin who performed it |
| `performed_by_name` | string null | display name |
| `metadata_json` | text null | gateway, sku, etc. |
| `created_at` | string(32) | ISO timestamp |

Indexes: `user_id`, `order_id`, `type`, `created_at`.

### New columns on `orders`
- `refund_amount` integer default 0 — accumulated refunded amount
- `refunded_at` string(32) null

### New order status
`Order::STATUS_REFUNDED = 'refunded'` («عودت شده») added to `STATUSES`,
`STATUS_TITLES`, and `NON_QUEUE_STATUSES` (a refunded order leaves the operator queue).

### New service: `app/Services/WalletService.php`
- `credit(User $u, int $amount, string $type, array $opts): WalletTransaction`
- `debit(User $u, int $amount, string $type, array $opts): WalletTransaction`
- `refund(User $u, int $amount, array $opts): WalletTransaction`

Both credit/debit run in a transaction, re-read the balance with
`lockForUpdate()`, apply the delta (debit throws `INSUFFICIENT_WALLET_BALANCE`
if it would go negative), persist the new balance, and insert the ledger row with
`balance_after`. This centralises the balance + ledger write so they can never drift.

### Refactor all money movements onto the service
1. [`PaymentFulfillmentService::apply()`](../../app/Services/PaymentFulfillmentService.php:67) — top-up credit → `WalletService::credit(type=topup)`.
2. [`OrderController::payWithWallet()`](../../app/Http/Controllers/Api/OrderController.php:237) — → `WalletService::debit(type=purchase)`; handle the rare race by returning the structured `INSUFFICIENT_WALLET` payload.
3. [`UserController::update()`](../../app/Http/Controllers/Api/UserController.php:83) — manual `walletChange` → `manual_credit` / `manual_debit`, recording the acting admin. Negative delta is clamped to the available balance (preserves the old floor-at-zero behaviour).

### New permissions
Add a `wallet` resource to [`PermissionService`](../../app/Services/PermissionService.php:36):
`view`, `refund`, `adjust`, `manage`. Admin gets all of them automatically; a
migration (mirroring `2026_10_07_100000_add_sms_delete_permission.php`) registers
the keys and re-syncs the roles for already-migrated installs.

### New endpoints — `app/Http/Controllers/Api/WalletController.php`
- `GET /api/wallet/transactions` — the caller's own ledger; users with
  `wallet.view|wallet.manage` may pass `?userId=` (and omit it for all users).
- `POST /api/wallet/refund` — `wallet.refund|wallet.manage`. Body: `userId`,
  `amount`, optional `orderId`, `reason`. Credits the wallet, links/updates the
  order (`refund_amount`, `refunded_at`, `status=refunded`), writes an order log,
  and SMS-notifies the customer.
- `POST /api/wallet/adjust` — `wallet.adjust|wallet.manage`. Manual credit/debit
  with a reason (admin tool, in addition to the user-form `walletChange`).

### Formatter
- `Formatter::walletTransaction($row)` mapper (camelCase contract).
- `Formatter::order()` gains `refundAmount` / `refundedAt`.

---

## Frontend

### Bug fix (#5)
[`api.syncWithBackend()`](../../public/js/api.js:158) also re-reads `/api/auth/me`
into `state.currentUser` when logged in. Add `getWalletTransactions`,
`refundWallet`, `adjustWallet` to [`api.js`](../../public/js/api.js:342).

### Customer — own transactions (#7)
[`wallet.js`](../../public/js/screens/wallet.js:21): add a «تاریخچه تراکنش‌ها»
section loaded via `api.getWalletTransactions()`, showing type, signed amount,
balance after, and date.

### Admin — orders monitoring + wallet traceability (#6, #4)
New screen `public/js/screens/admin-orders.js` with two segmented views:
- **سفارش‌ها**: all customers' orders + filters (status, operator, search by
  order/customer/phone/service) + detail sheet showing the assigned operator,
  payment info, the full log (who performed each step), and a **عودت وجه**
  action (order-based refund).
- **تراکنش‌های کیف پول**: the full ledger across users, filterable, with a
  free-form **عودت / شارژ دستی** action.

Wiring:
- Register the script in [`app.blade.php`](../../resources/views/app.blade.php:71).
- [`app.js`](../../public/js/app.js:153): admin bottom nav → home, dashboard,
  **orders**, users, more (move «خدمات» into the «بیشتر» hub); add the
  `admin/orders` title and render dispatch.
- [`admin-manage.js`](../../public/js/screens/admin-manage.js:14): add a
  «مدیریت خدمات» row to the hub.

### Refunded status surfacing
- [`ui.js`](../../public/js/ui.js:7) `STATUS_MAP` + [`app.css`](../../public/css/app.css:252)
  `.pill.refunded`.
- [`landing.js`](../../public/js/screens/landing.js:182) customer order filter chip.
- [`operator.js`](../../public/js/screens/operator.js:8) exclude `refunded` from the queue.

---

## Tests & docs
- `tests/Feature/WalletLedgerTest.php`: ledger on top-up / purchase / manual
  change / refund; `balance_after` correctness; refund permission denied for a
  non-admin (403); `/api/auth/me` reflects the debited balance (bug regression).
- Update `docs/PROJECT_DOCUMENTATION.md` (API table, wallet/refund, admin panel).
- Run `php artisan migrate` + the test suite.
