<?php

namespace Tests\Unit;

use App\Support\AssertionDiff;
use PHPUnit\Framework\TestCase;

class AssertionDiffTest extends TestCase
{
    public function test_flags_removed_changed_and_skipped_checks_only(): void
    {
        $before = ['a.cy.js' => implode("\n", [
            "it('a', () => {",
            "  cy.get('#old').click();",
            "  cy.get('h1').should('have.text', 'Welcome');",
            "  expect(total).to.equal(3);",
            "  cy.contains('Saved');",
            '});',
        ]), 'b.cy.js' => "expect(x).to.be.ok;"];

        $after = ['a.cy.js' => implode("\n", [
            "it.skip('a', () => {",
            "  cy.get('[data-test=new]').click();",
            "  cy.get('h1').should('exist');",
            "    expect(total).to.equal(3);",
            '});',
        ])];

        $diff = AssertionDiff::compare($before, $after);

        $this->assertSame([
            ['file' => 'a.cy.js', 'line' => "cy.get('h1').should('have.text', 'Welcome');"],
            ['file' => 'a.cy.js', 'line' => "cy.contains('Saved');"],
        ], $diff['removed']);
        $this->assertSame([['file' => 'a.cy.js', 'line' => "it.skip('a', () => {"]], $diff['skipped']);
    }

    public function test_unchanged_files_produce_nothing(): void
    {
        $files = ['a.spec.ts' => "test('a', async ({ page }) => {\n  await expect(page).toHaveTitle('x');\n});"];

        $this->assertSame(['removed' => [], 'skipped' => []], AssertionDiff::compare($files, $files));
    }
}
