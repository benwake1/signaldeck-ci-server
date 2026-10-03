<?php

namespace Tests\Unit;

use App\Enums\RunnerType;
use App\Jobs\Concerns\RunsTestSuite;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ManagedSuiteConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/managed-config-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $files path => content */
    private function write(array $files, RunnerType $runner = RunnerType::Cypress, ?string $baseUrl = 'https://example.cypress.io/'): void
    {
        $suite = new class($files, $runner, $baseUrl) {
            public Collection $managedTestFiles;
            public string $spec_pattern;

            public function __construct(array $files, private RunnerType $runner, public ?string $base_url)
            {
                $this->managedTestFiles = collect($files)->map(fn ($c, $p) => (object) ['file_path' => $p, 'content' => $c])->values();
                $this->spec_pattern = '**/*.cy.{js,ts}';
            }

            public function getEffectiveRunnerType(): RunnerType { return $this->runner; }
        };

        $job = new class($suite, $this->dir) {
            use RunsTestSuite;

            public object $run;

            public function __construct(object $suite, string $dir)
            {
                $this->run = (object) ['id' => 1, 'testSuite' => $suite];
                $this->runPath = $dir;
            }

            protected function updateStatus(string $status): void {}
            protected function log(string $message, bool $persist = true): void {}
            protected function exec(string $command): string { return ''; }

            public function go(): void { $this->writeManagedFiles(); }
        };

        $job->go();
    }

    public function test_cypress_suite_without_config_gets_generated_config(): void
    {
        $this->write(['cypress/e2e/a.cy.js' => "it('a', () => {});"]);

        $config = file_get_contents($this->dir . '/cypress.config.js');
        $this->assertStringContainsString("baseUrl: 'https://example.cypress.io/'", $config);
        $this->assertStringContainsString("specPattern: '**/*.cy.{js,ts}'", $config);
        $this->assertStringContainsString('supportFile: false', $config);
    }

    public function test_generated_config_omits_base_url_when_unset(): void
    {
        $this->write(['cypress/e2e/a.cy.js' => 'x'], baseUrl: null);

        $this->assertStringNotContainsString('baseUrl', file_get_contents($this->dir . '/cypress.config.js'));
    }

    public function test_existing_cypress_config_is_not_overwritten(): void
    {
        $this->write(['cypress.config.js' => '// mine', 'cypress/e2e/a.cy.js' => 'x']);

        $this->assertSame('// mine', file_get_contents($this->dir . '/cypress.config.js'));
    }

    public function test_playwright_suite_gets_no_cypress_config(): void
    {
        $this->write(['tests/a.spec.ts' => 'x'], RunnerType::Playwright);

        $this->assertFileDoesNotExist($this->dir . '/cypress.config.js');
        $this->assertFileExists($this->dir . '/playwright.config.ts');
    }

    public function test_generated_package_json_pins_mocha_and_reporter_deps(): void
    {
        $this->write(['cypress/e2e/a.cy.js' => 'x']);

        $deps = json_decode(file_get_contents($this->dir . '/package.json'), true)['dependencies'];
        $this->assertSame('^10', $deps['mocha']);
        $this->assertArrayHasKey('mochawesome-merge', $deps);
    }
}
