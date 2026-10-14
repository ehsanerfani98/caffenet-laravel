<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds icon_color and sort_order columns to categories, so the home page
 * can render a colorful icon grid (like the mobile_services_home reference)
 * where each category exposes a custom color picked from the admin panel.
 *
 * Both columns are nullable for backward compatibility with existing rows:
 *  - icon_color = NULL → frontend falls back to a default brand color.
 *  - sort_order = NULL → treated as 0 (existing categories stay in
 *    created_at order; new ones can be explicitly ordered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon_color', 32)->nullable()->after('icon_name');
            $table->integer('sort_order')->default(0)->after('icon_color');
            $table->string('created_at', 32)->nullable()->after('description')->change();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['icon_color', 'sort_order']);
        });
    }
};
