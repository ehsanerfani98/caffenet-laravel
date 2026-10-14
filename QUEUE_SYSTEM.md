# Smart Queue + Capacity Management

This module adds a smart queue + capacity management system on top of the existing payment-centric order lifecycle. It is designed for cPanel shared hosting without SSH, Redis, WebSocket, or Supervisor.

## Key Concepts

### Order Types (3 tiers)
- **فوری (quick)** — 5 min, priority 9, can skip slot reservation (e.g. print, simple form)
- **عادی (normal)** — 20 min, priority 5, standard queue with ETA
- **زمان‌بر (long)** — 60 min, priority 3, requires slot reservation

### Capacity Indicator (4 tiers)
| Tier | Color | Meaning | ETA |
|------|-------|---------|-----|
| `green` | 🟢 | Normal capacity | < 15 min |
| `yellow` | 🟡 | Queue filling up | 15-30 min |
| `orange` | 🟠 | Busy | 30-60 min |
| `red` | 🔴 | Today's capacity exhausted | no slot today |

### Two separate times shown to the customer
- **زمان انتظار (wait)** — how long until work STARTS
- **مدت انجام (duration)** — how long the work itself takes
- **مجموع = انتظار + انجام**

This avoids the misconception that "30 min" means 30 min of continuous work.

## Architecture

### Database Tables (added by migration `2026_10_15_000001`)
- `order_types` — quick/normal/long definitions
- `operator_profiles` — capacity_per_hour per staff user
- `capacity_slots` — reservable 30-min windows per operator
- `queue_entries` — position tracking per order
- `notification_logs` — in-app notification audit trail
- `order_status_histories` — append-only status transition log
- `queue_locks` — software advisory locks (no Redis)
- Plus columns added to `orders`: `queue_type`, `priority_level`, `estimated_wait_minutes`, `estimated_duration_minutes`, `estimated_start_at`, `estimated_end_at`, `track_token`, `queue_position`, `no_response_deadline`, `reserved_slot_id`

### Services (in `app/Services/`)
| Service | Purpose |
|---------|---------|
| `QueueLockService` | SQLite-friendly advisory locks with TTL |
| `QueueManagerService` | Enqueue / dequeue / start / finish / requeue |
| `WaitTimeEstimatorService` | Compute ETA from queue + parallelism |
| `StatusResolverService` | Map ETA to 🟢/🟡/🟠/🔴 tier |
| `SlotReservationService` | Generate + reserve capacity slots |
| `AutoRequeueService` | Sweep no-response orders every minute |
| `NotificationDispatcherService` | Write to notification_logs |
| `OrderLifecycleService` | Orchestrate transitions + history |
| `OrderTypeClassifierService` | Map service.estimated_time → quick/normal/long |

### Jobs (scheduled in `routes/console.php`)
- `CheckNoResponseOrders` — every minute, finds expired no-response deadlines
- `RefreshCapacitySnapshot` — every minute, regenerates slots + busts cache
- `SendStartReminder` — every minute, sends "5 min before start" reminders
- `CleanupExpiredLocks` — every 5 min, purges stale queue_locks

### Events / Listeners
- `OrderStarted` → `LogNotificationOnStart` → writes notification_logs row
- `OrderNoResponse` → `RequeueOnNoResponse` → calls AutoRequeueService

