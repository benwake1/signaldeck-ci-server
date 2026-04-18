<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FlakyTestController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SsoAuthController;
use App\Http\Controllers\Api\V1\TestGeneratorController;
use App\Http\Controllers\Api\V1\TestHistoryController;
use App\Http\Controllers\Api\V1\TestRunController;
use App\Http\Controllers\Api\V1\TestRunStreamController;
use App\Http\Controllers\Api\V1\TestSuiteController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Middleware\EnsureApiTokenAbility;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api/v1 (configured in bootstrap/app.php).
| Authentication uses Laravel Sanctum Bearer tokens.
|
*/

// ── Public (unauthenticated) ────────────────────────────────────────────

Route::get('health', HealthController::class);

// ── Webhooks (public, signature-authenticated) ────────────────────────
Route::post('webhook/trigger', [WebhookController::class, 'trigger'])
    ->name('api.v1.webhook.trigger');

// ── Auth ────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::get('sso/providers', [SsoAuthController::class, 'providers']);
    Route::get('sso/{provider}/redirect', [SsoAuthController::class, 'redirect']);
    Route::get('sso/{provider}/callback', [SsoAuthController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:10,1');
        Route::get('user', [AuthController::class, 'user']);
    });
});

// ── Authenticated (read access) ─────────────────────────────────────────

Route::middleware(['auth:sanctum', EnsureApiTokenAbility::class.':desktop:read'])->group(function () {

    // Dashboard
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);

    // Clients (read)
    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/{client}', [ClientController::class, 'show']);

    // Projects (read)
    Route::get('projects', [ProjectController::class, 'index']);
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::get('projects/{project}/suites', [TestSuiteController::class, 'index']);

    // Test Suites (read)
    Route::get('suites/{suite}', [TestSuiteController::class, 'show']);

    // Test Runs (read)
    Route::get('test-runs', [TestRunController::class, 'index']);
    Route::get('test-runs/compare', [TestRunController::class, 'compare']);
    Route::get('test-runs/{testRun}', [TestRunController::class, 'show']);
    Route::get('test-runs/{testRun}/results', [TestRunController::class, 'results']);
    Route::get('test-runs/{testRun}/logs', [TestRunController::class, 'logs']);
    Route::get('test-runs/{testRun}/report', [TestRunController::class, 'report']);

    // Analytics
    Route::get('flaky-tests', [FlakyTestController::class, 'index']);
    Route::get('test-history', [TestHistoryController::class, 'index']);
});

// ── Authenticated (write access) ────────────────────────────────────────

Route::middleware(['auth:sanctum', EnsureApiTokenAbility::class.':desktop:write'])->group(function () {

    // Trigger / cancel test runs
    Route::post('test-runs', [TestRunController::class, 'store']);
    Route::post('test-runs/{testRun}/cancel', [TestRunController::class, 'cancel']);

    // Test suite generator
    Route::post('generate-test-suite', [TestGeneratorController::class, 'generate']);
});

// ── Admin only ──────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum', EnsureApiTokenAbility::class.':desktop:admin'])->group(function () {

    // Clients (CRUD)
    Route::post('clients', [ClientController::class, 'store']);
    Route::put('clients/{client}', [ClientController::class, 'update']);
    Route::delete('clients/{client}', [ClientController::class, 'destroy']);

    // Projects (CRUD)
    Route::post('projects', [ProjectController::class, 'store']);
    Route::put('projects/{project}', [ProjectController::class, 'update']);
    Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    Route::post('projects/{project}/generate-key', [ProjectController::class, 'generateKey']);
    Route::post('projects/{project}/discover-projects', [ProjectController::class, 'discoverProjects']);
    Route::post('projects/{project}/rotate-webhook-secret', [ProjectController::class, 'rotateSecret']);

    // Test Suites (CRUD)
    Route::post('projects/{project}/suites', [TestSuiteController::class, 'store']);
    Route::put('suites/{suite}', [TestSuiteController::class, 'update']);
    Route::delete('suites/{suite}', [TestSuiteController::class, 'destroy']);

    // Test Runs (delete)
    Route::delete('test-runs/{testRun}', [TestRunController::class, 'destroy']);

    // Settings
    Route::get('settings', [SettingsController::class, 'index']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::get('settings/mail', [SettingsController::class, 'mail']);
    Route::put('settings/mail', [SettingsController::class, 'updateMail']);
    Route::post('settings/mail/test', [SettingsController::class, 'testMail'])
        ->middleware('throttle:3,1');
    Route::get('settings/sso', [SettingsController::class, 'sso']);
    Route::put('settings/sso', [SettingsController::class, 'updateSso']);
    Route::get('settings/slack', [SettingsController::class, 'slack']);
    Route::put('settings/slack', [SettingsController::class, 'updateSlack']);
    Route::post('settings/slack/test', [SettingsController::class, 'testSlack'])
        ->middleware('throttle:3,1');
    Route::post('settings/slack/test-breach', [SettingsController::class, 'testSlackBreach'])
        ->middleware('throttle:3,1');

    // Storage / S3
    Route::get('settings/storage', [SettingsController::class, 'storage']);
    Route::put('settings/storage', [SettingsController::class, 'updateStorage']);
    Route::delete('settings/storage', [SettingsController::class, 'disableStorage']);
    Route::post('settings/storage/migrate', [SettingsController::class, 'migrateStorage'])
        ->middleware('throttle:3,1');

    // Users
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::post('users', [UserController::class, 'store']);
    Route::put('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
});

// ── SSE Streams ──────────────────────────────────────────────────────────
// auth:sanctum handles both session cookies (web) and Bearer tokens (macOS).
// These routes must sit outside EnsureApiTokenAbility because that middleware
// checks currentAccessToken(), which is null for session-authenticated web users.

Route::middleware('auth:sanctum')->group(function () {
    Route::get('test-runs/{testRun}/stream', [TestRunStreamController::class, 'stream'])
        ->name('api.v1.test-runs.stream');

    Route::get('events/stream', [TestRunStreamController::class, 'globalStream'])
        ->name('api.v1.events.stream');
});
