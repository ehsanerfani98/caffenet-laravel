<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the service-fee split + service notice columns to services:
 *
 *  - benefit      → «کارمزد کافی‌نت» (the shop's share of base_price).
 *                   «کارمزد سامانه» is derived on the fly: base_price − benefit.
 *  - notice_type  → NULL = normal flow | 'lock' = service closed for orders
 *                   until further notice | 'normal' = read-me-first announcement.
 *  - notice_text  → the Persian notice body shown in the red/orange popup.
 *
 * Every column is added behind a hasColumn() guard: some production
 * databases already received these columns manually (added by hand before
 * the migration existed), and SQLite has no "ADD COLUMN IF NOT EXISTS".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'benefit')) {
            Schema::table('services', function (Blueprint $table) {
                $table->integer('benefit')->nullable()->after('base_price');
            });
        }

        if (! Schema::hasColumn('services', 'notice_type')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('notice_type', 16)->nullable()->after('base_price');
            });
        }

        if (! Schema::hasColumn('services', 'notice_text')) {
            Schema::table('services', function (Blueprint $table) {
                $table->text('notice_text')->nullable()->after('notice_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['benefit', 'notice_type', 'notice_text']);
        });
    }
};
