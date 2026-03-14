<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('runs:cleanup')->dailyAt('02:00');
