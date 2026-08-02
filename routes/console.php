<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Move timed-out dispatch offers on to the next technician (~every minute via the scheduler container).
Schedule::command('dispatch:expire-offers')->everyMinute();
