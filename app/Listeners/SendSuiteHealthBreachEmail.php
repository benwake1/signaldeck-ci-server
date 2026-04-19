<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Listeners;

use App\Events\SuiteHealthBreached;
use App\Mail\SuiteHealthBreachedMailable;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendSuiteHealthBreachEmail implements ShouldQueue
{
    public function handle(SuiteHealthBreached $event): void
    {
        if (AppSetting::get('notifications_enabled', '1') !== '1') {
            return;
        }

        $cacheKey = "health_breach_email_sent_suite_{$event->suite->id}";
        if (! Cache::add($cacheKey, true, now()->addHour())) {
            return;
        }

        $admins = User::where('role', 'admin')->whereNotNull('email')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->send(
                new SuiteHealthBreachedMailable($event->suite, $event->currentPassRate, $event->threshold)
            );
        }
    }
}
