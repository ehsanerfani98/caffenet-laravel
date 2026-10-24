<?php

/** Shared helpers for the cron/queue test scripts. */

use Illuminate\Support\Facades\DB;

function dump_queue(bool $compact = false): void
{
    $entries = DB::table('queue_entries')->orderBy('position')->get();
    if ($compact) {
        $parts = [];
        foreach ($entries as $e) {
            $o = DB::table('orders')->where('id', $e->order_id)->first();
            $parts[] = "{$e->position}:{$o->order_number}(".$o->status.")";
        }
        echo '    queue → ['.implode(' ', $parts)."] waiting=".count($entries)."\n";
        return;
    }
    if ($entries->isEmpty()) {
        echo "  (queue empty)\n";
        return;
    }
    foreach ($entries as $e) {
        $o = DB::table('orders')->where('id', $e->order_id)->first();
        echo sprintf("  pos=%d %s [%s] status=%s dur=%s wait=%s requeues=%s deadline=%s\n",
            $e->position, $o->order_number, substr((string) $o->customer_name, 0, 16), $o->status,
            $o->estimated_duration_minutes, $o->estimated_wait_minutes,
            $e->requeue_count, $e->no_response_deadline ? 'armed' : '-');
    }
}

function sms_rows(string $type): array
{
    return DB::table('sms_logs')->where('type', $type)->orderBy('timestamp')->get()->all();
}
