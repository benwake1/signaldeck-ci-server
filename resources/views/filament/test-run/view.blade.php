<x-filament-panels::page>
    <style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>
    <div
        x-data="testRunViewer(@js($record->id), @js($record->status), @js($record->isRunning()), @js($record->log_output ?? ''))"
        data-run-status="{{ $record->status }}"
        x-init="init()"
        @if($record->isRunning()) wire:poll.3000ms="pollStatus" @endif
        class="flex flex-col gap-6"
    >

        {{-- Status Bar --}}
        <div class="rounded-xl border p-4 flex items-center justify-between"
             :style="{
                borderColor: status === 'passing'                                         ? '#86efac' :
                             status === 'failed' || status === 'error'                    ? '#fca5a5' :
                             ['pending','cloning','installing','running'].includes(status) ? '#ea580c' :
                             status === 'cancelled'                                        ? '#bfdbfe' : '#e5e7eb',
             }">
            <div class="flex items-center gap-3">
                <div x-show="['pending','cloning','installing','running'].includes(status)">
                    <svg style="animation:spin 1s linear infinite;height:1.25rem;width:1.25rem;color:#ea580c;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity:0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </div>
                <div>
                    <p class="font-semibold text-sm capitalize" x-text="statusLabel"></p>
                    <p class="text-xs text-gray-500" x-text="statusDescription"></p>
                </div>
            </div>
            <div class="flex gap-6 text-sm">
                <div class="text-center">
                    <p class="text-2xl font-bold text-green-600">{{ $record->passed_tests }}</p>
                    <p class="text-xs text-gray-500">Passed</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-red-600">{{ $record->failed_tests }}</p>
                    <p class="text-xs text-gray-500">Failed</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-600">{{ $record->total_tests }}</p>
                    <p class="text-xs text-gray-500">Total</p>
                </div>
                @if($record->duration_ms)
                <div class="text-center">
                    <p class="text-2xl font-bold text-blue-600">{{ $record->duration_formatted }}</p>
                    <p class="text-xs text-gray-500">Duration</p>
                </div>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Live Log --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="bg-gray-900 px-4 py-2 flex items-center justify-between">
                    <span class="text-xs text-gray-400 font-mono">Console Output</span>
                    <div x-show="isRunning" class="flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                        <span class="text-xs text-green-400">Live</span>
                    </div>
                </div>
                <div
                    id="log-container"
                    class="bg-gray-950 p-4 h-96 overflow-y-auto overflow-x-auto font-mono text-xs text-gray-300 whitespace-pre"
                    x-ref="logContainer"
                ><span class="text-gray-500" x-show="!initialLog">Waiting for output...</span></div>
            </div>

            {{-- Test Results --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="bg-white dark:bg-gray-800 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="font-semibold text-sm">Test Results</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700 max-h-96 overflow-y-auto" id="results-container">
                    @forelse($record->testResults->groupBy('spec_file') as $specFile => $results)
                        <div class="p-3">
                            <p class="text-xs font-mono text-gray-500 mb-2">📄 {{ basename($specFile) }}</p>
                            @foreach($results as $result)
                                <div class="flex items-start gap-2 py-1">
                                    <span class="mt-0.5 text-sm">
                                        @if($result->status === 'passed') ✅
                                        @elseif($result->status === 'failed') ❌
                                        @elseif($result->status === 'pending') ⏸️
                                        @else ⏭️
                                        @endif
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs truncate">{{ $result->test_title }}</p>
                                        @if($result->suite_title)
                                            <p class="text-xs text-gray-400">{{ $result->suite_title }}</p>
                                        @endif
                                        @if($result->error_message)
                                            <p class="text-xs text-red-500 mt-1 font-mono bg-red-50 dark:bg-red-950 px-2 py-1 rounded">
                                                {{ Str::limit($result->error_message, 120) }}
                                            </p>
                                        @endif
                                        @if($result->screenshot_urls)
                                            <div class="flex gap-1 mt-1 flex-wrap">
                                                @foreach($result->screenshot_urls as $url)
                                                    <button @click="openMedia('image', '{{ $url }}')" class="focus:outline-none">
                                                        <img src="{{ $url }}" class="h-12 rounded border hover:ring-2 hover:ring-blue-400 transition" alt="Screenshot">
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if($result->video_url)
                                            <button @click="openMedia('video', '{{ $result->video_url }}')" class="mt-1 inline-flex items-center gap-1 text-xs text-blue-500 hover:text-blue-700">
                                                🎬 Watch video
                                            </button>
                                        @endif
                                    </div>
                                    <span class="text-xs text-gray-400 shrink-0">{{ $result->duration_formatted }}</span>
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <div class="p-8 text-center text-gray-400 text-sm">
                            <p x-show="isRunning">Tests are running, results will appear here...</p>
                            <p x-show="!isRunning">No results recorded.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Failed Tests Detail --}}
        @if($record->testResults->where('status', 'failed')->count() > 0)
        <div class="mt-6 rounded-xl border border-red-200 dark:border-red-800 overflow-hidden">
            <div class="bg-red-50 dark:bg-red-950 px-4 py-3 border-b border-red-200 dark:border-red-800">
                <h3 class="font-semibold text-sm text-red-800 dark:text-red-200">
                    ❌ {{ $record->testResults->where('status', 'failed')->count() }} Failed Test(s)
                </h3>
            </div>
            <div class="divide-y divide-red-100 dark:divide-red-900">
                @foreach($record->testResults->where('status', 'failed') as $result)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <p class="font-medium text-sm text-red-800 dark:text-red-200">{{ $result->full_title }}</p>
                                <p class="text-xs text-gray-500 font-mono mt-0.5">{{ $result->spec_file }}</p>
                                @if($result->error_message)
                                    <div class="mt-2 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded p-3">
                                        <p class="text-xs font-mono text-red-700 dark:text-red-300">{{ $result->error_message }}</p>
                                    </div>
                                @endif
                                @if($result->error_stack)
                                    <details class="mt-2">
                                        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Stack trace</summary>
                                        <pre class="mt-1 text-xs font-mono text-gray-500 overflow-x-auto bg-gray-50 dark:bg-gray-900 p-2 rounded">{{ $result->error_stack }}</pre>
                                    </details>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs text-gray-400">{{ $result->duration_formatted }}</p>
                                @if($result->screenshot_urls)
                                    <div class="mt-2 flex gap-1 justify-end flex-wrap">
                                        @foreach($result->screenshot_urls as $url)
                                            <button @click="openMedia('image', '{{ $url }}')" class="focus:outline-none">
                                                <img src="{{ $url }}" class="h-20 rounded border shadow hover:ring-2 hover:ring-blue-400 transition cursor-zoom-in" alt="Failure screenshot">
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                @if($result->video_url)
                                    <button @click="openMedia('video', '{{ $result->video_url }}')"
                                       class="inline-flex items-center gap-1 mt-2 px-2 py-1 rounded bg-blue-50 dark:bg-blue-950 text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-100 transition">
                                        🎬 Watch video
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Run Metadata --}}
        <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800">
                <p class="text-xs text-gray-500">Client</p>
                <p class="font-medium text-sm">{{ $record->project->client->name }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800">
                <p class="text-xs text-gray-500">Triggered By</p>
                <p class="font-medium text-sm">{{ $record->triggeredBy->name }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800">
                <p class="text-xs text-gray-500">Commit SHA</p>
                <p class="font-medium text-sm font-mono">{{ $record->commit_sha ?? '—' }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800">
                <p class="text-xs text-gray-500">Started</p>
                <p class="font-medium text-sm">{{ $record->started_at?->diffForHumans() ?? '—' }}</p>
            </div>
        </div>

        {{-- Lightbox Modal --}}
        <div
            x-show="lightbox.open"
            x-transition.opacity
            x-cloak
            @keydown.escape.window="closeMedia()"
            @click.self="closeMedia()"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
        >
            <div class="relative max-w-5xl w-full max-h-[90vh] flex flex-col">
                <button @click="closeMedia()" class="absolute -top-10 right-0 text-white text-sm hover:text-gray-300 flex items-center gap-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Close
                </button>
                <template x-if="lightbox.type === 'image'">
                    <img :src="lightbox.url" class="rounded-lg max-h-[85vh] object-contain mx-auto shadow-2xl" alt="Screenshot">
                </template>
                <template x-if="lightbox.type === 'video'">
                    <video :src="lightbox.url" controls autoplay class="rounded-lg max-h-[85vh] w-full shadow-2xl bg-black"></video>
                </template>
            </div>
        </div>

    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/ansi_up@6/ansi_up.min.js"></script>
    <script>
        function testRunViewer(runId, initialStatus, initiallyRunning, initialLog) {
            return {
                runId: runId,
                status: initialStatus,
                isRunning: initiallyRunning,
                initialLog: initialLog,

                _ansiUp: null,
                getAnsiUp() {
                    if (!this._ansiUp && typeof AnsiUp !== 'undefined') {
                        const au = new AnsiUp();
                        au.use_classes = false;
                        this._ansiUp = au;
                    }
                    return this._ansiUp;
                },
                ansiToHtml(text) {
                    const au = this.getAnsiUp();
                    // Normalise line endings (\r\n and bare \r → \n)
                    const normalised = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    const html = au
                        ? au.ansi_to_html(normalised)
                        : normalised.replace(/\x1b\[[0-9;]*m/g, '');
                    // Explicit <br> so line breaks survive innerHTML assignment
                    return html.replace(/\n/g, '<br>');
                },

                // Media lightbox
                lightbox: { open: false, type: null, url: null },
                openMedia(type, url) { this.lightbox = { open: true, type, url }; },
                closeMedia() { this.lightbox.open = false; },

                get statusLabel() {
                    const labels = {
                        pending: 'Pending',
                        cloning: 'Cloning repository...',
                        installing: 'Installing dependencies...',
                        running: 'Running tests...',
                        passing: 'All tests passed',
                        failed: 'Tests failed',
                        error: 'Run error',
                        cancelled: 'Cancelled',
                    };
                    return labels[this.status] || this.status;
                },

                get statusDescription() {
                    const desc = {
                        pending: 'Waiting in queue',
                        cloning: 'Fetching repository from git',
                        installing: 'Running npm install',
                        running: 'Cypress is executing tests',
                        passing: 'All tests completed successfully',
                        failed: 'One or more tests failed',
                        error: 'An unexpected error occurred',
                        cancelled: 'Run was cancelled',
                    };
                    return desc[this.status] || '';
                },

                init() {
                    // Render initial log output directly from the JS variable
                    const container = this.$refs.logContainer;
                    if (container && this.initialLog) {
                        container.innerHTML = this.ansiToHtml(this.initialLog);
                        container.scrollTop = container.scrollHeight;
                    }

                    // Sync Alpine status from Livewire pollStatus() dispatches
                    window.addEventListener('run-status-updated', (e) => {
                        const newStatus = e.detail.status;
                        if (newStatus && newStatus !== this.status) {
                            this.status = newStatus;
                            this.isRunning = ['pending','cloning','installing','running'].includes(newStatus);
                            if (!this.isRunning) {
                                setTimeout(() => window.location.reload(), 1500);
                            }
                        }
                    });

                    if (!this.isRunning) return;

                    // Connect to Laravel Reverb via Echo for real-time updates
                    if (typeof window.Echo === 'undefined') return;

                    window.Echo.channel(`test-run.${this.runId}`)
                        .listen('.log.received', (e) => {
                            const container = this.$refs.logContainer;
                            if (container) {
                                container.innerHTML += this.ansiToHtml(e.message) + '<br>';
                                container.scrollTop = container.scrollHeight;
                            }
                        })
                        .listen('.status.changed', (e) => {
                            this.status = e.status;
                            this.isRunning = ['pending','cloning','installing','running'].includes(e.status);

                            if (!this.isRunning) {
                                setTimeout(() => window.location.reload(), 1500);
                            }
                        });
                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
