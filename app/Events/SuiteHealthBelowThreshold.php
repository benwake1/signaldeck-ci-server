<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Events;

use App\Models\TestSuite;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after every completed run that leaves a suite below its pass-rate
 * threshold. Unlike SuiteHealthBreached (alerts, throttled to once an hour),
 * this is never throttled — listeners apply their own gating.
 */
class SuiteHealthBelowThreshold
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TestSuite $suite,
        public readonly float     $currentPassRate,
        public readonly float     $threshold
    ) {}
}
