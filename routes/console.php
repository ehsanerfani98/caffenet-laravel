<?php

use Illuminate\Support\Facades\Schedule;

// Cleanup job — the port of startFileRetentionJob: immediately after boot in
// the original, here every six hours (plus manually via caffenet:cleanup).
Schedule::command('caffenet:cleanup')->everySixHours();
