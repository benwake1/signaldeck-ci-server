<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Providers;

use App\Events\TestRunStatusChanged;
use App\Listeners\SendTestRunCompletedEmail;
use App\Listeners\SendTestRunSlackNotification;
use App\Models\AppSetting;
use App\Services\S3ConfigService;
use App\Services\SlackService;
use App\Services\SsoConfigService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SsoConfigService::class);
        $this->app->singleton(SlackService::class);
    }

    public function boot(): void
    {
        // Load S3 config from DB — matches the pattern of applyMailSettings() / applySsoSettings().
        // try/catch guards against the DB being unavailable during migrations.
        try {
            S3ConfigService::loadFromSettings();
        } catch (\Throwable) {
            // DB not ready (e.g., fresh install before migrations). Fall back to .env config.
        }

        Event::listen(TestRunStatusChanged::class, SendTestRunCompletedEmail::class);
        Event::listen(TestRunStatusChanged::class, SendTestRunSlackNotification::class);

        // Redis queue retry_after defaults to 90 s in Laravel's vendor config, which
        // is far shorter than a typical test run. Override it here so jobs are never
        // re-queued mid-flight and immediately fail with MaxAttemptsExceededException.
        // Value matches the --timeout=14400 set on the queue worker in supervisord.conf.
        Config::set(
            'queue.connections.redis.retry_after',
            (int) env('REDIS_QUEUE_RETRY_AFTER', 14460)
        );

        $this->applyMailSettings();
        $this->applySsoSettings();
    }

    private function applyMailSettings(): void
    {
        // Hosted instances use mail configured at the server level via .env
        if (config('brand.is_hosted')) {
            return;
        }

        try {
            $map = [
                'mail_mailer'       => 'mail.default',
                'mail_from_address' => 'mail.from.address',
                'mail_from_name'    => 'mail.from.name',
                'mail_host'         => 'mail.mailers.smtp.host',
                'mail_port'         => 'mail.mailers.smtp.port',
                'mail_username'     => 'mail.mailers.smtp.username',
                'mail_password'     => 'mail.mailers.smtp.password',
                'mail_encryption'   => 'mail.mailers.smtp.encryption',
            ];

            foreach ($map as $setting => $configKey) {
                $value = AppSetting::get($setting);
                if ($value !== null && $value !== '') {
                    Config::set($configKey, $value);
                }
            }
        } catch (\Throwable) {
            // DB not available (e.g. during migrations or first install)
        }
    }

    private function applySsoSettings(): void
    {
        try {
            app(SsoConfigService::class)->applyRuntimeConfig();
        } catch (\Throwable) {
            // DB not available (e.g. during migrations or first install)
        }
    }
}
