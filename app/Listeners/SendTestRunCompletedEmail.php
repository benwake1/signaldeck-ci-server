<?php

namespace App\Listeners;

use App\Events\TestRunStatusChanged;
use App\Mail\TestRunCompletedMailable;
use App\Models\AppSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendTestRunCompletedEmail implements ShouldQueue
{
    public function handle(TestRunStatusChanged $event): void
    {
        $run = $event->run;

        // Only fire on terminal statuses (use event's captured status, not the
        // re-fetched model, to avoid all queued listeners firing once the run completes)
        if (! in_array($event->status, ['passing', 'failed'])) {
            return;
        }

        // Respect the notifications toggle
        if (AppSetting::get('notifications_enabled', '1') !== '1') {
            return;
        }

        // Must have a report to link to
        if (! $run->report_html_path) {
            return;
        }

        // Deduplicate — prevent sending more than once per run
        $cacheKey = "email_sent_run_{$run->id}";
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addMinutes(10));

        // Re-apply mail settings fresh from DB — the queue worker may have booted
        // before settings were saved or changed in the admin panel.
        // Purge the cached transport so the new config is actually used.
        $this->applyMailSettings();
        Mail::purge(config('mail.default'));

        // Send to whoever triggered the run
        $recipient = $run->triggeredBy;
        if (! $recipient?->email) {
            return;
        }

        Mail::to($recipient)->send(new TestRunCompletedMailable($run));
    }

    private function applyMailSettings(): void
    {
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
    }
}
