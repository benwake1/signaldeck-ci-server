{{-- Step 1: Framework & Platform --}}

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-hidden">
    <div class="flex items-start gap-3 border-b border-gray-200 dark:border-gray-700 px-6 py-5">
        <x-heroicon-o-code-bracket class="h-5 w-5 text-primary-500 mt-0.5 shrink-0" />
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Choose your test framework and platform</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">This generator produces a <strong class="text-gray-700 dark:text-gray-300">starting-point</strong> test suite — you'll need to customise selectors and test data to match your store before running in CI.</p>
        </div>
    </div>

    <div class="px-6 py-6">

        {{-- Framework --}}
        <div>
            <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-4">Test Framework</label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                {{-- Cypress --}}
                <button
                    wire:click="$set('framework', 'cypress')"
                    type="button"
                    @class([
                        'relative flex flex-col gap-1 p-4 rounded-lg border-2 text-left transition-all',
                        'border-primary-500 bg-primary-50 dark:bg-primary-900/20' => $framework === 'cypress',
                        'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' => $framework !== 'cypress',
                    ])
                >
                    <div class="flex items-center justify-between mb-1">
                        <x-heroicon-o-command-line @class([
                            'w-6 h-6',
                            'text-primary-500' => $framework === 'cypress',
                            'text-gray-400 dark:text-gray-500' => $framework !== 'cypress',
                        ]) />
                        @if($framework === 'cypress')
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary-500 shrink-0" />
                        @else
                            <span class="w-4 h-4 shrink-0"></span>
                        @endif
                    </div>
                    <p class="font-semibold text-gray-900 dark:text-white text-sm">Cypress</p>
                    <p class="text-xs font-medium text-primary-600 dark:text-primary-400">JavaScript · .cy.js files</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Real browser testing with hot reload and time-travel debugging.</p>
                </button>

                {{-- Playwright --}}
                <button
                    wire:click="$set('framework', 'playwright')"
                    type="button"
                    @class([
                        'relative flex flex-col gap-1 p-4 rounded-lg border-2 text-left transition-all',
                        'border-primary-500 bg-primary-50 dark:bg-primary-900/20' => $framework === 'playwright',
                        'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' => $framework !== 'playwright',
                    ])
                >
                    <div class="flex items-center justify-between mb-1">
                        <x-heroicon-o-computer-desktop @class([
                            'w-6 h-6',
                            'text-primary-500' => $framework === 'playwright',
                            'text-gray-400 dark:text-gray-500' => $framework !== 'playwright',
                        ]) />
                        @if($framework === 'playwright')
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary-500 shrink-0" />
                        @else
                            <span class="w-4 h-4 shrink-0"></span>
                        @endif
                    </div>
                    <p class="font-semibold text-gray-900 dark:text-white text-sm">Playwright</p>
                    <p class="text-xs font-medium text-primary-600 dark:text-primary-400">TypeScript · .spec.ts files</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Cross-browser parallelism with built-in trace viewer.</p>
                </button>

            </div>
        </div>

        <div class="py-8">
            <div class="border-t border-gray-200 dark:border-gray-700"></div>
        </div>

        {{-- Platform --}}
        <div>
            <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-2">Ecommerce Platform</label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Determines the CSS selectors and DOM patterns used in generated tests. Works with both frameworks above.</p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

                @foreach([
                    ['value' => 'magento-hyva', 'label' => 'Magento 2 (Hyva)',  'desc' => 'Alpine.js · x-data selectors'],
                    ['value' => 'magento-luma', 'label' => 'Magento 2 (Luma)',  'desc' => 'Standard Magento CSS classes'],
                    ['value' => 'generic',       'label' => 'Generic',            'desc' => 'Common ecommerce patterns'],
                ] as $option)
                    <button
                        wire:click="$set('ecommercePlatform', '{{ $option['value'] }}')"
                        type="button"
                        @class([
                            'relative flex flex-col gap-1 p-4 rounded-lg border-2 text-left transition-all',
                            'border-primary-500 bg-primary-50 dark:bg-primary-900/20' => $ecommercePlatform === $option['value'],
                            'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' => $ecommercePlatform !== $option['value'],
                        ])
                    >
                        <div class="flex items-center justify-between">
                            <p class="font-semibold text-gray-900 dark:text-white text-sm">{{ $option['label'] }}</p>
                            @if($ecommercePlatform === $option['value'])
                                <x-heroicon-s-check-circle class="w-4 h-4 text-primary-500 shrink-0" />
                            @else
                                <span class="w-4 h-4 shrink-0"></span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $option['desc'] }}</p>
                    </button>
                @endforeach

            </div>
        </div>

    </div>
</div>

{{-- Navigation --}}
<div class="flex justify-end mt-6">
    <x-filament::button wire:click="nextStep" icon="heroicon-o-arrow-right" icon-position="after">
        Choose Scenarios
    </x-filament::button>
</div>

