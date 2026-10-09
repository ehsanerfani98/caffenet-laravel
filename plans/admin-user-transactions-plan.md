# Admin "User Transactions" — Implementation Plan

## Goal

1. Fix the bug where the admin "Orders & Transactions" page shows **no transactions found** while the customer's own wallet ledger works.
2. Rename the tab `تراکنش های کیف پول` → `تراکنش های کاربران`.
3. When that tab is opened, show a **list of users**. Selecting a user navigates to a **dedicated page** with that user's full transaction history and filters.
4. Add the relevant permissions to the catalog **and the database**, and enforce them.

## Confirmed decisions

- New permission `wallet.users` = "view the user-list tab".
- Reuse existing `wallet.view` for viewing any single user's ledger.
- Both keys are added to admin (all keys) and **operator** role defaults.
- Detail page lives at a real SPA route: `admin/user-transactions/{userId}`.
- Filters on the detail page: transaction **type**, **direction** (credit/debit), **Jalali date range**, free-text **search** (reference/note/typeTitle).

---

## Root cause of the empty list

`WalletController::transactions()` decides scope with
`$user->hasAnyPermission(['wallet.view', 'wallet.manage'])`.
When the admin's spatie role is missing those keys (DB migrated before
`2026_10_09_000003_add_wallet_permissions.php`), the query falls back to
`where('user_id', $adminId)` → the admin's own (empty) wallet → `[]`.

`Gate::before` admin bypass in `AppServiceProvider` does NOT apply to
`hasAnyPermission()` calls.

---

## 1. Server permission catalog — `app/Services/PermissionService.php`

- In `RESOURCES['wallet']['actions']` add (keep `view` first, `manage` last):
  `['users', 'مشاهده کاربران تراکنش', 'مشاهده فهرست کاربران دارای تراکنش کیف پول']`.
- In `roleDefaults()` operator branch add `'wallet.view', 'wallet.users'`:
  `['dashboard.view', 'orders.manage', 'sms.view', 'wallet.view', 'wallet.users']`.
- Admin automatically receives `wallet.users` via `allKeys()`.

## 2. Client permission mirror — `public/js/permissions.js`

- Add the missing resource:
  `wallet: { title: 'کیف پول و تراکنش ها', actions: ['view', 'refund', 'adjust', 'manage', 'users'] }`.
- Update `roleDefaults('operator')` to
  `['dashboard.view', 'orders.manage', 'sms.view', 'wallet.view', 'wallet.users']`.
- This makes `ALL` include `wallet.*` so the user-editor matrix renders wallet rows
  and `PERM.resolve` keeps wallet keys for non-admin overrides.

## 3. New migration — `database/migrations/2026_10_09_000005_add_wallet_users_permission.php`

Mirror `2026_10_09_000003_add_wallet_permissions.php`:
- `forgetCachedPermissions()`
- `Permission::findOrCreate()` for every `PermissionService::allKeys()`.
- `admin` role `syncPermissions(allKeys())`.
- `operator` role `syncPermissions(expand(roleDefaults('operator')))`.
- `down()`: delete `wallet.users` only.

`RoleSeeder` needs no change (it already syncs `allKeys()` and expanded operator defaults).

## 4. `app/Http/Controllers/Api/WalletController.php`

### `transactions()` (fix + filters)
- Scope:
  ```php
  $canViewAll = $user->role === 'admin'
      || $user->hasAnyPermission(['wallet.view', 'wallet.manage']);
  ```
- Filters (only meaningful when `$canViewAll`; customers are always pinned to `user_id = $user->id`):
  - `userId` (exact), `type` (in TYPE_TITLES keys), `direction` (`credit|debit`),
    `from`/`to` (ISO or Jalali-free ISO; compare on `created_at`),
    `search` (LIKE on `reference`, `note`, and matched via `user_id`).
  - `limit` stays `max(1, min(500, ...))`.
- Return the same `Formatter::walletTransaction` shape.

### `users()` (new)
- Guarded by route middleware `permission:wallet.users|wallet.manage`.
- Query users that have ≥1 wallet transaction, with aggregates
  (`txn_count`, `total_credit`, `total_debit`, `last_txn_at`) + `wallet_balance`.
