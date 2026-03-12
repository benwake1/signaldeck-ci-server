<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Report — {{ $project->name }} — {{ $generatedAt->format('d M Y') }}</title>
    <style>
        /* ============================================
           BRANDED TEST REPORT — Print & Web Ready
           Uses client branding colours from DB
        ============================================ */
        @php
            // Sanitise colours to valid hex only — prevents CSS injection
            $safeColour = fn($c) => preg_match('/^#[0-9A-Fa-f]{3,8}$/', trim($c)) ? trim($c) : '#1e40af';
        @endphp
        :root {
            --primary:   {{ $safeColour($client->primary_colour) }};
            --secondary: {{ $safeColour($client->secondary_colour) }};
            --accent:    {{ $safeColour($client->accent_colour) }};
            --pass:      #16a34a;
            --fail:      #dc2626;
            --warn:      #d97706;
            --muted:     #6b7280;
            --border:    #e5e7eb;
            --bg:        #f9fafb;
            --white:     #ffffff;
            --font:      'Segoe UI', system-ui, -apple-system, sans-serif;
            --mono:      'Cascadia Code', 'Fira Code', 'Courier New', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: #111827;
            font-size: 14px;
            line-height: 1.6;
        }

        /* ---- HEADER ---- */
        .report-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 40px 48px;
            position: relative;
            overflow: hidden;
        }

        .report-header::after {
            content: '';
            position: absolute;
            right: -60px;
            top: -60px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
        }

        .logos {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .logo-img {
            height: 48px;
            width: auto;
            object-fit: contain;
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
            padding: 6px 12px;
        }

        .logo-divider {
            width: 1px;
            height: 40px;
            background: rgba(255,255,255,0.3);
        }

        .logo-text {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .report-meta {
            text-align: right;
            font-size: 13px;
            opacity: 0.85;
            line-height: 1.8;
        }

        .report-title {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
        }

        .report-subtitle {
            font-size: 16px;
            opacity: 0.8;
        }

        /* ---- SUMMARY CARDS ---- */
        .summary-section {
            background: white;
            padding: 32px 48px;
            border-bottom: 1px solid var(--border);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 20px;
        }

        .summary-card {
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border);
            background: var(--bg);
        }

        .summary-card.pass { border-color: #bbf7d0; background: #f0fdf4; }
        .summary-card.fail { border-color: #fecaca; background: #fef2f2; }
        .summary-card.warn { border-color: #fde68a; background: #fffbeb; }
        .summary-card.info { border-color: #bfdbfe; background: #eff6ff; }

        .card-number {
            font-size: 40px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 6px;
        }

        .card-number.pass { color: var(--pass); }
        .card-number.fail { color: var(--fail); }
        .card-number.warn { color: var(--warn); }
        .card-number.info { color: var(--primary); }

        .card-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }

        /* ---- PASS RATE BAR ---- */
        .pass-rate-bar {
            margin-top: 24px;
            background: #f3f4f6;
            border-radius: 999px;
            height: 10px;
            overflow: hidden;
        }

        .pass-rate-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--pass), #4ade80);
            transition: width 1s ease;
        }

        .pass-rate-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .pass-rate-pct {
            font-weight: 700;
            font-size: 18px;
            color: var(--pass);
        }

        /* ---- SECTION HEADERS ---- */
        .section {
            padding: 32px 48px;
            border-bottom: 1px solid var(--border);
            background: white;
        }

        .section:last-child { border-bottom: none; }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }

        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .section-count {
            margin-left: auto;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        /* ---- SPEC GROUPS ---- */
        .spec-group {
            margin-bottom: 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .spec-header {
            background: var(--bg);
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--border);
        }

        .spec-file {
            font-family: var(--mono);
            font-size: 12px;
            color: var(--muted);
            flex: 1;
        }

        .spec-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 999px;
        }

        .spec-badge.pass { background: #dcfce7; color: #15803d; }
        .spec-badge.fail { background: #fee2e2; color: #b91c1c; }
        .spec-badge.mixed { background: #fef9c3; color: #92400e; }

        /* ---- TEST ROWS ---- */
        .test-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            transition: background 0.1s;
        }

        .test-row:last-child { border-bottom: none; }

        .test-status-icon { font-size: 16px; margin-top: 1px; flex-shrink: 0; }

        .test-info { flex: 1; min-width: 0; }

        .test-title { font-weight: 500; font-size: 13px; }

        .test-suite { font-size: 11px; color: var(--muted); margin-top: 1px; }

        .test-error {
            margin-top: 8px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 10px 12px;
        }

        .test-error-msg {
            font-family: var(--mono);
            font-size: 11px;
            color: #991b1b;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .test-stack {
            margin-top: 6px;
            font-family: var(--mono);
            font-size: 10px;
            color: #b91c1c;
            opacity: 0.7;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 100px;
            overflow: hidden;
        }

        .test-duration { font-size: 11px; color: var(--muted); flex-shrink: 0; }

        /* ---- SCREENSHOTS & VIDEOS ---- */
        .artifacts {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .screenshot-img {
            height: 80px;
            border-radius: 6px;
            border: 1px solid var(--border);
            cursor: zoom-in;
            object-fit: cover;
            transition: box-shadow 0.15s;
        }

        .screenshot-img:hover { box-shadow: 0 0 0 3px var(--primary); }

        .video-thumb {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #1d4ed8;
            cursor: pointer;
            text-decoration: none;
        }

        .video-thumb:hover { background: #dbeafe; }

        /* ---- LIGHTBOX ---- */
        .lightbox-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .lightbox-overlay.active { display: flex; }

        .lightbox-inner {
            position: relative;
            max-width: 90vw;
            max-height: 90vh;
        }

        .lightbox-inner img,
        .lightbox-inner video {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 8px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            display: block;
            background: black;
        }

        .lightbox-close {
            position: absolute;
            top: -36px;
            right: 0;
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
        }

        .lightbox-close:hover { opacity: 1; }

        /* ---- FOOTER ---- */
        .report-footer {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 24px 48px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            opacity: 0.9;
        }

        .footer-left { opacity: 0.85; line-height: 1.8; }
        .footer-right { text-align: right; opacity: 0.85; line-height: 1.8; }

        /* ---- PRINT BUTTON ---- */
        .print-btn {
            position: fixed;
            bottom: 32px;
            right: 32px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 24px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1000;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .print-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,0,0,0.3); }

        /* ---- PRINT STYLES ---- */
        @media print {
            body { background: white; }
            .print-btn { display: none; }
            .lightbox-overlay { display: none !important; }
            .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .report-footer { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary-card { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .section { break-inside: avoid; }
            .spec-group { break-inside: avoid; }
            .artifacts { display: none; }
        }

        /* ---- UTILITIES ---- */
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .pill-pass { background: #dcfce7; color: #15803d; }
        .pill-fail { background: #fee2e2; color: #b91c1c; }
        .pill-gray { background: #f3f4f6; color: var(--muted); }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--muted);
        }

        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
    </style>
</head>
<body>

    {{-- ===== HEADER ===== --}}
    <div class="report-header">
        <div class="header-top">
            <div class="logos">
                {{-- Your agency logo --}}
                <div class="logo-text">🧪 QA Dashboard</div>

                <div class="logo-divider"></div>

                {{-- Client logo --}}
                @if($client->logo_path)
                    <img src="{{ Storage::url($client->logo_path) }}" alt="{{ $client->name }}" class="logo-img">
                @else
                    <div style="font-size:18px;font-weight:700;opacity:.9;">{{ $client->name }}</div>
                @endif
            </div>

            <div class="report-meta">
                <div><strong>Report Date:</strong> {{ $generatedAt->format('d F Y') }}</div>
                <div><strong>Generated:</strong> {{ $generatedAt->format('H:i') }}</div>
                <div><strong>Run ID:</strong> #{{ $run->id }}</div>
                @if($run->commit_sha)
                    <div><strong>Commit:</strong> <code style="font-family:monospace">{{ $run->commit_sha }}</code></div>
                @endif
            </div>
        </div>

        <div class="report-title">{{ $project->name }}</div>
        <div class="report-subtitle">
            Test Suite: {{ $suite->name }} &nbsp;·&nbsp;
            Branch: {{ $run->branch }} &nbsp;·&nbsp;
            Triggered by: {{ $run->triggeredBy->name }}
        </div>
    </div>

    {{-- ===== SUMMARY ===== --}}
    <div class="summary-section">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2 style="font-size:16px;font-weight:700;color:#374151;">Executive Summary</h2>
            <span class="pill {{ $run->status === 'passing' ? 'pill-pass' : 'pill-fail' }}">
                {{ $run->status === 'passing' ? '✅ All Tests Passed' : '❌ Tests Failed' }}
            </span>
        </div>

        <div class="summary-cards">
            <div class="summary-card pass">
                <div class="card-number pass">{{ $run->passed_tests }}</div>
                <div class="card-label">Passed</div>
            </div>
            <div class="summary-card fail">
                <div class="card-number fail">{{ $run->failed_tests }}</div>
                <div class="card-label">Failed</div>
            </div>
            <div class="summary-card info">
                <div class="card-number info">{{ $run->total_tests }}</div>
                <div class="card-label">Total</div>
            </div>
            <div class="summary-card">
                <div class="card-number {{ $run->pass_rate >= 80 ? 'pass' : ($run->pass_rate >= 60 ? 'warn' : 'fail') }}">
                    {{ $run->pass_rate }}%
                </div>
                <div class="card-label">Pass Rate</div>
            </div>
        </div>

        <div style="margin-top:24px;">
            <div class="pass-rate-label">
                <span style="font-weight:600;color:#374151;">Overall Pass Rate</span>
                <span class="pass-rate-pct">{{ $run->pass_rate }}%</span>
            </div>
            <div class="pass-rate-bar">
                <div class="pass-rate-fill" style="width:{{ $run->pass_rate }}%;
                    background:linear-gradient(90deg,
                        {{ $run->pass_rate >= 80 ? '#16a34a, #4ade80' : ($run->pass_rate >= 60 ? '#d97706, #fbbf24' : '#dc2626, #f87171') }}
                    );"></div>
            </div>
        </div>

        @if($run->duration_ms)
            <div style="margin-top:16px;display:flex;gap:24px;font-size:13px;color:var(--muted);">
                <span>⏱ Duration: <strong style="color:#374151;">{{ $run->duration_formatted }}</strong></span>
                <span>📅 Run Date: <strong style="color:#374151;">{{ $run->started_at?->format('d M Y H:i') ?? $run->created_at->format('d M Y H:i') }}</strong></span>
                <span>🌿 Branch: <strong style="color:#374151;">{{ $run->branch }}</strong></span>
            </div>
        @endif
    </div>

    {{-- ===== FAILED TESTS ===== --}}
    @if($failedResults->count() > 0)
    <div class="section">
        <div class="section-header">
            <div class="section-icon" style="background:#fee2e2;">❌</div>
            <span class="section-title" style="color:#b91c1c;">Failed Tests</span>
            <span class="section-count">{{ $failedResults->count() }}</span>
        </div>

        @foreach($failedResults->groupBy('spec_file') as $specFile => $results)
            <div class="spec-group">
                <div class="spec-header">
                    <span class="spec-file">📄 {{ $specFile }}</span>
                    <span class="spec-badge fail">{{ $results->count() }} failed</span>
                </div>

                @foreach($results as $result)
                    <div class="test-row" style="background:#fffafa;">
                        <div class="test-status-icon">❌</div>
                        <div class="test-info">
                            <div class="test-title">{{ $result->test_title }}</div>
                            @if($result->suite_title)
                                <div class="test-suite">{{ $result->suite_title }}</div>
                            @endif

                            @if($result->error_message)
                                <div class="test-error">
                                    <div class="test-error-msg">{{ $result->error_message }}</div>
                                    @if($result->error_stack)
                                        <div class="test-stack">{{ Str::limit($result->error_stack, 400) }}</div>
                                    @endif
                                </div>
                            @endif

                            @if($result->screenshot_urls || $result->video_url)
                                <div class="artifacts">
                                    @foreach($result->screenshot_urls ?? [] as $url)
                                        <img src="{{ $url }}" class="screenshot-img" alt="Failure screenshot"
                                             onclick="openLightbox('image', '{{ $url }}')">
                                    @endforeach
                                    @if($result->video_url)
                                        <button class="video-thumb" onclick="openLightbox('video', '{{ $result->video_url }}')">
                                            🎬 Watch video
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="test-duration">{{ $result->duration_formatted }}</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    @endif

    {{-- ===== ALL RESULTS BY SPEC ===== --}}
    <div class="section">
        <div class="section-header">
            <div class="section-icon" style="background:#eff6ff;">📋</div>
            <span class="section-title">Full Test Results</span>
            <span class="section-count">{{ $run->total_tests }} tests across {{ $resultsBySpec->count() }} spec(s)</span>
        </div>

        @forelse($resultsBySpec as $specFile => $results)
            @php
                $specPassed  = $results->where('status','passed')->count();
                $specFailed  = $results->where('status','failed')->count();
                $specTotal   = $results->count();
                $specBadge   = $specFailed > 0 && $specPassed > 0 ? 'mixed' : ($specFailed > 0 ? 'fail' : 'pass');
            @endphp
            <div class="spec-group">
                <div class="spec-header">
                    <span class="spec-file">📄 {{ $specFile }}</span>
                    <span class="spec-badge {{ $specBadge }}">
                        {{ $specPassed }}/{{ $specTotal }} passed
                    </span>
                </div>

                @foreach($results as $result)
                    <div class="test-row">
                        <div class="test-status-icon">
                            @if($result->status === 'passed') ✅
                            @elseif($result->status === 'failed') ❌
                            @elseif($result->status === 'pending') ⏸️
                            @else ⏭️ @endif
                        </div>
                        <div class="test-info">
                            <div class="test-title">{{ $result->test_title }}</div>
                            @if($result->suite_title)
                                <div class="test-suite">{{ $result->suite_title }}</div>
                            @endif
                            @if($result->error_message && $result->status === 'failed')
                                <div class="test-error">
                                    <div class="test-error-msg">{{ Str::limit($result->error_message, 200) }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="test-duration">{{ $result->duration_formatted }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-state-icon">🔍</div>
                <p>No test results recorded for this run.</p>
            </div>
        @endforelse
    </div>

    {{-- ===== FOOTER ===== --}}
    <div class="report-footer">
        <div class="footer-left">
            @if($client->report_footer_text)
                <p>{{ $client->report_footer_text }}</p>
            @else
                <p>This report was automatically generated by QA Dashboard.</p>
                <p>For questions about this report, please contact your account manager.</p>
            @endif
        </div>
        <div class="footer-right">
            <p><strong>{{ $client->name }}</strong></p>
            @if($client->contact_email)
                <p>{{ $client->contact_email }}</p>
            @endif
            <p>Generated {{ $generatedAt->format('d M Y \a\t H:i') }}</p>
        </div>
    </div>

    {{-- ===== PRINT BUTTON ===== --}}
    <button class="print-btn" onclick="window.print()">
        🖨️ Save as PDF
    </button>

    {{-- ===== LIGHTBOX ===== --}}
    <div class="lightbox-overlay" id="lightbox" onclick="if(event.target===this)closeLightbox()">
        <div class="lightbox-inner">
            <button class="lightbox-close" onclick="closeLightbox()">&#x2715;</button>
            <div id="lightbox-content"></div>
        </div>
    </div>

    <script>
        function openLightbox(type, url) {
            const content = document.getElementById('lightbox-content');
            if (type === 'image') {
                content.innerHTML = '<img src="' + url + '" alt="Screenshot">';
            } else {
                content.innerHTML = '<video src="' + url + '" controls autoplay></video>';
            }
            document.getElementById('lightbox').classList.add('active');
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
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
