<?php

namespace App\Services;

use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Facades\Log;
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

        // Update run stats from the stats block
        $stats = $data['stats'] ?? [];
        $run->update([
            'total_tests' => $stats['tests'] ?? 0,
            'passed_tests' => $stats['passes'] ?? 0,
            'failed_tests' => $stats['failures'] ?? 0,
            'pending_tests' => $stats['pending'] ?? 0,
            'duration_ms' => $stats['duration'] ?? null,
            'status' => ($stats['failures'] ?? 0) > 0 ? TestRun::STATUS_FAILED : TestRun::STATUS_PASSING,
            'finished_at' => now(),
        ]);

        // Parse each result (spec file) and its suites/tests
        foreach ($data['results'] ?? [] as $result) {
            $specFile = $result['file'] ?? 'unknown';
            $this->parseResult($run, $result, $specFile);
        }
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

        // Cache stored video paths keyed by spec basename to avoid re-storing the same file
        $storedVideos = [];

        foreach ($run->testResults as $result) {
            $specBasename = pathinfo($result->spec_file, PATHINFO_FILENAME);

            if (isset($storedVideos[$specBasename])) {
                $result->update(['video_path' => $storedVideos[$specBasename]]);
                continue;
            }

            foreach ($videoFiles as $videoFile) {
                if (str_contains($videoFile, $specBasename)) {
                    $storedPath = $this->storeArtifact($videoFile, "runs/{$run->id}/videos");
                    $storedVideos[$specBasename] = $storedPath;
                    $result->update(['video_path' => $storedPath]);
                    break;
                }
            }
        }
    }

    /**
     * Map screenshots to failed tests.
     */
    public function mapScreenshotsToResults(TestRun $run, string $screenshotsDir): void
    {
        if (!is_dir($screenshotsDir)) return;

        $screenshotFiles = $this->findFiles($screenshotsDir, 'png');

        foreach ($run->testResults()->where('status', 'failed')->get() as $result) {
            $matchingScreenshots = [];

            foreach ($screenshotFiles as $screenshot) {
                $screenshotName = basename($screenshot);
                // Cypress screenshot names contain the test title
                $testTitleNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $result->test_title);
                $screenshotNormalized = preg_replace('/[^a-zA-Z0-9]/', '', $screenshotName);

                if (stripos($screenshotNormalized, $testTitleNormalized) !== false) {
                    $storedPath = $this->storeArtifact($screenshot, "runs/{$run->id}/screenshots");
                    $matchingScreenshots[] = $storedPath;
                }
            }

            if (!empty($matchingScreenshots)) {
                $result->update(['screenshot_paths' => $matchingScreenshots]);
            }
        }
    }

    private function storeArtifact(string $sourcePath, string $destinationDir): string
    {
        $filename = basename($sourcePath);
        $destinationPath = $destinationDir . '/' . $filename;
        Storage::disk('public')->put($destinationPath, file_get_contents($sourcePath));
        return $destinationPath;
    }
}
