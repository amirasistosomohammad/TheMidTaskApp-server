<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 6.2 — Reminders scheduler
// Generate \"due soon\" reminders for upcoming user tasks at regular intervals.
Schedule::command('reminders:generate-due-soon')->hourly();
