<?php

namespace Database\Seeders;

use App\Services\SlugService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fills the URL slug of every service whose slug is still empty.
 *
 * Idempotent — safe to run any number of times: rows that already carry a
 * slug are never touched, so existing #/service/{slug} links stay stable.
 * Run it on shared hosting through the Artisan Runner:
 *   /api/_artisan/run/db:seed:slugs?token=…
 * (the dedicated alias exists because the plain db:seed alias would run ALL
 * seeders).
 */
class ServiceSlugSeeder extends Seeder
{
    public function run(): void
    {
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

        if ($this->command) {
            $this->command->info('ServiceSlugSeeder: '.$rows->count().' خدمت اسلاگ گرفت.');
        }
    }
}
