<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Redirect root to Filament admin
Route::get('/', fn () => redirect('/admin'));

// Error page preview — remove before deploying to production
Route::get('/dev/error-preview/{status?}', function (int $status = 500) {
    return response()->view('errors.error', [
        'status' => $status,
        'ref'    => 'ABCD1234',
    ], $status);
});

// Laravel's auth middleware redirects unauthenticated users to route('login') —
// point that at Filament's login page.
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

// Public branded report routes (protected by signed URL or token)
Route::prefix('reports')->group(function () {

    // View HTML report in browser
    Route::get('/run/{testRun}/html', [ReportController::class, 'html'])
        ->name('reports.html')
        ->middleware('auth');

    // Shareable signed URL (no auth required — for client delivery)
    Route::get('/share/{testRun}/{token}', [ReportController::class, 'share'])
        ->name('reports.share');

    // Proxy route for report assets (screenshots, videos) — auth or valid share token
    Route::get('/run/{testRun}/asset/{path}', [ReportController::class, 'asset'])
        ->name('reports.asset')
        ->where('path', '.+');

    // Shared asset route — token and expiry are path segments, not query params.
    // Query-string tokens get stripped by Cloudflare WAF; path segments are not affected.
    Route::get('/share/{testRun}/asset/{token}/{expiry}/{path}', [ReportController::class, 'sharedAsset'])
        ->name('reports.sharedAsset')
        ->where('path', '.+');

});
