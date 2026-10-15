<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each row = one variable mapping for one SMS pattern.
 *
 * The admin creates a pattern in ippanel with custom variable names
 * (e.g. %customer%, %order%), gets a pattern code, then in the admin
 * panel enters:
 *   - pattern_key  = "order_started" (the event type)
 *   - var_name     = "customer" (the name they used in ippanel)
 *   - data_field   = "customer_name" (the system field to fill it with)
 *
 * The system reads all rows for a pattern_key, builds the params object,
 * and sends it to the Edge API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_pattern_vars', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('pattern_key');   // order_started | otp | broadcast | ...
            $table->string('var_name');       // the variable name the admin used in ippanel
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('created_at', 32);
            $table->index('pattern_key', 'idx_smspv_pattern');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_pattern_vars');
    }
};
