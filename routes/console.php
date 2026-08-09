<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Move timed-out dispatch offers on to the next technician (~every minute via the scheduler container).
Schedule::command('dispatch:expire-offers')->everyMinute();

// Expire unanswered quotes past their deadline (24h window, so hourly is plenty).
Schedule::command('quotes:expire')->hourly();

// Release escrow holds once a completed order's dispute window has closed.
Schedule::command('orders:release-holds')->hourly();

// Auto-complete orders whose closure review window elapsed with no client action
// (client neither used the code nor disputed) — frees the technician.
Schedule::command('closure:auto-complete')->everyMinute();

// Activate confirmed appointments whose time has arrived (scheduled order -> on-site).
Schedule::command('appointments:activate-due')->everyMinute();

// Remind clients/technicians of appointments starting within the lead window (UC-26).
Schedule::command('appointments:remind')->everyFiveMinutes();

Schedule::command('telescope:prune --hours=24')->daily();