- Optional `?search=` on name/phone. Order by `last_txn_at` desc.
- Response rows: `id, name, phone, role, walletBalance, txnCount, totalCredit, totalDebit, lastTransactionAt`.

## 5. Routes — `routes/api.php`

```php
Route::middleware(['auth.session', 'permission:wallet.users|wallet.manage'])
    ->get('/wallet/users', [WalletController::class, 'users']);
// existing:
Route::middleware('auth.session')->get('/wallet/transactions', [WalletController::class, 'transactions']);
```

## 6. Client API — `public/js/api.js`

- `getWalletTransactions(params)` accepts an object
  `{userId, type, direction, from, to, search, limit}` and builds the query string
  (keep accepting a plain string userId for backward compatibility).
- New `getWalletUsers(search)` → `GET /api/wallet/users?search=…`.
- Update existing callers (`wallet.js`, `admin-orders.js`) to the new signature
  (they pass no args, so callers stay unchanged if the method still tolerates omission).

## 7. Admin tab + user list — `public/js/screens/admin-orders.js`

- `Screens.adminOrders`: replace the second tab with
  `<button class="seg" data-view="users">تراکنش های کاربران</button>`
  shown only when `api.hasPermission('wallet.users') || api.hasPermission('wallet.manage')`.
- `switchView()` maps `'users'` → `renderUsers()`.
- `renderUsers()`:
  - Keep the existing "عودت / شارژ / کسر دستی" button (`canAdjust || canRefund`).
  - Search box (name/phone) → debounced `api.getWalletUsers`.
  - Rows: icon, name, phone, balance, `تعداد تراکنش` + `آخرین: <Jalali>`.
  - Row click → `App.navigate('admin/user-transactions/' + u.id)`.
- `refresh` hook (`$host.data('caffenetRefresh')`) handles the `users` view.

## 8. New detail screen — `public/js/screens/admin-orders.js`

- `Screens.adminUserTransactions(ctx)` returns a host with a header + filters + list.
- `Screens.bind_admin_user_transactions($host, ctx)`:
  - `ctx.userId` from the route (fallback: `ctx.state`/URL parse).
  - Fetch the user from `api.getUsers()` (or a dedicated summary once) to render
    name/phone/balance header.
  - Filters UI:
    - type chips (all + the 5 `TYPE_TITLES`),
    - direction chips (all/credit/debit),
    - Jalali range `from`/`to` using `data-jdp` inputs (as in `admin-manage.js` coupon form),
    - search input (reference/note).
  - Each filter change → `api.getWalletTransactions({ userId, ... })` → repaint.
  - Reuse the shared `walletTxnRow()` renderer.
  - Back button → `App.navigate('admin/orders')` (and re-select the users tab).
  - Keep refund/adjust actions available when the matching permission is held.

## 9. Router — `public/js/app.js`

- `isAdminScreen()` already matches `admin/*`.
- In `render()` add a branch before the exact-match chain:
  ```js
  else if (scr.indexOf('admin/user-transactions') === 0) {
    ctx.userId = scr.split('/')[2] || '';
    html = window.Screens.adminUserTransactions(ctx);
  }
  ```
  and for bindings, when the screen starts with `admin/user-transactions`, call
  `window.Screens.bind_admin_user_transactions($host, ctx)` instead of the
  `bind_<screenKey>` lookup.
- `screenTitles()`: return `{ t1: 'تراکنش های کاربر', t2: 'تاریخچه کامل و فیلترها' }`
  for the prefix.
- `navItems()` active state: when on `admin/user-transactions/*`, highlight
  `admin/orders`.

## 10. Verification

- `php artisan migrate` (runs the new permission migration) and
  `php artisan db:seed --class=RoleSeeder`.
- Confirm the admin tab now lists users, drill-down filters work, and the customer
  wallet page is unaffected (it still calls `getWalletTransactions()` with no args).
- Verify a customer hitting `/api/wallet/users` gets 403 and `/api/wallet/transactions`
  still returns only their own rows.
