<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core Laravel infrastructure tables — created FIRST so that any migration
 * that touches the cache store (notably spatie/laravel-permission's
 * `cache()->forget()` inside its permission-tables migration) never runs
 * before these tables exist.
 *
 * Why this exists: create_queue_capacity_schema created jobs/failed_jobs/
 * cache/cache_locks/sessions lazily at the END of its up(), but the spatie
 * permission migration (2026_10_06_080000) flushes the permission cache
 * during `migrate` — on a brand-new (shared-hosting) database that order
 * explodes with "no such table: cache". Fresh deployments were impossible.
 *
 * Every create is guarded with Schema::hasTable so this migration stays
 * idempotent and no-ops on databases that already have the tables (all
 * existing installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensure('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at'], 'idx_jobs_queue_reserved');
        });

        $this->ensure('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        $this->ensure('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        $this->ensure('sessions', function (Blueprint $table) {
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
        // Intentionally a no-op: these tables belong to the framework's
        // session/cache/queue stores and other migrations (and the runtime)
        // may depend on them. Dropping infrastructure on rollback would be
        // destructive with no benefit.
    }

    private function ensure(string $table, \Closure $definition): void
    {
        if (Schema::hasTable($table)) {
            return;
        }
        Schema::create($table, $definition);
    }
};
