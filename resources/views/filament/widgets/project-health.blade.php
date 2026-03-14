<x-filament-widgets::widget>
    <x-filament::section heading="Project Health">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @forelse($this->getProjects() as $project)
                @php
                    $latest = $project['latest'];
                    $passRate = $project['pass_rate'];
                    $colour = match(true) {
                        $latest === null => 'gray',
                        $latest['status'] === 'passing' => 'green',
                        $latest['status'] === 'failed' => 'red',
                        in_array($latest['status'], ['running','cloning','installing','pending']) => 'yellow',
                        default => 'gray',
                    };
                @endphp

                <div class="rounded-xl border-2 p-4
                    @if($colour === 'green') border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950
                    @elseif($colour === 'red') border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950
                    @elseif($colour === 'yellow') border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950
                    @else border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900 @endif
                ">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <p class="font-bold text-sm">{{ $project['name'] }}</p>
                            <p class="text-xs text-gray-500">{{ $project['client'] }}</p>
                        </div>
                        <div class="text-right">
                            @if($passRate !== null)
                                <p class="text-lg font-bold
                                    @if($passRate >= 80) text-green-600
                                    @elseif($passRate >= 50) text-yellow-600
                                    @else text-red-600 @endif
                                ">{{ $passRate }}%</p>
                                <p class="text-xs text-gray-400">pass rate</p>
                            @else
                                <p class="text-xs text-gray-400">No runs yet</p>
                            @endif
                        </div>
                    </div>

                    @if($latest)
                        <div class="flex items-center gap-2 mb-3">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium
                                @if($latest['status'] === 'passing') bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300
                                @elseif($latest['status'] === 'failed') bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300
                                @elseif(in_array($latest['status'], ['running','cloning','installing'])) bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300
                                @else bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 @endif
                            ">{{ ucfirst($latest['status']) }}</span>
                            <span class="text-xs text-gray-400">
                                {{ \Carbon\Carbon::parse($latest['created_at'])->diffForHumans() }}
                            </span>
                            @if($latest['failed_tests'] > 0)
                                <span class="text-xs text-red-500">{{ $latest['failed_tests'] }} failed</span>
                            @endif
                        </div>
                    @endif

                    {{-- Quick run buttons for each suite --}}
                    <div class="flex flex-wrap gap-1 mt-2">
                        @foreach($project['suites'] as $suite)
                            <button
                                wire:click="triggerRun({{ $project['id'] }}, {{ $suite->id }})"
                                class="text-xs px-2 py-1 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                            >
                                ▶ {{ $suite->name }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="col-span-3 text-center py-8 text-gray-400">
                    <p>No active projects yet.</p>
                    <a href="{{ route('filament.admin.resources.projects.create') }}" class="text-blue-500 hover:underline text-sm">Create your first project →</a>
                </div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
