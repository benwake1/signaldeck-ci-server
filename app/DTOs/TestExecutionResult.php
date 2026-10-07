<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\DTOs;

class TestExecutionResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $output,
        public readonly array $failedTests = [],
    ) {}

    public static function crashed(string $output): self
    {
        return new self(passed: false, output: $output, failedTests: []);
    }
}
