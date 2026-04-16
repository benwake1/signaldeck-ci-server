<x-filament-panels::page>

    {{-- Setup Guide --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 mb-6">
        <div class="flex items-center gap-3 border-b border-gray-200 dark:border-gray-700 px-6 py-4">
            <x-heroicon-o-book-open class="h-5 w-5 text-primary-500" />
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">How to set up your Slack App</h2>
        </div>

        <div class="px-6 py-5 space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <p class="font-medium text-gray-900 dark:text-white">SignalDeck can send two types of Slack notification using the same Slack App:</p>
            <ul class="list-disc list-inside space-y-1 mt-1 text-gray-500 dark:text-gray-400">
                <li><strong class="font-medium text-gray-900 dark:text-white">Run completion DMs</strong> — sent to the user who triggered a run when it finishes. Only fires for user-triggered runs; scheduled and webhook runs have no associated user so no DM is sent.</li>
                <li><strong class="font-medium text-gray-900 dark:text-white">Health breach alerts</strong> — posted to a configured channel when a suite's pass rate drops below its threshold. Fires for all run types including scheduled runs. A 1-hour cooldown prevents repeated alerts when multiple consecutive runs fail.</li>
            </ul>
            <p class="mt-3 font-medium text-gray-900 dark:text-white">You'll need to create a <strong>private Slack App</strong> for your own workspace — it takes about 5 minutes.</p>

            <ol class="space-y-4 list-none">
                <li class="flex gap-3">
                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-xs font-bold">1</span>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Create a new Slack App</p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">Go to <a href="https://api.slack.com/apps" target="_blank" class="text-primary-600 dark:text-primary-400 underline hover:no-underline">api.slack.com/apps</a> and click <strong>Create New App → From scratch</strong>. Give it a name (e.g. "SignalDeck CI Testing") and select your workspace.</p>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-xs font-bold">2</span>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Add the required OAuth scopes</p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">Under <strong>OAuth & Permissions → Scopes → Bot Token Scopes</strong>, add:</p>
                        <ul class="mt-2 space-y-1">
                            <li class="flex items-center gap-2">
                                <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono text-gray-800 dark:text-gray-200">chat:write</code>
                                <span class="text-gray-500 dark:text-gray-400">— send DMs to users</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono text-gray-800 dark:text-gray-200">users:read.email</code>
                                <span class="text-gray-500 dark:text-gray-400">— look up users by email address</span>
                            </li>
                        </ul>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-xs font-bold">3</span>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Install the app to your workspace</p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">Still under <strong>OAuth & Permissions</strong>, click <strong>Install to Workspace</strong> and approve the permissions. Once installed, copy the <strong>Bot User OAuth Token</strong> — it starts with <code class="px-1 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs font-mono">xoxb-</code>.</p>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-xs font-bold">4</span>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Copy your Signing Secret</p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">Under <strong>Basic Information → App Credentials</strong>, copy the <strong>Signing Secret</strong> and paste it below.</p>
                    </div>
                </li>

                <li class="flex gap-3">
                    <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 text-xs font-bold">5</span>
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Ensure users have their Slack User ID set</p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">The bot will automatically try to find each user by their email address. If a user's dashboard email doesn't match their Slack account, you can set their Slack User ID manually under <strong>Management → Users → Edit</strong>. To find a Slack User ID, open their profile in Slack → <strong>⋯ → Copy member ID</strong>.</p>
                    </div>
                </li>
            </ol>
        </div>
    </div>

    {{-- Configuration Form --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit">
                Save Slack Settings
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="testConnection"
                wire:loading.attr="disabled"
                wire:target="testConnection"
            >
                <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                <span wire:loading wire:target="testConnection">Testing…</span>
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
