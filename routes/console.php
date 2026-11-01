<?php

use Illuminate\Support\Facades\Schedule;

// Claims are taken with a conditional UPDATE, so an overlapping run cannot
// double-queue a profile; withoutOverlapping just avoids wasted work.
// The lock expires after 5 minutes (default: 24 h) in case a run is killed.
Schedule::command('profiles:enqueue-due')->everyMinute()->withoutOverlapping(5);

// Horizon's metrics graphs (throughput, wait time) need snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
