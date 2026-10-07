<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Support;

/**
 * Locates a Chromium binary for Cypress to drive. Shared by RunCypressTestJob
 * (full-suite runs) and TestExecutionService (single-spec verification runs)
 * so the resolution list only needs to be kept in one place.
 */
class ChromiumBinaryResolver
{
    public static function resolve(): ?string
    {
        foreach (['/usr/local/bin/chrome-cypress', '/usr/bin/google-chrome-stable'] as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
