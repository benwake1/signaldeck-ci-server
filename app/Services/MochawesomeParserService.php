<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MochawesomeParserService
{
    /**
     * Parse a merged mochawesome JSON file and store results in the database.
     */
    public function parse(TestRun $run, string $mergedJsonPath): void
    {
        $json = file_get_contents($mergedJsonPath);
        $data = json_decode($json, true);

        if (!$data) {
            throw new \RuntimeException('Failed to parse mochawesome JSON');
        }

        // All DB writes wrapped in a transaction so a mid-parse failure leaves no
        // partial results — the run stays in its previous state and can be retried.
        DB::transaction(function () use ($run, $data) {
            $stats = $data['stats'] ?? [];
            $totalTests = $stats['tests'] ?? 0;
            $failures = $stats['failures'] ?? 0;
            $run->update([
                'total_tests'   => $totalTests,
                'passed_tests'  => $stats['passes']   ?? 0,
                'failed_tests'  => $failures,
                'pending_tests' => $stats['pending']  ?? 0,
                'duration_ms'   => $stats['duration'] ?? null,
                'status'        => $totalTests === 0 ? TestRun::STATUS_ERROR : ($failures > 0 ? TestRun::STATUS_FAILED : TestRun::STATUS_PASSING),
                'error_message' => $totalTests === 0 ? 'No tests were found or executed.' : null,
                'finished_at'   => now(),
            ]);

            foreach ($data['results'] ?? [] as $result) {
                $specFile = $result['file'] ?? 'unknown';
                $this->parseResult($run, $result, $specFile);
            }
        });
    }

    private function parseResult(TestRun $run, array $result, string $specFile): void
    {
        foreach ($result['suites'] ?? [] as $suite) {
            $this->parseSuite($run, $suite, $specFile, $result['title'] ?? '');
        }

        // Handle tests at the root level (outside suites)
        foreach ($result['tests'] ?? [] as $test) {
            $this->storeTestResult($run, $test, $specFile, '');
        }
    }

    private function parseSuite(TestRun $run, array $suite, string $specFile, string $parentTitle): void
    {
        $suiteTitle = $suite['title'] ?? '';
        $fullSuiteTitle = $parentTitle ? "{$parentTitle} > {$suiteTitle}" : $suiteTitle;

        foreach ($suite['tests'] ?? [] as $test) {
            $this->storeTestResult($run, $test, $specFile, $fullSuiteTitle);
        }

        // Recurse into nested suites
        foreach ($suite['suites'] ?? [] as $nestedSuite) {
            $this->parseSuite($run, $nestedSuite, $specFile, $fullSuiteTitle);
        }
    }

    private function storeTestResult(TestRun $run, array $test, string $specFile, string $suiteTitle): void
    {
        $status = 'pending';
        if ($test['pass'] ?? false) {
            $status = 'passed';
        } elseif ($test['fail'] ?? false) {
            $status = 'failed';
        } elseif ($test['pending'] ?? false) {
            $status = 'pending';
        } elseif ($test['skipped'] ?? false) {
            $status = 'skipped';
        }

        $err = $test['err'] ?? [];
        $errorMessage = $err['message'] ?? null;
        $errorStack = $err['estack'] ?? $err['stack'] ?? null;

        TestResult::create([
            'test_run_id' => $run->id,
            'spec_file' => $specFile,
            'suite_title' => $suiteTitle,
            'test_title' => $test['title'] ?? '',
            'full_title' => $test['fullTitle'] ?? ($suiteTitle ? "{$suiteTitle} {$test['title']}" : $test['title']),
            'status' => $status,
            'duration_ms' => $test['duration'] ?? null,
            'error_message' => $errorMessage,
            'error_stack' => $errorStack,
            'test_code' => $test['code'] ?? null,
            'attempt' => $test['currentRetry'] ?? 0,
        ]);
    }

    /**
     * Recursively find all files with a given extension under a directory.
     */
    private function findFiles(string $dir, string $extension): array
    {
        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === $extension) {
                $results[] = $file->getPathname();
            }
        }
        return $results;
    }

    /**
     * Map video files to spec files based on filename matching.
     * Cypress creates one video per spec file, stored at videosFolder/{full/spec/path}.mp4
     */
    public function mapVideosToResults(TestRun $run, string $videosDir): void
    {
        if (!is_dir($videosDir)) return;

        $videoFiles = $this->findFiles($videosDir, 'mp4');
        if (empty($videoFiles)) return;

        // Build a map of spec basename → stored video path, storing each video file only once
        $storedVideos = [];
        $updates = [];

        foreach ($run->testResults as $result) {
            $specBasename = pathinfo($result->spec_file, PATHINFO_FILENAME);

            if (!isset($storedVideos[$specBasename])) {
                foreach ($videoFiles as $videoFile) {
                    if (str_contains($videoFile, $specBasename)) {
                        $storedVideos[$specBasename] = $this->storeArtifact($videoFile, "runs/{$run->id}/videos");
                        break;
                    }
                }
            }

            if (isset($storedVideos[$specBasename])) {
                $updates[$storedVideos[$specBasename]][] = $result->id;
            }
        }

        foreach ($updates as $videoPath => $ids) {
            TestResult::whereIn('id', $ids)->update(['video_path' => $videoPath]);
        }
    }

    /**
     * Map screenshots to failed tests.
     */
    public function mapScreenshotsToResults(TestRun $run, string $screenshotsDir): void
    {
        if (!is_dir($screenshotsDir)) return;

        $screenshotFiles = $this->findFiles($screenshotsDir, 'png');

        $failedResults = $run->testResults()->where('status', 'failed')->get();
        $storedScreenshots = [];
        $updates = [];

        foreach ($failedResults as $result) {
            $testTitleNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $result->test_title);
            $matchingPaths = [];

            foreach ($screenshotFiles as $screenshot) {
                $screenshotNormalized = preg_replace('/[^a-zA-Z0-9]/', '', basename($screenshot));

                if (stripos($screenshotNormalized, $testTitleNormalized) !== false) {
                    if (!isset($storedScreenshots[$screenshot])) {
                        $storedScreenshots[$screenshot] = $this->storeArtifact($screenshot, "runs/{$run->id}/screenshots");
                    }
                    $matchingPaths[] = $storedScreenshots[$screenshot];
                }
            }

            if (!empty($matchingPaths)) {
                $updates[$result->id] = $matchingPaths;
            }
        }

        foreach ($updates as $resultId => $paths) {
            TestResult::where('id', $resultId)->update(['screenshot_paths' => json_encode($paths)]);
        }
    }

    private function storeArtifact(string $sourcePath, string $destinationDir): string
    {
        $filename = basename($sourcePath);
        $destinationPath = $destinationDir . '/' . $filename;
        Storage::disk(config('filesystems.default'))->put($destinationPath, file_get_contents($sourcePath));
        return $destinationPath;
    }
}