### API Endpoints (added to `routes/api.php`)
| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/api/capacity/current` | public | Global capacity tier (cached 30s) |
| GET | `/api/capacity/slots` | auth | List open reservable slots |
| GET | `/api/orders/track/{token}` | public | Track order by long token |
| GET | `/api/orders/{id}/updates` | auth (rate-limited 12/min) | Lightweight polling |
| POST | `/api/orders/{id}/reserve` | auth | Reserve a slot for long-type |
| POST | `/api/orders/{id}/notify-me` | auth | "Notify me on start" flag |
| DELETE | `/api/orders/{id}/cancel` | auth | Cancel + dequeue |
| PATCH | `/api/orders/{id}/start` | staff | Operator starts work |
| PATCH | `/api/orders/{id}/complete` | staff | Operator marks ready |
| GET | `/api/queue` | staff | Live queue snapshot (admin dashboard) |
| ANY | `/api/_artisan/run/{command}` | token | Artisan Runner (cPanel no-SSH) |

### Frontend (`public/js/queue.js`)
- `QueueUI.startCapacityIndicator(el)` — polls `/api/capacity/current` every 30s
- `QueueUI.startOrderTracking(orderId, cb)` — polls `/api/orders/{id}/updates` every 15s
- Visibility-aware (pauses when tab hidden)
- Offline-aware (stops when `navigator.onLine === false`)
- Exponential backoff on failure (1s → 2s → 4s → 8s → cap 30s)
- Browser Notification API integration for `order_started` / `order_ready` / `requeued`

## How the Queue Lifecycle Integrates

1. Customer creates order → `OrderController::store` → status = `pending_payment`, `queue_type` is set by `OrderTypeClassifierService`
2. Customer pays (wallet or Zarinpal) → `OrderLifecycleService::onSubmitted()` is called:
   - Computes ETA (wait + duration)
   - Generates `track_token` (32-char random)
   - Calls `QueueManagerService::enqueue()` → adds to queue with next position
3. Operator sees the order in `/api/queue` snapshot (polled every 5s on admin dashboard)
4. Operator clicks "Start" → `QueueOrderController::start` → `OrderLifecycleService::onStart()`:
   - Status: `submitted` → `processing`
   - `QueueEntry.started_at` is set
   - `OrderStarted` event → `LogNotificationOnStart` listener → notification_logs row
   - Customer's polling picks up the new notification → browser notification fires
5. Operator clicks "Complete" → `QueueOrderController::complete` → status: `processing` → `ready`, `QueueEntry.finished_at` set
6. Customer receives order, admin marks `delivered` → `OrderLifecycleService::onDelivered()` → `QueueManagerService::dequeue()` (frees position, repacks queue)

### No-response rule (15 min)
- When an order reaches position 1, the operator can set `no_response_deadline` (via admin UI; not yet wired up)
- Every minute, `CheckNoResponseOrders` job finds expired deadlines → dispatches `OrderNoResponse` event
- `RequeueOnNoResponse` listener calls `AutoRequeueService::requeueExpired()` → pushes to back of queue
- Customer sees a "نوبت شما منتقل شد" notification on their next poll

## cPanel Deployment

See **`CPANEL_DEPLOY.md`** for the full step-by-step guide.

### Cron Jobs (3 entries, all per-minute)
```bash
# 1. Laravel scheduler — drives all the queue jobs
* * * * * /usr/local/bin/php /home/USERNAME/laravel/artisan schedule:run >> /dev/null 2>&1

# 2. Queue worker — runs at most 50s, exits when empty
* * * * * /usr/local/bin/php /home/USERNAME/laravel/artisan queue:work --stop-when-empty --max-time=50 --tries=1 >> /dev/null 2>&1

# 3. Daily SQLite backup
0 3 * * * cp /home/USERNAME/laravel/database/database.sqlite /home/USERNAME/backups/db-$(date +\%F).sqlite
```

### Artisan Runner (no-SSH deploy)
Whitelisted commands runnable via browser:
```
https://yourdomain.com/api/_artisan/run/migrate?token=YOUR_TOKEN
https://yourdomain.com/api/_artisan/run/db:seed?token=YOUR_TOKEN
https://yourdomain.com/api/_artisan/run/queue:slots:generate?token=YOUR_TOKEN
https://yourdomain.com/api/_artisan/run/config:cache?token=YOUR_TOKEN
https://yourdomain.com/api/_artisan/run/cache:clear?token=YOUR_TOKEN
https://yourdomain.com/api/_artisan/run/queue:work?token=YOUR_TOKEN  # manual queue flush
```

Disable in production by setting `ARTISAN_RUNNER_TOKEN=disabled`.

## Out of Scope (per spec)
- ❌ Redis (Cache/Queue/Session)
- ❌ WebSocket / Laravel Echo / Pusher / Reverb
- ❌ Supervisor
- ❌ SSH / Composer CLI on host
- ❌ SMS / WhatsApp / Telegram
- ❌ Payment gateway
- ❌ Firebase / OneSignal
- ❌ Sentry

## Replaced By
- ✅ Database driver for queue/cache/session
- ✅ jQuery polling (15s user, 5s admin) with visibility + offline awareness
- ✅ Browser Notification API for local pings
- ✅ Cron + `queue:work --stop-when-empty --max-time=50`
- ✅ Artisan Runner (HTTP-triggered, token-guarded)
- ✅ `notification_logs` table + session flash + log file
