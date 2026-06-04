<?php

use App\Support\ScheduleLogRotator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scheduler:rotate-log', function () {
    if (ScheduleLogRotator::rotateIfNeeded()) {
        $this->info('Rotated oversized storage/logs/cron.log (archived with .bak suffix).');
    } else {
        $this->info('cron.log is within size limits — no rotation needed.');
    }
})->purpose('Archive storage/logs/cron.log when it exceeds the configured size');
