<?php

namespace Tests\Unit;

use App\Support\ManagedSuiteDefaults;
use PHPUnit\Framework\TestCase;

class ManagedSuiteDefaultsTest extends TestCase
{
    public function test_cypress_config_includes_base_url_and_escapes_quotes(): void
    {
        $c = ManagedSuiteDefaults::cypressConfig("https://x.test/it's", '**/*.cy.js');

        $this->assertStringContainsString("baseUrl: 'https://x.test/it\\'s',", $c);
        $this->assertStringContainsString("specPattern: '**/*.cy.js'", $c);
    }

    public function test_cypress_config_omits_empty_base_url(): void
    {
        $this->assertStringNotContainsString('baseUrl', ManagedSuiteDefaults::cypressConfig(null, 'x'));
    }

    public function test_config_file_detection(): void
    {
        foreach (['cypress.config.js', 'cypress.config.ts', 'sub/playwright.config.ts', 'package.json'] as $p) {
            $this->assertTrue(ManagedSuiteDefaults::isConfigFile($p), $p);
        }
        foreach (['cypress/e2e/a.cy.js', 'tests/config.spec.ts'] as $p) {
            $this->assertFalse(ManagedSuiteDefaults::isConfigFile($p), $p);
        }
    }
}
