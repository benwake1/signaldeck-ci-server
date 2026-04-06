<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Services\Concerns\MagentoSelectorMaps;
use Illuminate\Support\Str;
use ZipArchive;

class TestGeneratorService
{
    use MagentoSelectorMaps;

    private const SCENARIO_FILE_MAP = [
        'cypress' => [
            'homepage'             => 'e2e/homepage.cy.js',
            'category'             => 'e2e/category.cy.js',
            'product'              => 'e2e/product.cy.js',
            'add_to_cart'          => 'e2e/add-to-cart.cy.js',
            'cart'                 => 'e2e/cart.cy.js',
            'guest_checkout'       => 'e2e/guest-checkout.cy.js',
            'account_registration' => 'e2e/account-registration.cy.js',
            'auth'                 => 'e2e/auth.cy.js',
            'search'               => 'e2e/search.cy.js',
        ],
        'playwright' => [
            'homepage'             => 'tests/homepage.spec.ts',
            'category'             => 'tests/category.spec.ts',
            'product'              => 'tests/product.spec.ts',
            'add_to_cart'          => 'tests/add-to-cart.spec.ts',
            'cart'                 => 'tests/cart.spec.ts',
            'guest_checkout'       => 'tests/guest-checkout.spec.ts',
            'account_registration' => 'tests/account-registration.spec.ts',
            'auth'                 => 'tests/auth.spec.ts',
            'search'               => 'tests/search.spec.ts',
        ],
    ];

    /**
     * Generate a test suite ZIP and return the path to the temp file.
     *
     * @param  array{
     *   framework: string,
     *   platform: string,
     *   scenarios: string[],
     *   base_url: string,
     *   admin_url: ?string,
     *   test_email: string,
     *   test_password: string,
     *   timeout_seconds: int,
     *   headless: bool,
     *   pw_workers: int,
     *   pw_retries: int,
     * } $config
     */
    public function generate(array $config): string
    {
        $uuid   = Str::uuid()->toString();
        $tmpDir = storage_path("app/temp/test-generator/{$uuid}");

        try {
            $this->createDirectoryStructure($tmpDir, $config['framework']);

            $selectors = $this->getSelectorMapping($config['platform']);
            $context   = $this->buildContext($config, $selectors);

            $this->renderScenarioFiles($tmpDir, $config['framework'], $config['scenarios'], $context);
            $this->renderSupportFiles($tmpDir, $config['framework'], $context);

            $zipPath = $this->createZip($tmpDir, $config['framework']);

            return $zipPath;
        } finally {
            $this->deleteDirectory($tmpDir);
        }
    }

    private function buildContext(array $config, array $selectors): array
    {
        return [
            'baseUrl'         => rtrim($config['base_url'], '/'),
            'adminUrl'        => rtrim($config['admin_url'] ?? '', '/') ?: null,
            'testEmail'       => $config['test_email'],
            'testPassword'    => $config['test_password'],
            'timeoutSeconds'  => (int) ($config['timeout_seconds'] ?? 30),
            'headless'        => (bool) ($config['headless'] ?? true),
            'selectors'       => $selectors,
            'pwWorkers'       => (int) ($config['pw_workers'] ?? 4),
            'pwRetries'       => (int) ($config['pw_retries'] ?? 2),
            'platform'        => $config['platform'],
            'framework'       => $config['framework'],
        ];
    }

    private function createDirectoryStructure(string $tmpDir, string $framework): void
    {
        $dirs = $framework === 'cypress'
            ? [
                $tmpDir,
                "{$tmpDir}/cypress/e2e",
                "{$tmpDir}/cypress/support",
                "{$tmpDir}/cypress/fixtures",
            ]
            : [
                $tmpDir,
                "{$tmpDir}/tests",
            ];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function renderScenarioFiles(string $tmpDir, string $framework, array $scenarios, array $context): void
    {
        $fileMap = self::SCENARIO_FILE_MAP[$framework];

        foreach ($scenarios as $scenario) {
            if (! isset($fileMap[$scenario])) {
                continue;
            }

            $stubName   = str_replace(['e2e/', 'tests/'], '', $fileMap[$scenario]);
            $stubName   = str_replace(['.cy.js', '.spec.ts'], '', $stubName);
            $stubPath   = resource_path("test-templates/{$framework}/scenarios/{$stubName}.blade.php");
            $outputPath = $framework === 'cypress'
                ? "{$tmpDir}/cypress/{$fileMap[$scenario]}"
                : "{$tmpDir}/{$fileMap[$scenario]}";

            $this->renderStub($stubPath, $outputPath, array_merge($context, [
                'scenarioSelectors' => $context['selectors'][$scenario] ?? [],
            ]));
        }
    }

    private function renderSupportFiles(string $tmpDir, string $framework, array $context): void
    {
        $supportStubs = $framework === 'cypress'
            ? [
                'config'           => ['stub' => 'cypress.config.blade.php',         'out' => "{$tmpDir}/cypress.config.js"],
                'package'          => ['stub' => 'package.blade.php',                 'out' => "{$tmpDir}/package.json"],
                'support_commands' => ['stub' => 'support/commands.blade.php',        'out' => "{$tmpDir}/cypress/support/commands.js"],
                'support_e2e'      => ['stub' => 'support/e2e.blade.php',             'out' => "{$tmpDir}/cypress/support/e2e.js"],
                'fixture_selectors'=> ['stub' => 'fixtures/selectors.blade.php',      'out' => "{$tmpDir}/cypress/fixtures/selectors.json"],
                'fixture_customer' => ['stub' => 'fixtures/customer.blade.php',       'out' => "{$tmpDir}/cypress/fixtures/customer.json"],
            ]
            : [
                'config'  => ['stub' => 'playwright.config.blade.php', 'out' => "{$tmpDir}/playwright.config.ts"],
                'package' => ['stub' => 'package.blade.php',            'out' => "{$tmpDir}/package.json"],
            ];

        foreach ($supportStubs as $stub) {
            $stubPath = resource_path("test-templates/{$framework}/{$stub['stub']}");
            $this->renderStub($stubPath, $stub['out'], $context);
        }
    }

    private function renderStub(string $stubPath, string $outputPath, array $context): void
    {
        if (! file_exists($stubPath)) {
            return;
        }

        $content = view()->file($stubPath, $context)->render();
        if (file_put_contents($outputPath, $content) === false) {
            throw new \RuntimeException("Failed to write generated file: {$outputPath}");
        }
    }

    private function createZip(string $tmpDir, string $framework): string
    {
        $zipPath = storage_path('app/temp/test-suite-' . time() . '-' . Str::random(6) . '.zip');

        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException("Failed to create ZIP archive (code: {$result})");
        }

        $rootName = $framework === 'cypress' ? 'cypress-tests' : 'playwright-tests';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $filePath   = $file->getRealPath();
            $relativePath = $rootName . '/' . substr($filePath, strlen($tmpDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();

        return $zipPath;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
