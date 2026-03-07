<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 6.2 — Reminders scheduler
Schedule::command('reminders:generate-due-soon')->hourly();

// Automated SQL backup: check every minute so scheduled runs trigger on time
Schedule::command('backup:run')->everyMinute();
