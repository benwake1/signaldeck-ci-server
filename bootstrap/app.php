<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // In debug mode keep the full Laravel error page / JSON so local dev is unaffected.
        if (config('app.debug')) {
            return;
        }

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // Validation and authentication errors are intentional — keep their detail.
            if ($e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getStatusCode()
                : 500;

            // Only log unexpected server errors — not routine 404s / 403s etc.
            $ref = null;
            if ($status >= 500) {
                $ref = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8));
                \Illuminate\Support\Facades\Log::error("Application error [{$ref}]: " . $e->getMessage(), [
                    'exception' => $e,
                    'url'       => $request->fullUrl(),
                ]);
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                $message = match ($status) {
                    400 => 'Bad request.',
                    401 => 'Unauthenticated.',
                    403 => 'Forbidden.',
                    404 => 'Not found.',
                    405 => 'Method not allowed.',
                    429 => 'Too many requests.',
                    default => 'An unexpected error occurred.',
                };

                $body = ['message' => $message];
                if ($ref) {
                    $body['error_ref'] = $ref;
                }

                return response()->json($body, $status);
            }

            return response()->view('errors.error', [
                'status' => $status,
                'ref'    => $ref,
            ], $status);
        });
    })
    ->withProviders([
        App\Providers\AppServiceProvider::class,
        App\Providers\Filament\AdminPanelProvider::class,
    ])
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('runs:cleanup')->dailyAt('02:00');
        $schedule->command('model:prune', ['--model' => [\App\Models\RunEvent::class]])->hourly();
        $schedule->command('signaldeck:run-scheduled')->everyMinute();
    })
    ->create();
