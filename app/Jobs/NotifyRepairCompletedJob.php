<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs;

use App\Mail\SuiteRepairProposedMailable;
use App\Models\AiConversation;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Runs after VerifyGeneratedTestJob reaches a terminal state for a
 * repair-originated conversation (chained by TestRepairService). Notifies
 * admins regardless of whether verification passed or not — either way a
 * proposed fix is sitting there for human review, never auto-merged.
 */
class NotifyRepairCompletedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly AiConversation $conversation,
    ) {}

    public function handle(): void
    {
        if (AppSetting::get('notifications_enabled', '1') !== '1') {
            return;
        }

        $conversation = $this->conversation->fresh();
        $suite = $conversation?->testSuite;

        if (!$conversation || !$suite) {
            return;
        }

        $admins = User::where('role', 'admin')->whereNotNull('email')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->send(new SuiteRepairProposedMailable($conversation, $suite));
        }
    }
}
