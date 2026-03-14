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

});
