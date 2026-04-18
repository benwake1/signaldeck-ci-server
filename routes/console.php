<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('runs:cleanup')->dailyAt('02:00');

Schedule::call(function () {
    \App\Models\RunEvent::where('created_at', '<', now()->subHours(24))->delete();
})->daily()->name('prune-run-events');
