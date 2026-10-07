<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Support;

/**
 * Flags the ways an AI-proposed edit could make a failing test "pass" without
 * fixing anything: assertion lines that disappeared or changed, and newly
 * skipped tests. Line-based and deliberately over-eager — a reviewer would
 * rather see a false positive than miss a weakened check.
 */
class AssertionDiff
{
    private const ASSERTION_PATTERN = '/\b(expect|assert)\s*\(|\.(should|and)\s*\(|\bcy\.contains\s*\(|\.to(Have|Be|Equal|Contain|Match)\w*\s*\(/';

    private const SKIP_PATTERN = '/\b(it|test|describe|context)\.skip\s*\(|\bx(it|describe)\s*\(|\btest\.fixme\s*\(/';

    /**
     * @param array<string, string> $before path => content of the current files
     * @param array<string, string> $after  path => content of the proposed files (files absent here are unchanged)
     * @return array{removed: array<int, array{file: string, line: string}>, skipped: array<int, array{file: string, line: string}>}
     */
    public static function compare(array $before, array $after): array
    {
        $removed = [];
        $skipped = [];

        foreach ($after as $path => $newContent) {
            $oldLines = self::lines($before[$path] ?? '');
            $newLines = self::lines($newContent);
            $newSet = array_flip($newLines);
            $oldSet = array_flip($oldLines);

            foreach ($oldLines as $line) {
                if (preg_match(self::ASSERTION_PATTERN, $line) && !isset($newSet[$line])) {
                    $removed[] = ['file' => $path, 'line' => $line];
                }
            }

            foreach ($newLines as $line) {
                if (preg_match(self::SKIP_PATTERN, $line) && !isset($oldSet[$line])) {
                    $skipped[] = ['file' => $path, 'line' => $line];
                }
            }
        }

        return ['removed' => $removed, 'skipped' => $skipped];
    }

    /** @return array<int, string> */
    private static function lines(string $content): array
    {
        $lines = array_map('trim', preg_split('/\R/', $content));

        return array_values(array_unique(array_filter($lines, fn ($l) => $l !== '' && !str_starts_with($l, '//'))));
    }
}
