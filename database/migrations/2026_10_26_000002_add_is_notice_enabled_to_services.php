<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the is_notice_enabled master switch to services:
 *
 *  - false (default) → the notice is dormant: no popup anywhere and the
 *    service follows the completely normal order flow, even when
 *    notice_type / notice_text are configured.
 *  - true → the notice activates: 'lock' services show the red popup and
 *    refuse orders, 'normal' services show the orange read-me popup.
 *
 * Guarded with hasColumn() — some production databases receive columns
 * by hand before the migration lands (SQLite has no ADD COLUMN IF NOT
 * EXISTS, and re-adding an existing column would fail the whole batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'is_notice_enabled')) {
            Schema::table('services', function (Blueprint $table) {
                $table->boolean('is_notice_enabled')->default(false)->after('notice_text');
            });
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('is_notice_enabled');
        });
    }
};
