<?php

use App\Services\SlugService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * URL slug for services — powers the dedicated order page #/service/{slug}.
 *
 * Fully self-contained: the column is added, EXISTING rows are backfilled
 * with unique slugs (same generator the seeder and the save hook use), and
 * only then the unique index lands — a plain «php artisan migrate» is enough.
 * ServiceSlugSeeder re-runs the identical backfill any time later; it is
 * idempotent and only touches rows whose slug is still empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Nullable: rows are backfilled right below, before the unique
            // index (SQLite/MySQL both allow multiple NULLs in a unique index,
            // but we prefer every row to carry a real slug).
            $table->string('slug')->nullable()->after('title');
        });

        $rows = DB::table('services')
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->get(['id', 'title']);

        foreach ($rows as $row) {
            DB::table('services')->where('id', $row->id)->update([
                'slug' => SlugService::uniqueFor((string) $row->title),
            ]);
        }

        Schema::table('services', function (Blueprint $table) {
            $table->unique('slug', 'idx_services_slug');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('idx_services_slug');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
