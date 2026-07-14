<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly: flag leads with no activity in 30 days as dormant.
Schedule::command('leads:flag-dormant')->dailyAt('02:00');

// Hourly work-status reminders: runs often, only nudges checked-in employees who
// haven't logged the current hour (self-throttles per slot). Needs the scheduler running.
Schedule::command('work:remind')->everyFifteenMinutes();
