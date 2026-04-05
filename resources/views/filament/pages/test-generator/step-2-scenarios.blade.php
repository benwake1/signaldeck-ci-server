{{-- Step 2: Scenario Selection --}}

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-hidden">
    <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 px-6 py-4">
        <div class="flex items-center gap-3">
            <x-heroicon-o-clipboard-document-check class="h-5 w-5 text-primary-500" />
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Select test scenarios</h2>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="selectAllScenarios" type="button" class="text-xs text-primary-600 dark:text-primary-400 hover:underline">Select all</button>
            <span class="text-gray-300 dark:text-gray-600">|</span>
            <button wire:click="clearAllScenarios" type="button" class="text-xs text-gray-500 dark:text-gray-400 hover:underline">Clear all</button>
        </div>
    </div>

    <div class="px-6 py-6">
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
            Each scenario generates a separate test file covering a distinct ecommerce flow.
            Selected: <strong class="text-gray-900 dark:text-white">{{ count($selectedScenarios) }}</strong> of {{ count($this->getScenarioOptions()) }}
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach($this->getScenarioOptions() as $key => $scenario)
                @php $checked = in_array($key, $selectedScenarios); @endphp
                <button
                    wire:click="toggleScenario('{{ $key }}')"
                    type="button"
                    @class([
                        'flex flex-col items-start p-4 rounded-lg border-2 text-left transition-all w-full',
                        'border-primary-500 bg-primary-50 dark:bg-primary-900/20' => $checked,
                        'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' => !$checked,
                    ])
                >
                    <div class="flex items-start justify-between w-full gap-2">
                        <p class="font-medium text-gray-900 dark:text-white text-sm">{{ $scenario['label'] }}</p>
                        @if($checked)
                            <x-heroicon-s-check-circle class="w-4 h-4 text-primary-500 flex-shrink-0 mt-0.5" />
                        @else
                            <x-heroicon-o-circle-stack class="w-4 h-4 text-gray-300 dark:text-gray-600 flex-shrink-0 mt-0.5" />
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $scenario['description'] }}</p>
                </button>
            @endforeach
        </div>
    </div>
</div>

{{-- Navigation --}}
<div class="flex justify-between mt-6">
    <x-filament::button color="gray" wire:click="previousStep" icon="heroicon-o-arrow-left">
        Back
    </x-filament::button>
    <x-filament::button wire:click="nextStep" icon="heroicon-o-arrow-right" icon-position="after">
        Configure
    </x-filament::button>
</div>
