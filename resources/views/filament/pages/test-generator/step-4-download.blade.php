{{-- Step 4: Preview & Download --}}

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-hidden">
    <div class="flex items-center gap-3 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
        <x-heroicon-o-archive-box-arrow-down class="h-5 w-5 text-primary-500" />
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Review and download</h2>
    </div>

    <div class="px-6 py-6 space-y-6">

        {{-- Summary grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            {{-- Framework & Platform --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Framework & Platform</h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Framework</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $this->getFrameworkLabel() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Platform</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $this->getPlatformLabel() }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Output format</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white font-mono text-xs">
                            {{ $framework === 'cypress' ? '.cy.js' : '.spec.ts' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Configuration --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Configuration</h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">Base URL</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white truncate text-right font-mono text-xs">{{ $baseUrl ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">Test email</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white truncate text-right">{{ $testEmail ?: '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Timeout</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $timeoutSeconds }}s</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Headless</span>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $headless ? 'Yes' : 'No' }}</span>
                    </div>
                    @if($framework === 'playwright')
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Workers / Retries</span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $pwWorkers }} / {{ $pwRetries }}</span>
                        </div>
                    @endif
                </div>
            </div>

        </div>

        {{-- Scenarios --}}
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                Test Scenarios ({{ count($selectedScenarios) }})
            </h3>
            <div class="flex flex-wrap gap-2">
                @foreach($this->getScenarioOptions() as $key => $scenario)
                    @if(in_array($key, $selectedScenarios))
                        <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 dark:bg-primary-900/30 px-3 py-1 text-xs font-medium text-primary-700 dark:text-primary-300">
                            <x-heroicon-s-check-circle class="w-3 h-3" />
                            {{ $scenario['label'] }}
                        </span>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- What's inside the ZIP --}}
        <div class="rounded-lg bg-gray-100 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">What's in the ZIP</h3>
            <div class="font-mono text-xs text-gray-800 dark:text-gray-200 space-y-0.5">
                @if($framework === 'cypress')
                    <p>📦 cypress-tests/</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> cypress.config.js</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> package.json</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> cypress/support/</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> cypress/fixtures/ <span class="text-gray-500 dark:text-gray-400 font-sans">(selectors + credentials)</span></p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">└</span> cypress/e2e/ <span class="text-gray-500 dark:text-gray-400 font-sans">({{ count($selectedScenarios) }} test {{ Str::plural('file', count($selectedScenarios)) }})</span></p>
                @else
                    <p>📦 playwright-tests/</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> playwright.config.ts</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">├</span> package.json</p>
                    <p class="pl-4"><span class="text-gray-400 dark:text-gray-600">└</span> tests/ <span class="text-gray-500 dark:text-gray-400 font-sans">({{ count($selectedScenarios) }} test {{ Str::plural('file', count($selectedScenarios)) }})</span></p>
                @endif
            </div>
        </div>

        {{-- Customisation disclaimer --}}
        <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 px-4 py-3 flex items-start gap-3">
            <x-heroicon-o-wrench-screwdriver class="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
            <div>
                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">These tests will need customisation</p>
                <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">
                    The generated suite is a <strong class="font-semibold">starting point</strong>, not a production-ready test suite. Selectors, product paths, and test data are based on common patterns for your chosen platform and will need to be updated to match your specific store's DOM structure, product catalogue, and checkout flow.
                </p>
            </div>
        </div>

        {{-- Next steps callout --}}
        <div class="rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950 overflow-hidden">

            <div class="px-4 py-3 flex items-center gap-3 border-b border-blue-200 dark:border-blue-800">
                <x-heroicon-o-information-circle class="h-5 w-5 text-blue-500 shrink-0" />
                <p class="text-sm font-medium text-blue-800 dark:text-blue-200">Next steps after downloading</p>
            </div>

            <div class="px-4 py-4 space-y-5">

                {{-- Section 1 --}}
                <div class="mb-2">
                    <p class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">Set up locally</p>
                    <div class="space-y-1.5 text-sm text-blue-700 dark:text-blue-300">
                        <p>1. Unzip and drop the folder into your repo root.</p>
                        <p>2. Run <code class="bg-blue-100 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 px-1.5 py-0.5 rounded font-mono text-xs">npm install</code> inside the test folder.</p>
                        @if($framework === 'playwright')
                            <p>3. Run <code class="bg-blue-100 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 px-1.5 py-0.5 rounded font-mono text-xs">npm run install:browsers</code> to download Playwright's browser binaries.</p>
                        @endif
                        <p>{{ $framework === 'playwright' ? '4' : '3' }}. Update selectors, product URLs, and test data to match your store.</p>
                        <p>{{ $framework === 'playwright' ? '5' : '4' }}. Review any commented-out sections (e.g. guest checkout order placement) before enabling them.</p>
                        <p>{{ $framework === 'playwright' ? '6' : '5' }}. Run <code class="bg-blue-100 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 px-1.5 py-0.5 rounded font-mono text-xs">npm test</code> locally to confirm everything passes.</p>
                    </div>
                </div>

                <div class="border-t border-blue-200 dark:border-blue-800"></div>

                {{-- Section 2 --}}
                <div class="mt-2 mb-2">
                    <div class="flex items-center gap-3 mb-2">
                        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Connect to SignalDeck CI</p>
                        <a href="https://docs.signaldeck.tech/docs/guides/projects-and-suites" target="_blank"
                           class="inline-flex items-center gap-1 rounded border border-blue-300 dark:border-blue-700 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900 transition-colors">
                            View docs ↗
                        </a>
                    </div>
                    <div class="space-y-1.5 text-sm text-blue-700 dark:text-blue-300">
                        <p>1. Go to <strong class="font-semibold text-blue-800 dark:text-blue-200">Projects → New Project</strong> and create a project for your store.</p>
                        <p>2. Under <strong class="font-semibold text-blue-800 dark:text-blue-200">Project Settings → Runner</strong>, set the framework to <strong class="font-semibold text-blue-800 dark:text-blue-200">{{ $framework === 'cypress' ? 'Cypress' : 'Playwright' }}</strong> and the test directory to <code class="bg-blue-100 dark:bg-blue-900 border border-blue-200 dark:border-blue-700 px-1.5 py-0.5 rounded font-mono text-xs">{{ $framework === 'cypress' ? 'cypress/e2e' : 'tests/' }}</code>.</p>
                        <p>3. Add your store URL as the <strong class="font-semibold text-blue-800 dark:text-blue-200">Base URL</strong> in the project configuration.</p>
                        <p>4. Trigger a test run from the dashboard or connect your CI pipeline using the SignalDeck API token shown in project settings.</p>
                        <p>5. Monitor results, screenshots, and failure reports from the <strong class="font-semibold text-blue-800 dark:text-blue-200">Test Runs</strong> view.</p>
                    </div>
                </div>

                <div class="border-t border-blue-200 dark:border-blue-800"></div>

                {{-- Section 2 --}}
                <div class="mt-2">
                    <div class="flex items-center gap-3 mb-2">
                        <p class="text-sm font-semibold text-blue-800 dark:text-blue-200">Docs</p>
                        <a href="https://docs.signaldeck.tech/docs/guides/projects-and-suites" target="_blank"
                           class="inline-flex items-center gap-1 rounded border border-blue-300 dark:border-blue-700 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900 transition-colors">
                            View docs ↗
                        </a>
                    </div>
                    <div class="space-y-1.5 text-sm text-blue-700 dark:text-blue-300">
                        <p>We would strongly encourage you to review the SignalDeck CI docs <a href="https://docs.signaldeck.tech/docs/guides/projects-and-suites" target="_blank" class="inline-flex items-center gap-1 rounded border border-blue-300 dark:border-blue-700 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900 transition-colors">View docs ↗</a></p>
                        <p>In addition to the SignalDeck CI docs, there are also extremely details docs for both Cypress and Playwright.</p>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

{{-- Navigation --}}
<div class="flex justify-between mt-6">
    <x-filament::button color="gray" wire:click="previousStep" icon="heroicon-o-arrow-left">
        Back
    </x-filament::button>
    <x-filament::button
            wire:click="downloadZip"
            wire:loading.attr="disabled"
            wire:target="downloadZip"
            icon="heroicon-o-arrow-down-tray"
            icon-position="after"
    >
        <span wire:loading.remove wire:target="downloadZip">Download ZIP</span>
        <span wire:loading wire:target="downloadZip">Generating...</span>
    </x-filament::button>
</div>