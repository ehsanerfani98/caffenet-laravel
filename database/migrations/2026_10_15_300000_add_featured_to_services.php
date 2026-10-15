<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds is_featured + featured_sort to services so the admin can mark
 * specific services as "popular" — those appear in the "خدمات پرطرفدار"
 * section on the home page.
 *
 *  - is_featured     : boolean, default false
 *  - featured_sort   : int, default 0 (smaller = appears earlier in the
 *                      popular section; ties broken by created_at)
 *
 * Without this, the home page just showed the first 4 active services
 * by created_at — the admin had no way to control which services were
 * highlighted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_active');
            $table->integer('featured_sort')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'featured_sort']);
        });
    }
};
