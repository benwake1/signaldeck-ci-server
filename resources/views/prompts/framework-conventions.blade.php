## {{ $framework }} conventions

@if($framework === 'cypress')
- Use `cy.visit()` for navigation, `cy.get()` for element selection.
- Use `cy.contains()` for text-based selection when no better selector exists.
- Use `cy.intercept()` for API stubbing and waiting on network requests.
- Use `.should()` for assertions — chain multiple assertions where appropriate.
- For URL assertions, prefer `cy.url().should('include', '/path')` or `cy.location('pathname').should('eq', '/path')` over asserting the full URL with its query string.
- Use `beforeEach` for common setup like visiting a page.
- Use `cy.session()` for login state that persists across tests.
- Avoid hard-coded waits (`cy.wait(1000)`) — use `cy.intercept` aliases or assertion retries instead.
- Use `{ timeout: 10000 }` on specific assertions when elements may take longer to appear.
@elseif($framework === 'playwright')
- Generate TypeScript files (`.spec.ts`). Use proper TypeScript types where helpful.
- Import from `@playwright/test`: `import { test, expect } from '@playwright/test';`
- Use `page.goto()` for navigation.
- Use `page.locator()` for element selection — prefer role-based locators (`page.getByRole()`, `page.getByLabel()`, `page.getByText()`).
- Use `await expect(locator).toBeVisible()` and similar assertions.
- Use `test.describe()` for grouping and `test.beforeEach()` for setup.
- Use `page.waitForResponse()` or `page.waitForURL()` instead of hard-coded timeouts.
- For URL assertions, prefer `await expect(page).toHaveURL(/\/path\/?$/)` (a regex on the path) over asserting the full URL with its query string.
- Use `test.use({ storageState: ... })` for authenticated test contexts.
- All test actions must be awaited.
@endif
