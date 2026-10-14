<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Queue + Capacity Management schema.
 *
 * Adds the queue/capacity tables on top of the existing payment-centric
 * orders system. An order enters the queue when it transitions to
 * `submitted` (after verified payment); queue position is tracked in
 * queue_entries, and operators expose their per-hour capacity through
 * operator_profiles + capacity_slots.
 *
 * Conventions match the rest of the codebase:
 *  - String UUID primary keys (prefixed: otp-, opr-, slot-, qe-, nl-, osh-, qlck-)
 *  - ISO 8601 string timestamps (created_at/updated_at as string(32))
 *  - SQLite pragmas (WAL, foreign_keys, busy_timeout) already applied by the
 *    baseline migration; we only add indexes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────
        // 1. order_types — Quick / Normal / Long
        // ────────────────────────────────────────────────────────────────
        Schema::create('order_types', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('key')->unique();              // quick | normal | long
            $table->string('title');                       // فارسی
            $table->unsignedInteger('base_duration_minutes')->default(20);
            $table->unsignedTinyInteger('priority_level')->default(5); // 1..10 (higher = more priority)
            $table->boolean('allow_inline')->default(true); // quick jobs may skip slot reservation
            $table->text('description')->nullable();
        });

        // ────────────────────────────────────────────────────────────────
        // 2. operator_profiles — capacity per active operator
        // ────────────────────────────────────────────────────────────────
        Schema::create('operator_profiles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id')->unique();           // → users.id (role = operator|admin)
            $table->string('display_name');
            $table->unsignedInteger('capacity_per_hour')->default(2); // avg orders/hr
            $table->boolean('is_active')->default(true);
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->index('is_active', 'idx_oprofile_active');
        });

        // ────────────────────────────────────────────────────────────────
        // 3. capacity_slots — reservable time windows per operator
        // ────────────────────────────────────────────────────────────────
        Schema::create('capacity_slots', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_id');                 // → operator_profiles.id
            $table->string('start_at', 32);                // ISO 8601
            $table->string('end_at', 32);                  // ISO 8601
            $table->string('status')->default('open');     // open | reserved | completed | cancelled
            $table->string('reserved_by_order_id')->nullable();
            $table->string('created_at', 32);
            $table->string('updated_at', 32);
            $table->index('operator_id', 'idx_slot_operator');
            $table->index('start_at', 'idx_slot_start');
            $table->index('status', 'idx_slot_status');
        });

        // ────────────────────────────────────────────────────────────────
        // 4. queue_entries — position tracking for submitted orders
        // ────────────────────────────────────────────────────────────────
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id')->unique();          // → orders.id
            $table->unsignedInteger('position');
            $table->string('entered_at', 32);              // ISO 8601 — when joined queue
            $table->string('started_at', 32)->nullable();  // when work started
            $table->string('finished_at', 32)->nullable(); // when work finished
            $table->string('no_response_deadline', 32)->nullable(); // for auto-requeue
            $table->unsignedInteger('requeue_count')->default(0);
            $table->index('position', 'idx_qe_position');
        });

        // ────────────────────────────────────────────────────────────────
        // 5. notification_logs — single source of truth for sent pings
        //    (no third-party SMS/Telegram; this is the audit trail)
        // ────────────────────────────────────────────────────────────────
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('user_id')->nullable();
            $table->string('type');                        // order_started | order_ready | reminder | requeued | ...
            $table->string('channel')->default('in_app');  // in_app | browser | sms | telegram
            $table->text('payload')->nullable();           // JSON snapshot
            $table->string('status')->default('pending');  // pending | sent | failed
            $table->text('error')->nullable();
            $table->string('sent_at', 32)->nullable();
            $table->string('created_at', 32);
            $table->index('order_id', 'idx_nl_order');
            $table->index('user_id', 'idx_nl_user');
        });

        // ────────────────────────────────────────────────────────────────
        // 6. order_status_histories — audit trail of status transitions
        // ────────────────────────────────────────────────────────────────
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('changed_by')->nullable();      // user id or 'system'
            $table->text('note')->nullable();
            $table->string('changed_at', 32);
            $table->index('order_id', 'idx_osh_order');
            $table->index('changed_at', 'idx_osh_changed');
        });

        // ────────────────────────────────────────────────────────────────
        // 7. queue_locks — software-level advisory locks (no Redis)
        // ────────────────────────────────────────────────────────────────
        Schema::create('queue_locks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('resource_key')->unique();      // e.g. queue:position
            $table->string('owner_token', 64);             // unique per lock holder
            $table->unsignedInteger('ttl_seconds')->default(10);
            $table->string('locked_at', 32);
            $table->string('expires_at', 32);
            $table->index('expires_at', 'idx_qlck_expires');
        });

        // ────────────────────────────────────────────────────────────────
        // 8. Extend orders table with queue/capacity fields
        // ────────────────────────────────────────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            $table->string('queue_type')->default('normal')->after('urgency');      // quick | normal | long
            $table->unsignedInteger('priority_level')->default(5)->after('queue_type');
            $table->unsignedInteger('estimated_wait_minutes')->nullable()->after('priority_level');
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('estimated_wait_minutes');
            $table->string('estimated_start_at', 32)->nullable()->after('estimated_duration_minutes');
            $table->string('estimated_end_at', 32)->nullable()->after('estimated_start_at');
            $table->string('track_token', 64)->nullable()->after('estimated_end_at');
            $table->unsignedInteger('queue_position')->nullable()->after('track_token');
            $table->string('no_response_deadline', 32)->nullable()->after('queue_position');
            $table->string('reserved_slot_id')->nullable()->after('no_response_deadline');
            $table->index('queue_type', 'idx_orders_qtype');
            $table->index('track_token', 'idx_orders_track');
        });

        // ────────────────────────────────────────────────────────────────
        // 9. Laravel's jobs/failed_jobs/cache/sessions tables
        //    (Required for QUEUE_CONNECTION=database, CACHE_STORE=database,
        //     SESSION_DRIVER=database on shared hosting without Redis.)
        // ────────────────────────────────────────────────────────────────
        $this->ensureJobsTable();
        $this->ensureFailedJobsTable();
        $this->ensureCacheTable();
        $this->ensureSessionsTable();
    }

    private function ensureJobsTable(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at'], 'idx_jobs_queue_reserved');
        });
    }

    private function ensureFailedJobsTable(): void
    {
        if (Schema::hasTable('failed_jobs')) {
            return;
        }
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    private function ensureCacheTable(): void
    {
        if (Schema::hasTable('cache')) {
            return;
        }
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    private function ensureSessionsTable(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_qtype');
            $table->dropIndex('idx_orders_track');
            $table->dropColumn([
                'queue_type', 'priority_level', 'estimated_wait_minutes',
                'estimated_duration_minutes', 'estimated_start_at', 'estimated_end_at',
                'track_token', 'queue_position', 'no_response_deadline', 'reserved_slot_id',
            ]);
        });

        foreach (['queue_locks', 'order_status_histories', 'notification_logs', 'queue_entries', 'capacity_slots', 'operator_profiles', 'order_types'] as $t) {
            Schema::dropIfExists($t);
        }
        // Note: jobs/failed_jobs/cache/sessions intentionally left in place
        // (Laravel's own queue/cache/session migrations create/drop them).
    }
};
