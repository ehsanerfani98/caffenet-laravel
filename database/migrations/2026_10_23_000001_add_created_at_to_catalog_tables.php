<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures categories.created_at and services.created_at exist on EVERY
 * install, including databases whose color/sort migrations already ran
 * before the created_at fix (e.g. production SQLite, where the catalog
 * order migrations were recorded without ever creating the column).
 *
 * CatalogController orders both tables by (sort_order, created_at) — a
 * missing column makes /api/categories and /api/services crash with
 * «column not found» once that code is deployed.
 *
 * Guarded with Schema::hasColumn so fresh installs (where the base
 * migration or the color/sort migrations already create the column)
 * are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'created_at')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('created_at', 32)->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('services', 'created_at')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('created_at', 32)->nullable()->after('pricing_formula');
            });
        }
    }

    public function down(): void
    {
        // Deliberately empty: dropping created_at would re-break the
        // catalog ordering on installs that need it.
    }
};
