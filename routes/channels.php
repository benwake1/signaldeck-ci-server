<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

// Dashboard channel — all authenticated users
Broadcast::channel('dashboard', function ($user) {
    return true;
});

// Per-run channel — authenticated users only
Broadcast::channel('test-run.{runId}', function ($user, int $runId) {
    return true; // Add finer-grained auth here if needed
});

// Per-project channel
Broadcast::channel('project.{projectId}', function ($user, int $projectId) {
    return true;
});
