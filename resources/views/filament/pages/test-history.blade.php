<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $project = $this->getProject();
    @endphp

    @if(!$this->fullTitle)
        <div class="sd-block rounded-xl p-12 text-center">
            <p class="text-gray-500">No test specified. Navigate here from a test run's failed tests section.</p>
        </div>
    @else
        {{-- Header info --}}
        <div class="sd-block rounded-xl p-4 mb-2">
            <p class="text-xs text-gray-500 font-mono">{{ $this->specFile }}</p>
            @if($project)
                <p class="text-xs text-blue-600 dark:text-blue-400 mt-1">{{ $project->name }}</p>
            @endif
        </div>

        @if(empty($summary))
            <div class="sd-block rounded-xl p-12 text-center">
                <p class="text-gray-500">No history found for this test.</p>
            </div>
        @else
            {{-- Summary Stats --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 mb-4">
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold dark:text-white">{{ $summary['total'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Runs</p>
                </div>
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $summary['pass_rate'] }}%</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Pass Rate</p>
                </div>
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $summary['passed'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Passed</p>
                </div>
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $summary['failed'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Failed</p>
                </div>
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold dark:text-gray-200">{{ $summary['avg_ms'] < 1000 ? $summary['avg_ms'].'ms' : round($summary['avg_ms']/1000, 1).'s' }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Avg Duration</p>
                </div>
                <div class="sd-block rounded-lg p-3 text-center">
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $summary['max_streak'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Longest Fail Streak</p>
                </div>
            </div>

            {{-- Filament table — dark mode handled natively --}}
            {{ $this->table }}
        @endif
    @endif
</x-filament-panels::page>
