<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time heal for production data behind the «نفر چهارم در صف با ۳ نفر
 * جلوی شما» phantom-position report.
 *
 * Root cause: UserPurgeService deleted a purged user's orders but not their
 * queue_entries rows (the table has no FK cascade). The orphaned ghosts kept
 * feeding enqueue's max(position)+1 and the tracker's «ahead = position-1»
 * hero, so a brand-new customer's first order landed at position 4 with
 * «۳ نفر جلوی شما» on an otherwise empty queue.
 *
 *  1. Delete queue entries whose order row no longer exists.
 *  2. Repack the surviving entries to contiguous 1..N (relative order kept)
 *     and sync orders.queue_position so live trackers show the healed value.
 *
 * Going forward the queue self-heals: QueueManagerService::enqueue() runs the
 * same compaction under the queue:position lock before reading max(position),
 * and UserPurgeService now deletes the entries and calls compact() itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM queue_entries WHERE order_id NOT IN (SELECT id FROM orders)');

        $pos = 0;
        foreach (DB::table('queue_entries')->orderBy('position')->orderBy('entered_at')->get() as $entry) {
            $pos++;
            if ((int) $entry->position === $pos) {
                continue;
            }

            DB::table('queue_entries')->where('id', $entry->id)->update(['position' => $pos]);
            DB::table('orders')->where('id', $entry->order_id)->update(['queue_position' => $pos]);
        }
    }

    public function down(): void
    {
        // Orphaned rows cannot be restored — nothing to roll back.
    }
};
