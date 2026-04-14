<x-filament-panels::page>
    <style>
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes skeletonPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .log-line { animation: fadeIn 0.35s ease forwards; font-size: 0.75rem; line-height: 1.6; white-space: pre; }
        .log-green  { color: #4ade80; }
        .log-cyan   { color: #67e8f9; }
        .log-yellow { color: #fbbf24; }
        .log-dim    { color: #6b7280; }
    </style>
    <div
        x-data="testRunViewer(@js($record->id), @js($record->status), @js($record->isRunning()), @js($record->log_output ?? ''))"
        data-run-status="{{ $record->status }}"
        x-init="init()"
        @if($record->isRunning()) wire:poll.3000ms="pollStatus" @endif
        class="flex flex-col gap-6"
    >

        {{-- Partial Re-run Callout --}}
        @if($record->spec_override)
        <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950 px-4 py-3 flex items-start gap-3">
            <span class="text-amber-500 mt-0.5">⚡</span>
            <div>
                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Partial re-run — failed specs only</p>
                <p class="text-xs text-amber-600 dark:text-amber-400 font-mono mt-0.5">{{ $record->spec_override }}</p>
                @if($record->parent_run_id)
                    <a href="{{ \App\Filament\Resources\TestRunResource::getUrl('view', ['record' => $record->parent_run_id]) }}" class="text-xs text-amber-700 dark:text-amber-300 underline hover:no-underline mt-1 inline-block">
                        ← View original run #{{ $record->parent_run_id }}
                    </a>
                @endif
            </div>
        </div>
        @endif

        {{-- Status Bar --}}
        <div class="sd-block-transparent rounded-xl border p-4 flex items-center justify-between"
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
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden sd-block">
                <div class="bg-gray-900 px-4 py-2 flex items-center justify-between sd-block">
                    <span class="text-xs text-gray-400 font-mono">Console Output</span>
                    <div x-show="isRunning" class="flex items-center gap-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                        <span class="text-xs text-green-400 sd-block-green">Live</span>
                    </div>
                </div>
                <div
                    id="log-container"
                    class="bg-gray-950 p-4 h-96 overflow-y-auto overflow-x-auto font-mono text-xs text-gray-300 whitespace-pre"
                >
                    <span class="text-gray-500" x-show="!hasLog && !isRunning">Waiting for output...</span>

                    {{-- Skeleton placeholder — hidden once real log data arrives --}}
                    <div x-show="!hasLog && isRunning" class="py-1" x-data="{
                        phases: [
                            { text: '  Cloning repository...', color: 'log-cyan', delay: 400 },
                            { text: '  npm install running...', color: 'log-cyan', delay: 1200 },
                            { text: '  Dependencies resolved.', color: 'log-green', delay: 2800 },
                            { text: '', color: 'log-dim', delay: 3200 },
                            { text: '  Starting {{ $record->runner_type?->label() ?? "Cypress" }}...', color: 'log-cyan', delay: 3600 },
                            { text: '  Preparing test environment...', color: 'log-dim', delay: 4400 },
                            { text: '', color: 'log-dim', delay: 4800 },
                            { text: '  Found spec files:', color: 'log-cyan', delay: 5200 },
                        ],
                        shown: [],
                        spinnerFrame: 0,
                        spinnerChars: ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'],
                        specNames: ['auth/login.spec.ts','dashboard/overview.spec.ts','checkout/payment.spec.ts','settings/profile.spec.ts','api/endpoints.spec.ts'],
                        currentSpec: 0,
                        init() {
                            this.phases.forEach(p => {
                                setTimeout(() => { this.shown.push(p); this.$nextTick(() => { const c = this.$el.closest('#log-container'); if(c) c.scrollTop = c.scrollHeight; }); }, p.delay);
                            });
                            setInterval(() => { this.spinnerFrame = (this.spinnerFrame + 1) % 10; }, 80);
                            setInterval(() => { this.currentSpec = (this.currentSpec + 1) % this.specNames.length; }, 3500);
                        }
                    }">
                        <template x-for="(p, i) in shown" :key="i">
                            <div class="log-line" :class="p.color" x-text="p.text"></div>
                        </template>
                        <div x-show="shown.length >= 8" class="mt-2 space-y-1">
                            <div class="log-dim">  ────────────────────────────────────────────</div>
                            <div class="flex items-center gap-2">
                                <span class="text-yellow-400" x-text="spinnerChars[spinnerFrame]"></span>
                                <span class="log-yellow">Running: </span><span class="log-dim font-mono" x-text="specNames[currentSpec]"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Real log output — shown once data arrives --}}
                    <div x-show="hasLog" x-html="logHtml"></div>
                </div>
            </div>

            {{-- Test Results --}}
            <div class="rounded-xl border border-dark-900 overflow-hidden sd-block">
                <div class="bg-white dark:bg-gray-800 px-4 py-3 border-b border-blue-200 dark:border-gray-700 fi-border-title sd-block">
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
                                        @if($result->status === 'failed')
                                            <a href="{{ \App\Filament\Pages\TestHistory::getUrl(['project' => $record->project_id, 'spec' => urlencode($result->spec_file), 'title' => urlencode($result->full_title)]) }}" class="inline-flex items-center gap-1 mt-1 px-2.5 py-0.5 rounded-full !bg-blue-600 !text-white text-xs font-semibold hover:!bg-blue-700 transition shadow-sm">📈 History</a>
                                        @endif
                                        @if($result->error_message)
                                            <p class="text-xs text-red-500 mt-1 font-mono bg-red-50 dark:bg-red-950 px-2 py-1 rounded">
                                                {{ Str::limit($result->error_message, 120) }}
                                            </p>
                                        @endif
                                        @if($result->screenshot_urls)
                                            <div class="flex gap-1 mt-1 flex-wrap">
                                                @foreach($result->screenshot_urls as $url)
                                                    <button type="button" data-lightbox-type="image" data-lightbox-url="{{ $url }}" onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)" class="focus:outline-none">
                                                        <img src="{{ $url }}" class="h-10 rounded border hover:ring-2 hover:ring-blue-400 transition cursor-zoom-in" alt="Screenshot">
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if($result->video_url)
                                            <button type="button" data-lightbox-type="video" data-lightbox-url="{{ $result->video_url }}" onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)" class="mt-1 inline-flex items-center gap-1 text-xs text-blue-500 hover:text-blue-700">
                                                🎬 Watch video
                                            </button>
                                        @endif
                                    </div>
                                    <span class="text-xs text-gray-400 shrink-0">{{ $result->duration_formatted }}</span>
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <div class="p-4">
                            <div x-show="isRunning" style="border-top:1px solid rgba(255,255,255,0.06)">
                                @php $skeletonWidths = [[75,35],[52,28],[83,42],[64,30],[58,25],[79,38],[48,32]]; @endphp
                                @foreach($skeletonWidths as $idx => $widths)
                                <div style="display:flex;align-items:center;gap:12px;padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.06)">
                                    <div style="width:20px;height:20px;border-radius:9999px;background:#4b5563;flex-shrink:0;animation:skeletonPulse 1.4s ease-in-out {{ $idx * 100 }}ms infinite"></div>
                                    <div style="flex:1;display:flex;flex-direction:column;gap:6px">
                                        <div style="width:{{ $widths[0] }}%;height:10px;border-radius:9999px;background:#4b5563;animation:skeletonPulse 1.4s ease-in-out {{ $idx * 100 + 50 }}ms infinite"></div>
                                        <div style="width:{{ $widths[1] }}%;height:7px;border-radius:9999px;background:#374151;animation:skeletonPulse 1.4s ease-in-out {{ $idx * 100 + 80 }}ms infinite"></div>
                                    </div>
                                    <div style="width:44px;height:10px;border-radius:9999px;background:#4b5563;flex-shrink:0;animation:skeletonPulse 1.4s ease-in-out {{ $idx * 100 + 60 }}ms infinite"></div>
                                </div>
                                @endforeach
                            </div>
                            <p x-show="!isRunning" class="text-center text-gray-400 text-sm py-4">No results recorded.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Failed Tests Detail --}}
        @if($record->testResults->where('status', 'failed')->count() > 0)
        <div class="mt-6 rounded-xl border border-red-200 dark:border-red-800 overflow-hidden sd-block-transparent">
            <div class="bg-red-50 dark:bg-red-950 px-4 py-3 border-b border-red-200 dark:border-red-800 sd-block-transparent">
                <h3 class="font-semibold text-sm text-red-800 dark:text-red-200">
                    ❌ {{ $record->testResults->where('status', 'failed')->count() }} Failed Test(s)
                </h3>
            </div>
            <div class="divide-y divide-red-100 dark:divide-red-900">
                @foreach($record->testResults->where('status', 'failed') as $result)
                    <div class="p-4">
                        {{-- Title row --}}
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-medium text-sm text-red-800 dark:text-red-200 truncate">{{ $result->full_title }}</p>
                                    @if(isset($flakyTestTitles) && in_array($result->full_title, $flakyTestTitles))
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700 shrink-0">⚠ Flaky</span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-500 font-mono truncate mt-0.5">{{ $result->spec_file }}</p>
                                <div class="mt-2">
                                    <a href="{{ \App\Filament\Pages\TestHistory::getUrl(['project' => $record->project_id, 'spec' => urlencode($result->spec_file), 'title' => urlencode($result->full_title)]) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold !bg-blue-600 !text-white border border-blue-700 hover:!bg-blue-700 transition shadow-sm">
                                        📈 View History
                                    </a>
                                </div>
                            </div>
                            <p class="text-xs text-gray-400 shrink-0">{{ $result->duration_formatted }}</p>
                        </div>

                        {{-- Error message --}}
                        @if($result->error_message)
                            <div class="mt-2 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded p-3">
                                <p class="text-xs font-mono text-red-700 dark:text-red-300">{{ $result->error_message }}</p>
                            </div>
                        @endif

                        {{-- Stack trace --}}
                        @if($result->error_stack)
                            <details class="mt-2">
                                <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600 dark:hover:text-gray-300">Stack trace</summary>
                                <pre class="mt-1 text-xs font-mono text-gray-500 overflow-x-auto bg-gray-50 dark:bg-gray-900 p-2 rounded">{{ $result->error_stack }}</pre>
                            </details>
                        @endif

                        {{-- Screenshots & video inline --}}
                        @if($result->screenshot_urls || $result->video_url)
                            <div class="mt-2 flex items-center gap-2 flex-wrap">
                                @if($result->screenshot_urls)
                                    @foreach($result->screenshot_urls as $url)
                                        <button type="button" data-lightbox-type="image" data-lightbox-url="{{ $url }}" onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)" class="focus:outline-none group">
                                            <img src="{{ $url }}" class="h-10 rounded border group-hover:ring-2 group-hover:ring-blue-400 transition cursor-zoom-in" alt="Failure screenshot">
                                        </button>
                                    @endforeach
                                @endif
                                @if($result->video_url)
                                    <button type="button" data-lightbox-type="video" data-lightbox-url="{{ $result->video_url }}" onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)"
                                       class="inline-flex items-center gap-1 px-2 py-1 rounded bg-blue-50 dark:bg-blue-950 text-xs text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900 transition">
                                        🎬 Watch video
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Run Metadata --}}
        <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800 sd-block">
                <p class="text-xs text-gray-500">Client</p>
                <p class="font-medium text-sm">{{ $record->project->client->name }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800 sd-block">
                <p class="text-xs text-gray-500">Triggered By</p>
                <p class="font-medium text-sm">{{ $record->triggeredBy?->name ?? $record->trigger_source?->label() ?? '—' }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800 sd-block">
                <p class="text-xs text-gray-500">Commit SHA</p>
                <p class="font-medium text-sm font-mono">{{ $record->commit_sha ?? '—' }}</p>
            </div>
            <div class="rounded-lg border p-3 bg-white dark:bg-gray-800 sd-block">
                <p class="text-xs text-gray-500">Started</p>
                <p class="font-medium text-sm">{{ $record->started_at?->diffForHumans() ?? '—' }}</p>
            </div>
        </div>

        {{-- Lightbox is injected into document.body via JS to escape Filament's CSS transform context --}}

    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/ansi_up@6/ansi_up.min.js"></script>
    <script>
        // Lightbox appended to body so it escapes any CSS transform/stacking context in Filament
        function openLightbox(type, url) {
            closeLightbox(); // remove any existing instance

            const overlay = document.createElement('div');
            overlay.id = 'run-lightbox';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.88);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1.5rem;';
            overlay.addEventListener('click', (e) => { if (e.target === overlay) closeLightbox(); });

            const inner = document.createElement('div');
            inner.style.cssText = 'position:relative;max-width:90vw;max-height:90vh;';

            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&#x2715;';
            closeBtn.style.cssText = 'position:absolute;top:-2.5rem;right:0;background:none;border:none;color:white;font-size:2rem;cursor:pointer;opacity:0.8;line-height:1;padding:0;';
            closeBtn.addEventListener('mouseenter', () => closeBtn.style.opacity = '1');
            closeBtn.addEventListener('mouseleave', () => closeBtn.style.opacity = '0.8');
            closeBtn.addEventListener('click', closeLightbox);

            let media;
            if (type === 'image') {
                media = document.createElement('img');
                media.src = url;
                media.alt = 'Screenshot';
                media.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 25px 50px rgba(0,0,0,.6);display:block;background:#000;';
            } else {
                media = document.createElement('video');
                media.src = url;
                media.controls = true;
                media.autoplay = true;
                media.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 25px 50px rgba(0,0,0,.6);display:block;background:#000;width:100%;';
            }

            inner.appendChild(closeBtn);
            inner.appendChild(media);
            overlay.appendChild(inner);
            document.body.appendChild(overlay);
        }

        function closeLightbox() {
            const lb = document.getElementById('run-lightbox');
            if (!lb) return;
            const video = lb.querySelector('video');
            if (video) video.pause();
            lb.remove();
        }

        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLightbox(); });
    </script>
    <script>
        function testRunViewer(runId, initialStatus, initiallyRunning, initialLog) {
            return {
                runId: runId,
                status: initialStatus,
                isRunning: initiallyRunning,
                initialLog: initialLog,
                hasLog: !!initialLog,
                logHtml: '',
                _lastLogLength: 0,

                _ansiUp: null,
                getAnsiUp() {
                    if (!this._ansiUp && typeof AnsiUp !== 'undefined') {
                        const au = new AnsiUp();
                        au.use_classes = false;
                        this._ansiUp = au;
                    }
                    return this._ansiUp;
                },
                escapeHtml(text) {
                    return text
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                },
                ansiToHtml(text) {
                    const au = this.getAnsiUp();
                    // Normalise line endings (\r\n and bare \r → \n)
                    const normalised = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    // AnsiUp v5+ HTML-escapes content internally (safe).
                    // Fallback: manually escape to prevent XSS before stripping ANSI codes.
                    const html = au
                        ? au.ansi_to_html(normalised)
                        : this.escapeHtml(normalised).replace(/\x1b\[[0-9;]*m/g, '');
                    // Explicit <br> so line breaks survive innerHTML assignment
                    return html.replace(/\n/g, '<br>');
                },

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
                        running: 'Executing tests...',
                        passing: 'All tests completed successfully',
                        failed: 'One or more tests failed',
                        error: 'An unexpected error occurred',
                        cancelled: 'Run was cancelled',
                    };
                    return desc[this.status] || '';
                },

                updateLog(log) {
                    if (!log || !log.trim() || log.length <= this._lastLogLength) return;
                    this.hasLog = true;
                    this._lastLogLength = log.length;
                    this.logHtml = this.ansiToHtml(log);
                    this.$nextTick(() => {
                        const c = document.getElementById('log-container');
                        if (c) c.scrollTop = c.scrollHeight;
                    });
                },

                init() {
                    // Render initial log output (e.g. viewing a completed run)
                    if (this.initialLog) {
                        this.updateLog(this.initialLog);
                    }

                    // Sync status from Livewire poll
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

                    // Update console from Livewire poll (works without WebSocket)
                    window.addEventListener('log-updated', (e) => {
                        this.updateLog(e.detail.log);
                    });

                }
            }
        }
    </script>
    @endpush
</x-filament-panels::page>
