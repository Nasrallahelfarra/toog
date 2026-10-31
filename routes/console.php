<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Each module registers its scheduled jobs in routes/schedule/*.php
// (data sync, competitor scans, trend and balance alerts).
foreach (glob(__DIR__.'/schedule/*.php') as $schedule) {
    require $schedule;
}
