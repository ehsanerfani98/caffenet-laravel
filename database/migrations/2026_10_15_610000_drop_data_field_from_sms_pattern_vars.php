<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-adds the data_field column to sms_pattern_vars.
 *
 * Each variable mapping now has TWO fields:
 *   var_name   = the custom name the admin used in ippanel (e.g. "order_id")
 *   data_field = the system field that fills it (e.g. "order_number")
 *
 * This lets the admin use ANY variable name in ippanel and map it to
 * ANY system data field. Neither is hardcoded — both are free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sms_pattern_vars', 'data_field')) {
            Schema::table('sms_pattern_vars', function (Blueprint $table) {
                $table->string('data_field')->default('')->after('var_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sms_pattern_vars', 'data_field')) {
            Schema::table('sms_pattern_vars', function (Blueprint $table) {
                $table->dropColumn('data_field');
            });
        }
    }
};
