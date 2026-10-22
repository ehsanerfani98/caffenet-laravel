<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * URL slug generator for catalog services (#/service/{slug}).
 *
 * Titles are Persian, so unlike Str::slug (which strips non-ASCII and would
 * collapse a Persian-only title to an empty string) this keeps Unicode
 * letters and digits from any script:
 *   «چاپ رسانه A4» → «چاپ-رسانه-a4»
 * Uniqueness is enforced against the services table with numeric suffixes
 * (-2, -3, …) so two same-titled services never collide.
 *
 * Consumers: the services migration (backfill), ServiceSlugSeeder
 * (idempotent re-runs) and CatalogController::saveService (new/legacy rows).
 * Slugs are assigned ONCE and kept stable afterwards — regenerating on title
 * edits would break already-shared links.
 */
class SlugService
{
    /** Max slug length (characters) — keeps URLs sane for long titles. */
    private const MAX_LEN = 120;

    /**
     * Raw slug from a title: Persian-safe, dash-separated, lowercased.
     * Arabic ي/ك are normalised to Persian ی/ک first so both spellings of
     * the same word produce the same slug.
     */
    public static function make(string $title): string
    {
        $s = strtr(trim($title), [
            "\u{064A}" => "\u{06CC}", // Arabic YEH → Persian YEH
            "\u{0643}" => "\u{06A9}", // Arabic KAF → Persian KEHEH
        ]);

        // Keep letters/digits (any script) plus separators; drop punctuation,
        // emoji, ZWNJ leftovers and everything else.
        $s = (string) preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $s);
        $s = (string) preg_replace('/\s+/u', '-', $s);
        $s = (string) preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        $s = mb_strtolower($s, 'UTF-8');

        if (mb_strlen($s, 'UTF-8') > self::MAX_LEN) {
            $s = rtrim(mb_substr($s, 0, self::MAX_LEN, 'UTF-8'), '-');
        }

        // A title made entirely of stripped characters still needs a slug.
        return $s !== '' ? $s : 'service';
    }

    /**
     * A slug unique among services, optionally ignoring one row id (used by
     * saveService so a row keeps its own slug). Suffixes -2, -3, … are
     * appended until a free candidate is found.
     */
    public static function uniqueFor(string $title, ?string $ignoreId = null): string
    {
        $base = self::make($title);

        $taken = function (string $candidate) use ($ignoreId): bool {
            $q = DB::table('services')->where('slug', $candidate);
            if ($ignoreId !== null && $ignoreId !== '') {
                $q->where('id', '!=', $ignoreId);
            }

            return $q->exists();
        };

        if (! $taken($base)) {
            return $base;
        }

        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base.'-'.$i;
            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        // Practically unreachable — degrade gracefully with a random suffix.
        return $base.'-'.substr(md5(uniqid('', true)), 0, 6);
    }
}
