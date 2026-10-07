<?php

namespace App\Support;

class ManagedSuiteDefaults
{
    public const CYPRESS_CONFIG_PATH = 'cypress.config.js';

    /** Cypress's builder output is named *.cy.js; Playwright's *.spec.ts. */
    public static function specPattern(string $framework): string
    {
        return $framework === 'cypress' ? '**/*.cy.{js,ts}' : '**/*.spec.{js,ts}';
    }

    /** Minimal Cypress config for builder-generated suites, which only contain spec files. */
    public static function cypressConfig(?string $baseUrl, string $specPattern): string
    {
        $baseUrl = addslashes((string) $baseUrl);
        $specPattern = addslashes($specPattern);
        $baseUrlLine = $baseUrl !== '' ? "baseUrl: '{$baseUrl}'," : '';

        return "module.exports = {\n  e2e: {\n    {$baseUrlLine}\n    supportFile: false,\n    specPattern: '{$specPattern}',\n  },\n  video: false,\n};\n";
    }

    /** Files a user may have edited by hand that a regenerate must not wipe. */
    public static function isConfigFile(string $path): bool
    {
        return (bool) preg_match('#(^|/)(cypress|playwright)\.config\.[cm]?[jt]s$|(^|/)package\.json$#', $path);
    }
}
