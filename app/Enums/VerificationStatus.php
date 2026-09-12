<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Enums;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case FailedUnverified = 'failed_unverified';
    case SkippedNoUrl = 'skipped_no_url';
    case SkippedUnsupportedFramework = 'skipped_unsupported_framework';
    case SkippedExtraDependencies = 'skipped_extra_dependencies';
    case SkippedNoTestFiles = 'skipped_no_test_files';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Verifying…',
            self::Passed => 'Verified — passed against the live site',
            self::FailedUnverified => 'Not verified — review before saving',
            self::SkippedNoUrl => 'Not verified — no target URL to run against',
            self::SkippedUnsupportedFramework => 'Not verified — this framework is not supported by the verification loop',
            self::SkippedExtraDependencies => 'Not verified — requires extra dependencies not supported in the verification loop',
            self::SkippedNoTestFiles => 'Not verified — no recognized test file in this generation',
        };
    }

    public function isPassing(): bool
    {
        return $this === self::Passed;
    }

    public function isUnverified(): bool
    {
        return $this !== self::Passed && $this !== self::Pending;
    }
}
