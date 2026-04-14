<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Test Report — {{ $project->name }} — {{ $generatedAt->format('d M Y') }}</title>

    {{-- Dynamic CSS variables driven by client branding (PHP-side, cannot be Tailwind) --}}
    <style>
        @php
            // Sanitise colours to valid hex only — prevents CSS injection
            $safeColour = fn($c) => preg_match('/^#[0-9A-Fa-f]{3,8}$/', trim($c)) ? trim($c) : '#1e40af';
        @endphp
        :root {
            --primary:   {{ $safeColour($client->primary_colour) }};
            --secondary: {{ $safeColour($client->secondary_colour) }};
            --accent:    {{ $safeColour($client->accent_colour) }};
        }

        /* Dynamic helpers that reference CSS variables */
        .grad { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); }
        .print-btn-bg { background-color: var(--primary); }
        .screenshot-img:hover { box-shadow: 0 0 0 3px var(--primary); }
        .custom-border {border-color: var(--primary); }

        /* Decorative header orb (pseudo-element can't be done with Tailwind) */
        .report-header::after {
            content: '';
            position: absolute;
            right: -60px;
            top: -60px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        /* Monospace font stack for error output */
        .font-code {
            font-family: 'Cascadia Code', 'Fira Code', 'Courier New', monospace;
        }

        /* iOS safe area: html background fallback for notch/home bar colour */
        html {
            background: var(--primary);
        }
    </style>

    {{-- Compiled Tailwind CSS — injected inline at render time from the Vite manifest --}}
    <style>{!! $reportCss !!}</style>

    {{-- Safe area overrides — must come after Tailwind to win the cascade --}}
    <style>
        .report-header {
            padding-top: calc(2rem + env(safe-area-inset-top, 0px));
        }
        @media (min-width: 768px) {
            .report-header { padding-top: calc(2.5rem + env(safe-area-inset-top, 0px)); }
        }
        .report-footer {
            padding-bottom: calc(2rem + env(safe-area-inset-bottom, 0px));
        }
        @media (min-width: 768px) {
            .report-footer { padding-bottom: calc(2.5rem + env(safe-area-inset-bottom, 0px)); }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 leading-relaxed"
      style="font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; font-size: 14px;">

    {{-- ===== HEADER ===== --}}
    <div class="report-header grad text-white relative overflow-hidden px-6 py-8 md:px-12 md:py-10 print-exact">

        {{-- Logo row + meta --}}
        <div class="flex flex-col gap-4 mb-8 md:flex-row md:justify-between md:items-start max-w-7xl mx-auto">

            {{-- Logos --}}
            <div class="flex items-center gap-5">
                <div class="text-lg font-bold tracking-tight">{{ config('brand.name') ?: config('app.name') }} QA Report</div>
                <div class="w-px h-10 bg-white/30 shrink-0"></div>
                @if($client->logo_path)
                    <img src="{{ Storage::url($client->logo_path) }}" alt="{{ $client->name }}"
                         class="h-20 w-auto object-contain bg-white rounded-lg px-3 py-2">
                @else
                    <div class="text-lg font-bold opacity-90">{{ $client->name }}</div>
                @endif
            </div>

            {{-- Report meta --}}
            <div class="text-xs opacity-85 leading-relaxed md:text-right">
                <div><strong>Report Date:</strong> {{ $generatedAt->format('d F Y') }}</div>
                <div><strong>Generated:</strong> {{ $generatedAt->format('H:i') }}</div>
                <div><strong>Run ID:</strong> #{{ $run->id }}</div>
                @if($run->commit_sha)
                    <div><strong>Commit:</strong> <span class="font-code">{{ $run->commit_sha }}</span></div>
                @endif
                <div><strong>Powered By:</strong> {{ $run->runner_type?->label() ?? 'Cypress' }}</div>
            </div>
        </div>

        <div class="text-3xl font-extrabold tracking-tight mb-2 md:text-4xl max-w-7xl mx-auto">{{ $project->name }}</div>
        <div class="text-base opacity-80 max-w-7xl mx-auto">
            Test Suite: {{ $suite->name }} &nbsp;·&nbsp;
            Branch: {{ $run->branch }} &nbsp;·&nbsp;
            Triggered by: {{ $run->triggeredBy->name }}
        </div>
    </div>

    {{-- ===== SUMMARY ===== --}}
    <div class="bg-white border-b border-gray-200 px-6 py-8 md:px-12 max-w-7xl mx-auto">

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
            <h2 class="text-base font-bold text-gray-700">Executive Summary</h2>
            <span class="inline-flex items-center gap-1 self-start px-3 py-1 rounded-full text-xs font-semibold
                {{ $run->status === 'passing' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                {{ $run->status === 'passing' ? '✅ All Tests Passed' : '❌ Tests Failed' }}
            </span>
        </div>

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 gap-4 mt-5 md:grid-cols-4">
            <div class="rounded-xl p-5 text-center border border-green-200 bg-green-50 print-exact">
                <div class="text-4xl font-extrabold leading-none mb-1.5 text-green-600">{{ $run->passed_tests }}</div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Passed</div>
            </div>
            <div class="rounded-xl p-5 text-center border border-red-200 bg-red-50 print-exact">
                <div class="text-4xl font-extrabold leading-none mb-1.5 text-red-600">{{ $run->failed_tests }}</div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Failed</div>
            </div>
            <div class="rounded-xl p-5 text-center border border-blue-200 bg-blue-50 print-exact">
                <div class="text-4xl font-extrabold leading-none mb-1.5 text-blue-600">{{ $run->total_tests }}</div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Total</div>
            </div>
            <div class="rounded-xl p-5 text-center border border-gray-200 bg-gray-50  {{ $run->pass_rate >= 80 ? 'bg-green-200 border-green-200' : ($run->pass_rate >= 60 ? 'bg-yellow-200 border-yellow-200' : 'bg-red-200 border-red-200') }} print-exact">
                <div class="text-4xl font-extrabold leading-none mb-1.5
                    {{ $run->pass_rate >= 80 ? 'text-green-600' : ($run->pass_rate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ $run->pass_rate }}%
                </div>
                <div class="text-xs font-semibold uppercase tracking-widest text-gray-500">Pass Rate</div>
            </div>
        </div>

        {{-- Pass rate progress bar --}}
        <div class="mt-6">
            <div class="flex justify-between items-center mb-2 text-xs">
                <span class="font-semibold text-gray-700">Overall Pass Rate</span>
                <span class="font-bold text-lg {{ $run->pass_rate >= 80 ? 'text-green-600' : ($run->pass_rate >= 60 ? 'text-yellow-600' : 'text-red-600') }}">
                    {{ $run->pass_rate }}%
                </span>
            </div>
            <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all duration-700"
                     style="width: {{ $run->pass_rate }}%; background: linear-gradient(90deg,
                        {{ $run->pass_rate >= 80 ? '#16a34a, #4ade80' : ($run->pass_rate >= 60 ? '#d97706, #fbbf24' : '#dc2626, #f87171') }}
                     );"></div>
            </div>
        </div>

        @if($run->duration_ms)
            <div class="mt-4 flex flex-wrap gap-4 text-xs text-gray-500">
                <span>⏱ Duration: <strong class="text-gray-700">{{ $run->duration_formatted }}</strong></span>
                <span>📅 Run Date: <strong class="text-gray-700">{{ $run->started_at?->format('d M Y H:i') ?? $run->created_at->format('d M Y H:i') }}</strong></span>
                <span>🌿 Branch: <strong class="text-gray-700">{{ $run->branch }}</strong></span>
            </div>
        @endif
    </div>

    {{-- ===== FAILED TESTS ===== --}}
    @if($failedResults->count() > 0)
    <div class="bg-white border-b border-gray-200 px-6 py-8 md:px-12 max-w-7xl mx-auto">

        <div class="flex items-center gap-2.5 mb-5 pb-3 border-b-2 border-gray-200">
            <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center text-base shrink-0">❌</div>
            <span class="text-lg font-bold text-red-700">Failed Tests</span>
            <span class="ml-auto bg-gray-50 border border-gray-200 rounded-full px-2.5 py-0.5 text-xs text-gray-500 font-semibold">
                {{ $failedResults->count() }}
            </span>
        </div>

        @foreach($failedResults->groupBy('spec_file') as $specFile => $results)
            <div class="mb-4 border border-gray-200 rounded-xl overflow-hidden break-inside-avoid">

                <div class="bg-gray-50 px-4 py-2.5 flex items-center gap-2 border-b border-gray-200">
                    <span class="font-code text-xs text-gray-500 flex-1 min-w-0 truncate">📄 {{ $specFile }}</span>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700 shrink-0">
                        {{ $results->count() }} failed
                    </span>
                </div>

                @foreach($results as $result)
                    <div class="flex items-start gap-3 px-4 py-3 border-b border-gray-100 last:border-b-0 bg-red-50/30">
                        <div class="text-base mt-0.5 shrink-0">❌</div>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm">{{ $result->test_title }}</div>
                            @if($result->suite_title)
                                <div class="text-xs text-gray-500 mt-0.5">{{ $result->suite_title }}</div>
                            @endif

                            @if($result->error_message)
                                <div class="mt-2 bg-red-50 border border-red-200 rounded-md px-3 py-2.5">
                                    <div class="font-code text-xs text-red-800 whitespace-pre-wrap break-words">{{ $result->error_message }}</div>
                                    @if($result->error_stack)
                                        <div class="mt-1.5 font-code text-[10px] text-red-700 opacity-70 whitespace-pre-wrap break-words max-h-24 overflow-hidden">{{ Str::limit($result->error_stack, 400) }}</div>
                                    @endif
                                </div>
                            @endif

                            @if($result->screenshotProxyUrls() || $result->videoProxyUrl())
                                <div class="flex gap-2 mt-2 flex-wrap items-start print:hidden">
                                    @foreach($result->screenshotProxyUrls() as $url)
                                        <img src="{{ $url }}"
                                             class="screenshot-img h-20 rounded-md border border-gray-200 cursor-zoom-in object-cover transition-shadow"
                                             alt="Failure screenshot"
                                             data-lightbox-type="image"
                                             data-lightbox-url="{{ $url }}"
                                             onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)">
                                    @endforeach
                                    @if($result->videoProxyUrl())
                                        <button class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-md text-xs font-semibold text-blue-700 cursor-pointer hover:bg-blue-100 transition-colors"
                                                data-lightbox-type="video"
                                                data-lightbox-url="{{ $result->videoProxyUrl() }}"
                                                onclick="openLightbox(this.dataset.lightboxType, this.dataset.lightboxUrl)">
                                            🎬 Watch video
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 shrink-0 pt-0.5">{{ $result->duration_formatted }}</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    @endif

    {{-- ===== ALL RESULTS BY SPEC ===== --}}
    <div class="bg-white border-b border-gray-200 px-6 py-8 md:px-12 max-w-7xl mx-auto">

        <div class="flex items-center gap-2.5 mb-5 pb-3 border-b-2 border-gray-200">
            <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center text-base shrink-0">📋</div>
            <span class="text-lg font-bold">Full Test Results</span>
            <span class="ml-auto bg-gray-50 border border-gray-200 rounded-full px-2.5 py-0.5 text-xs text-gray-500 font-semibold">
                {{ $run->total_tests }} tests &nbsp;·&nbsp; {{ $resultsBySpec->count() }} spec(s)
            </span>
        </div>

        @forelse($resultsBySpec as $specFile => $results)
            @php
                $specPassed = $results->where('status', 'passed')->count();
                $specFailed = $results->where('status', 'failed')->count();
                $specTotal  = $results->count();
                $specBadge  = $specFailed > 0 && $specPassed > 0 ? 'mixed' : ($specFailed > 0 ? 'fail' : 'pass');
            @endphp

            <div class="mb-4 border border-gray-200 rounded-xl overflow-hidden break-inside-avoid">

                <div class="bg-gray-50 px-4 py-2.5 flex items-center gap-2 border-b border-gray-200">
                    <span class="font-code text-xs text-gray-500 flex-1 min-w-0 truncate">📄 {{ $specFile }}</span>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full shrink-0
                        @if($specBadge === 'pass') bg-green-100 text-green-700
                        @elseif($specBadge === 'fail') bg-red-100 text-red-700
                        @else bg-yellow-100 text-yellow-800 @endif">
                        {{ $specPassed }}/{{ $specTotal }} passed
                    </span>
                </div>

                @foreach($results as $result)
                    <div class="flex items-start gap-3 px-4 py-3 border-b border-gray-100 last:border-b-0
                        {{ $result->status === 'failed' ? 'bg-red-50/20' : '' }}">
                        <div class="text-base mt-0.5 shrink-0">
                            @if($result->status === 'passed') ✅
                            @elseif($result->status === 'failed') ❌
                            @elseif($result->status === 'pending') ⏸️
                            @else ⏭️
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm">{{ $result->test_title }}</div>
                            @if($result->suite_title)
                                <div class="text-xs text-gray-500 mt-0.5">{{ $result->suite_title }}</div>
                            @endif
                            @if($result->error_message && $result->status === 'failed')
                                <div class="mt-2 bg-red-50 border border-red-200 rounded-md px-3 py-2.5">
                                    <div class="font-code text-xs text-red-800 whitespace-pre-wrap break-words">{{ Str::limit($result->error_message, 200) }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 shrink-0 pt-0.5">{{ $result->duration_formatted }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="text-center py-10 text-gray-500">
                <div class="text-5xl mb-3">🔍</div>
                <p>No test results recorded for this run.</p>
            </div>
        @endforelse
    </div>

    {{-- ===== FOOTER ===== --}}
    <div class="report-footer grad text-white relative overflow-hidden px-6 py-8 md:px-12 md:py-10 print-exact">
        <div class="flex flex-col py-6 gap-3 md:flex-row md:justify-between md:items-center md:px-12 max-w-7xl mx-auto">
            <div class="opacity-85 leading-relaxed">
                @if($client->report_footer_text)
                    <p>{{ $client->report_footer_text }}</p>
                @else
                    <p>This report was automatically generated by QA Dashboard.</p>
                    <p>For questions about this report, please contact your account manager.</p>
                @endif
            </div>
            <div class="opacity-85 leading-relaxed md:text-right">
                <p><strong>{{ $client->name }}</strong></p>
                @if($client->contact_email)
                    <p>{{ $client->contact_email }}</p>
                @endif
                <p>Generated {{ $generatedAt->format('d M Y \a\t H:i') }}</p>
            </div>
        </div>
        <div class="flex flex-col gap-3 py-6 md:items-center md:px-12 max-w-7xl mx-auto border-t custom-border">
            <div class="opacity-85 leading-relaxed md:text-center">
                <p>This report was automatically generated by {{ config('brand.name') ?: config('app.name') }} QA Report.</p>
                <p>For questions about this report, please contact your project manager.</p>
                <p>&copy; {{ date('Y') }} - All Rights Reserved - {{ config('brand.legal_name') ?: config('brand.name') }}</p>
            </div>
        </div>
    </div>


    {{-- ===== PRINT BUTTON (hidden on print) ===== --}}
    <button onclick="window.print()"
            class="print-btn-bg print:hidden hidden md:block md:fixed bottom-8 right-8 text-white border-none rounded-full px-6 py-3.5 text-sm font-bold cursor-pointer shadow-lg flex items-center gap-2 z-50 hover:-translate-y-0.5 hover:shadow-xl transition-all">
        🖨️ Save as PDF
    </button>

    {{-- ===== LIGHTBOX (hidden on print) ===== --}}
    <div id="lightbox"
         class="hidden fixed inset-0 bg-black/85 z-[9999] items-center justify-center p-6 print:hidden"
         onclick="if(event.target===this)closeLightbox()">
        <div class="relative max-w-[90vw] max-h-[90vh]">
            <button onclick="closeLightbox()"
                    class="absolute -top-9 right-0 bg-transparent border-none text-white text-3xl cursor-pointer opacity-80 hover:opacity-100 leading-none">
                &#x2715;
            </button>
            <div id="lightbox-content"></div>
        </div>
    </div>

    <script>
        function openLightbox(type, url) {
            const content = document.getElementById('lightbox-content');
            content.innerHTML = '';
            const style = 'max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 25px 50px rgba(0,0,0,.5);display:block;background:black;';
            let media;
            if (type === 'image') {
                media = document.createElement('img');
                media.alt = 'Screenshot';
                media.style.cssText = style;
            } else {
                media = document.createElement('video');
                media.controls = true;
                media.autoplay = true;
                media.style.cssText = style;
            }
            // Assign src via property — browser treats it as a URL, never as HTML.
            media.src = url;
            content.appendChild(media);
            const lb = document.getElementById('lightbox');
            lb.classList.remove('hidden');
            lb.classList.add('flex');
        }

        function closeLightbox() {
            const lb = document.getElementById('lightbox');
            lb.classList.add('hidden');
            lb.classList.remove('flex');
            const video = document.querySelector('#lightbox-content video');
            if (video) video.pause();
            document.getElementById('lightbox-content').innerHTML = '';
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeLightbox();
        });
    </script>

</body>
</html>
