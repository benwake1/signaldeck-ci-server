<?php

namespace App\Services;

use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlaywrightParserService
{
    /**
     * Parse Playwright's JSON reporter output and store results in the database.
     *
     * Playwright JSON structure:
     * {
     *   "suites": [{ "title": "", "file": "tests/foo.spec.ts", "suites": [...], "specs": [...] }],
     *   "stats": { "expected": 10, "unexpected": 2, "skipped": 1, "flaky": 0, "duration": 12345, ... }
     * }
     */
    public function parse(TestRun $run, string $jsonPath): void
    {
        $json = file_get_contents($jsonPath);
        $data = json_decode($json, true);

        if (!$data) {
            throw new \RuntimeException('Failed to parse Playwright JSON');
        }

        DB::transaction(function () use ($run, $data) {
            $stats = $data['stats'] ?? [];

            $passed = ($stats['expected'] ?? 0) + ($stats['flaky'] ?? 0);
            $failed = $stats['unexpected'] ?? 0;
            $skipped = $stats['skipped'] ?? 0;
            $total = $passed + $failed + $skipped;
            $duration = $stats['duration'] ?? null;

            $run->update([
                'total_tests'    => $total,
                'passed_tests'   => $passed,
                'failed_tests'   => $failed,
                'pending_tests'  => $skipped,
                'duration_ms'    => $duration,
                'status'         => $total === 0 ? TestRun::STATUS_ERROR : ($failed > 0 ? TestRun::STATUS_FAILED : TestRun::STATUS_PASSING),
                'error_message'  => $total === 0 ? 'No tests were found or executed.' : null,
                'finished_at'    => now(),
            ]);

            foreach ($data['suites'] ?? [] as $suite) {
                $this->parseSuite($run, $suite, '');
            }
        });
    }

    private function parseSuite(TestRun $run, array $suite, string $parentTitle): void
    {
        $suiteTitle = $suite['title'] ?? '';
        $fullSuiteTitle = $parentTitle ? "{$parentTitle} > {$suiteTitle}" : $suiteTitle;
        $specFile = $suite['file'] ?? 'unknown';

        // Parse specs (test definitions) in this suite
        foreach ($suite['specs'] ?? [] as $spec) {
            $this->parseSpec($run, $spec, $specFile, $fullSuiteTitle);
        }

        // Recurse into nested suites
        foreach ($suite['suites'] ?? [] as $nestedSuite) {
            // Nested suites inherit the file from the parent if not set
            if (empty($nestedSuite['file'])) {
                $nestedSuite['file'] = $specFile;
            }
            $this->parseSuite($run, $nestedSuite, $fullSuiteTitle);
        }
    }

    private function parseSpec(TestRun $run, array $spec, string $specFile, string $suiteTitle): void
    {
        $testTitle = $spec['title'] ?? '';
        $fullTitle = $suiteTitle ? "{$suiteTitle} {$testTitle}" : $testTitle;

        // Each spec has "tests" which represent the test across different projects (browsers)
        foreach ($spec['tests'] ?? [] as $test) {
            $projectName = $test['projectName'] ?? '';
            $displayTitle = $projectName ? "{$testTitle} [{$projectName}]" : $testTitle;
            $displayFullTitle = $projectName ? "{$fullTitle} [{$projectName}]" : $fullTitle;

            // Get the last result (final attempt)
            $results = $test['results'] ?? [];
            $lastResult = end($results) ?: [];

            $status = $this->mapStatus($test['status'] ?? 'skipped');

            // Extract error info from the last result
            $errorMessage = null;
            $errorStack = null;
            if (!empty($lastResult['errors'])) {
                $firstError = $lastResult['errors'][0] ?? [];
                $errorMessage = $firstError['message'] ?? null;
                $errorStack = $firstError['stack'] ?? null;
            } elseif (!empty($lastResult['error'])) {
                $errorMessage = $lastResult['error']['message'] ?? null;
                $errorStack = $lastResult['error']['stack'] ?? null;
            }

            TestResult::create([
                'test_run_id'  => $run->id,
                'spec_file'    => $specFile,
                'suite_title'  => $suiteTitle,
                'test_title'   => $displayTitle,
                'full_title'   => $displayFullTitle,
                'status'       => $status,
                'duration_ms'  => $lastResult['duration'] ?? null,
                'error_message' => $errorMessage,
                'error_stack'  => $errorStack,
                'test_code'    => null,
                'attempt'      => count($results),
            ]);
        }
    }

    private function mapStatus(string $playwrightStatus): string
    {
        return match ($playwrightStatus) {
            'expected'   => 'passed',
            'unexpected' => 'failed',
            'flaky'      => 'passed',
            'skipped'    => 'skipped',
            default      => 'pending',
        };
    }

    /**
     * Map artifacts from Playwright's test-results directory.
     *
     * Playwright stores artifacts per-test in: test-results/{test-name-hash}/
     * Each directory may contain screenshots (.png) and videos (.webm).
     */
    public function mapArtifacts(TestRun $run, string $testResultsDir): void
    {
        if (!is_dir($testResultsDir)) return;

        $this->mapVideos($run, $testResultsDir);
        $this->mapScreenshots($run, $testResultsDir);
    }

    private function mapVideos(TestRun $run, string $testResultsDir): void
    {
        $videoFiles = $this->findFiles($testResultsDir, 'webm');
        if (empty($videoFiles)) return;

        $storedVideos = [];

        foreach ($run->testResults as $result) {
            $specBasename = pathinfo($result->spec_file, PATHINFO_FILENAME);
            $testNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $result->test_title);

            foreach ($videoFiles as $videoFile) {
                $dirName = basename(dirname($videoFile));
                $dirNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $dirName);

                if (stripos($dirNormalized, $testNormalized) !== false
                    || str_contains($dirName, $specBasename)) {
                    if (!isset($storedVideos[$videoFile])) {
                        $storedVideos[$videoFile] = $this->storeArtifact($videoFile, "runs/{$run->id}/videos");
                    }
                    $result->update(['video_path' => $storedVideos[$videoFile]]);
                    break;
                }
            }
        }
    }

    private function mapScreenshots(TestRun $run, string $testResultsDir): void
    {
        $screenshotFiles = $this->findFiles($testResultsDir, 'png');
        if (empty($screenshotFiles)) return;

        $failedResults = $run->testResults()->where('status', 'failed')->get();
        $storedScreenshots = [];

        foreach ($failedResults as $result) {
            $testNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $result->test_title);
            $matchingPaths = [];

            foreach ($screenshotFiles as $screenshot) {
                $dirName = basename(dirname($screenshot));
                $dirNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $dirName);

                if (stripos($dirNormalized, $testNormalized) !== false) {
                    if (!isset($storedScreenshots[$screenshot])) {
                        $storedScreenshots[$screenshot] = $this->storeArtifact($screenshot, "runs/{$run->id}/screenshots");
                    }
                    $matchingPaths[] = $storedScreenshots[$screenshot];
                }
            }

            if (!empty($matchingPaths)) {
                $result->update(['screenshot_paths' => $matchingPaths]);
            }
        }
    }

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

    private function storeArtifact(string $sourcePath, string $destinationDir): string
    {
        $filename = basename($sourcePath);
        $destinationPath = $destinationDir . '/' . $filename;
        Storage::disk('public')->put($destinationPath, file_get_contents($sourcePath));
        return $destinationPath;
    }
}
