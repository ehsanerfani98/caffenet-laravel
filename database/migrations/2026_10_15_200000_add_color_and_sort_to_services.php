<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds icon_color + sort_order to the services table — mirrors the same
 * pattern already used by categories. The admin can now pick a custom
 * color per service (tint applied to the Font Awesome icon), and the
 * home page + catalog render the colored icon instead of the default
 * brand color.
 *
 * Both columns are nullable for backward compatibility with existing
 * rows: NULL color → frontend falls back to the default sky color.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('icon_color', 32)->nullable()->after('icon');
            $table->integer('sort_order')->default(0)->after('icon_color');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['icon_color', 'sort_order']);
        });
    }
};
