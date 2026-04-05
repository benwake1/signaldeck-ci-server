{{-- Step 3: Configuration --}}

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-hidden">
    <div class="flex items-center gap-3 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
        <x-heroicon-o-cog-6-tooth class="h-5 w-5 text-primary-500" />
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Configure your test suite</h2>
    </div>

    <div class="px-6 py-6 space-y-6">

        {{-- URLs --}}
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">URLs</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                    <label for="baseUrl" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                        Base URL <span class="text-danger-500">*</span>
                    </label>
                    <input
                        id="baseUrl"
                        type="url"
                        wire:model="baseUrl"
                        placeholder="https://your-store.com"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">The storefront URL tests will run against.</p>
                </div>

                <div>
                    <label for="adminUrl" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                        Admin URL <span class="text-xs text-gray-400 dark:text-gray-500">(optional)</span>
                    </label>
                    <input
                        id="adminUrl"
                        type="url"
                        wire:model="adminUrl"
                        placeholder="https://your-store.com/admin"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Used for admin-facing scenarios, if any.</p>
                </div>

            </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800"></div>

        {{-- Credentials --}}
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Test Account Credentials</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">These will be embedded in your test fixtures. Use a dedicated test account — never production credentials.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                    <label for="testEmail" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                        Test Email <span class="text-danger-500">*</span>
                    </label>
                    <input
                        id="testEmail"
                        type="email"
                        wire:model="testEmail"
                        placeholder="test@your-store.com"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                    />
                </div>

                <div>
                    <label for="testPassword" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                        Test Password <span class="text-danger-500">*</span>
                    </label>
                    <input
                        id="testPassword"
                        type="password"
                        wire:model="testPassword"
                        placeholder="Min. 6 characters"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                    />
                </div>

            </div>
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800"></div>

        {{-- Runner Options --}}
        <div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Runner Options</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                <div>
                    <label for="timeoutSeconds" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                        Timeout (seconds)
                    </label>
                    <input
                        id="timeoutSeconds"
                        type="number"
                        wire:model="timeoutSeconds"
                        min="5"
                        max="120"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Default command/assertion timeout.</p>
                </div>

                <div class="flex flex-col justify-between">
                    <label class="block text-sm font-medium text-gray-900 dark:text-white mb-1">Headless Mode</label>
                    <button
                        wire:click="$toggle('headless')"
                        type="button"
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
                            'bg-primary-600' => $headless,
                            'bg-gray-200 dark:bg-gray-700' => !$headless,
                        ])
                    >
                        <span
                            @class([
                                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                'translate-x-5' => $headless,
                                'translate-x-0' => !$headless,
                            ])
                        ></span>
                    </button>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Run without a visible browser window.</p>
                </div>

            </div>
        </div>

        {{-- Playwright-specific options --}}
        @if($framework === 'playwright')
            <div class="border-t border-gray-100 dark:border-gray-800"></div>

            <div>
                <div class="flex items-center gap-2 mb-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Playwright Options</h3>
                    <span class="inline-flex items-center rounded-full bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300">Playwright only</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <div>
                        <label for="pwWorkers" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                            Parallel Workers
                        </label>
                        <input
                            id="pwWorkers"
                            type="number"
                            wire:model="pwWorkers"
                            min="1"
                            max="16"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Number of test workers to run in parallel.</p>
                    </div>

                    <div>
                        <label for="pwRetries" class="block text-sm font-medium text-gray-900 dark:text-white mb-1">
                            Retries on Failure
                        </label>
                        <input
                            id="pwRetries"
                            type="number"
                            wire:model="pwRetries"
                            min="0"
                            max="5"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none transition"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">How many times to retry a flaky test.</p>
                    </div>

                </div>
            </div>
        @endif

    </div>
</div>

{{-- Navigation --}}
<div class="flex justify-between mt-6">
    <x-filament::button color="gray" wire:click="previousStep" icon="heroicon-o-arrow-left">
        Back
    </x-filament::button>
    <x-filament::button wire:click="nextStep" icon="heroicon-o-arrow-right" icon-position="after">
        Preview
    </x-filament::button>
</div>
