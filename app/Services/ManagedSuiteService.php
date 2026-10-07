<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Enums\SourceType;
use App\Models\ManagedTestFile;
use App\Models\TestSuite;
use App\Support\ManagedSuiteDefaults;
use Illuminate\Support\Facades\DB;

class ManagedSuiteService
{
    /**
     * Create a managed suite from builder-generated files. Shared by the
     * Filament builder page and the API so both produce a runnable suite.
     *
     * @param array<string, string> $files path => content
     */
    public function create(int $projectId, string $framework, string $name, array $files, ?string $baseUrl, ?int $userId): TestSuite
    {
        return DB::transaction(function () use ($projectId, $framework, $name, $files, $baseUrl, $userId) {
            $suite = TestSuite::create([
                'project_id' => $projectId,
                'source_type' => SourceType::Managed,
                'runner_type' => $framework,
                'name' => $name,
                'spec_pattern' => ManagedSuiteDefaults::specPattern($framework),
                'base_url' => $baseUrl,
                'active' => true,
            ]);

            foreach ($files as $path => $content) {
                ManagedTestFile::create([
                    'test_suite_id' => $suite->id,
                    'file_path' => $path,
                    'content' => $content,
                    'generated_by' => $userId,
                ]);
            }

            // Seed an editable Cypress config so per-suite nuance doesn't need code changes.
            if ($framework === 'cypress' && !collect(array_keys($files))->contains(fn ($p) => str_contains($p, 'cypress.config'))) {
                ManagedTestFile::create([
                    'test_suite_id' => $suite->id,
                    'file_path' => ManagedSuiteDefaults::CYPRESS_CONFIG_PATH,
                    'content' => ManagedSuiteDefaults::cypressConfig($suite->base_url, $suite->spec_pattern),
                    'generated_by' => $userId,
                ]);
            }

            return $suite;
        });
    }
}
