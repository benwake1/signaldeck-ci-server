<?php

namespace App\Listeners;

use App\Events\TestRunStatusChanged;
use App\Mail\TestRunCompletedMailable;
use App\Models\AppSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
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

        // Send to whoever triggered the run
        $recipient = $run->triggeredBy;
        if (! $recipient?->email) {
            return;
        }

        Mail::to($recipient)->send(new TestRunCompletedMailable($run));
    }
}
