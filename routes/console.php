<?php

use Illuminate\Support\Facades\Schedule;

// Claims are taken with a conditional UPDATE, so an overlapping run cannot
// double-queue a profile; withoutOverlapping just avoids wasted work.
Schedule::command('profiles:enqueue-due')->everyMinute()->withoutOverlapping();

// Horizon's metrics graphs (throughput, wait time) need snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
