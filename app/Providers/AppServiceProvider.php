<?php

namespace App\Providers;

use App\Events\TestRunStatusChanged;
use App\Listeners\SendTestRunCompletedEmail;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(TestRunStatusChanged::class, SendTestRunCompletedEmail::class);

        $this->applyMailSettings();
    }

    private function applyMailSettings(): void
    {
        try {
            $map = [
                'mail_mailer'       => 'mail.default',
                'mail_from_address' => 'mail.from.address',
                'mail_from_name'    => 'mail.from.name',
                'mail_host'         => 'mail.mailers.smtp.host',
                'mail_port'         => 'mail.mailers.smtp.port',
                'mail_username'     => 'mail.mailers.smtp.username',
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
}
