<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the data_field column from sms_pattern_vars.
 *
 * The previous migration (2026_10_15_600000) created the table with a
 * data_field column. We removed data_field from the code but the column
 * still exists on deployed databases with a NOT NULL constraint, causing
 * INSERT failures.
 *
 * This migration drops the column. On SQLite, we use the dropColumn
 * approach which works by recreating the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sms_pattern_vars', 'data_field')) {
            Schema::table('sms_pattern_vars', function (Blueprint $table) {
                $table->dropColumn('data_field');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sms_pattern_vars', 'data_field')) {
            Schema::table('sms_pattern_vars', function (Blueprint $table) {
                $table->string('data_field')->nullable()->after('var_name');
            });
        }
    }
};
