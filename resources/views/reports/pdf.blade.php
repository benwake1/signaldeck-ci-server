@php
    // Sanitise colours to valid hex only — prevents CSS injection
    $safeColour = fn($c) => preg_match('/^#[0-9A-Fa-f]{3,8}$/', trim((string) $c)) ? trim($c) : '#1e40af';
    $primary    = $safeColour($client?->primary_colour);
    $secondary  = $safeColour($client?->secondary_colour);
    $brand      = config('brand.name') ?: config('app.name');
    $rate       = $run->pass_rate;
    $rateColour = $rate >= 80 ? '#16a34a' : ($rate >= 60 ? '#d97706' : '#dc2626');
    $passing    = $run->status === 'passing';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Test Report — {{ $project->name }} — {{ $generatedAt->format('d M Y') }}</title>
<style>
    @page { size: A4; margin: 12mm 0 18mm 0; }
    @page :first { margin-top: 0; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #111827; line-height: 1.4; }
    .mono { font-family: 'Courier New', monospace; }

    .header { background: linear-gradient(135deg, {{ $primary }}, {{ $secondary }}); color: #fff; padding: 14mm 16mm 10mm; }
    .header-top { display: table; width: 100%; margin-bottom: 8mm; }
    .header-top > div { display: table-cell; vertical-align: top; }
    .header-meta { text-align: right; font-size: 8.5pt; line-height: 1.6; opacity: .9; }
    .logo { height: 16mm; max-width: 55mm; object-fit: contain; background: #fff; border-radius: 2mm; padding: 2mm 3mm; }
    .brandline { font-size: 9pt; letter-spacing: .08em; text-transform: uppercase; opacity: .85; margin-bottom: 2mm; }
    .client-name { font-size: 14pt; font-weight: 700; }
    h1 { font-size: 22pt; font-weight: 800; letter-spacing: -.01em; margin-bottom: 2mm; }
    .sub { font-size: 10pt; opacity: .85; }

    .content { padding: 10mm 16mm 0; }
    .summary-head { display: table; width: 100%; margin-bottom: 5mm; }
    .summary-head > div { display: table-cell; vertical-align: middle; }
    h2 { font-size: 13pt; font-weight: 700; }
    .pill { display: inline-block; padding: 1mm 4mm; border-radius: 99px; font-size: 9pt; font-weight: 700; }
    .pill-pass { background: #dcfce7; color: #166534; }
    .pill-fail { background: #fee2e2; color: #991b1b; }
    .pill-mixed { background: #fef3c7; color: #92400e; }

    .stats { display: table; width: 100%; border-spacing: 3mm 0; margin: 0 -3mm 6mm; }
    .stat { display: table-cell; width: 25%; text-align: center; padding: 5mm 2mm; border-radius: 3mm; border: .3mm solid; }
    .stat .n { font-size: 24pt; font-weight: 800; line-height: 1; margin-bottom: 1.5mm; }
    .stat .l { font-size: 7.5pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #6b7280; }

    .bar { height: 3mm; background: #f3f4f6; border-radius: 99px; overflow: hidden; margin-bottom: 4mm; }
    .bar > div { height: 100%; border-radius: 99px; }
    .facts { font-size: 9pt; color: #6b7280; margin-bottom: 8mm; }
    .facts strong { color: #374151; }
    .facts span { margin-right: 6mm; }

    .section-title { display: table; width: 100%; padding-bottom: 2mm; margin-bottom: 3mm; border-bottom: .5mm solid #e5e7eb; }
    .section-title > div { display: table-cell; vertical-align: middle; }
    .section-title .count { text-align: right; font-size: 9pt; color: #6b7280; }

    table.specs { width: 100%; border-collapse: collapse; }
    table.specs thead { display: table-header-group; }
    table.specs th { text-align: left; font-size: 7.5pt; letter-spacing: .08em; text-transform: uppercase; color: #6b7280; padding: 2mm 2mm; border-bottom: .3mm solid #d1d5db; }
    table.specs td { padding: 2.4mm 2mm; border-bottom: .2mm solid #f3f4f6; vertical-align: middle; font-size: 9pt; }
    table.specs tr { break-inside: avoid; page-break-inside: avoid; }
    table.specs .num { text-align: center; width: 16mm; }
    table.specs .status { text-align: right; width: 24mm; }
    .spec-name { word-break: break-all; color: #374151; }
    .zero { color: #d1d5db; }
    .empty { text-align: center; color: #6b7280; padding: 10mm 0; }

    .footer-note { margin: 10mm 16mm 0; padding-top: 4mm; border-top: .3mm solid #e5e7eb; font-size: 8.5pt; color: #6b7280; line-height: 1.6; break-inside: avoid; }
    .footer-note strong { color: #374151; }
</style>
</head>
<body>

<div class="header">
    <div class="header-top">
        <div>
            <div class="brandline">{{ $brand }} QA Report</div>
            @if($logoDataUri)
                <img class="logo" src="{{ $logoDataUri }}" alt="{{ $client->name }}">
            @else
                <div class="client-name">{{ $client->name }}</div>
            @endif
        </div>
        <div class="header-meta">
            <div><strong>Report Date:</strong> {{ $generatedAt->format('d F Y') }}</div>
            <div><strong>Run ID:</strong> #{{ $run->id }}</div>
            @if($run->commit_sha)
                <div><strong>Commit:</strong> <span class="mono">{{ \Illuminate\Support\Str::limit($run->commit_sha, 10, '') }}</span></div>
            @endif
            <div><strong>Powered By:</strong> {{ $run->runner_type?->label() ?? 'Cypress' }}</div>
        </div>
    </div>
    <h1>{{ $project->name }}</h1>
    <div class="sub">
        Test Suite: {{ $suite->name }} &nbsp;·&nbsp; Branch: {{ $run->branch }}
        &nbsp;·&nbsp; Triggered by: {{ $run->triggeredBy?->name ?? $run->trigger_source?->label() ?? '—' }}
    </div>
</div>

<div class="content">
    <div class="summary-head">
        <div><h2>Executive Summary</h2></div>
        <div style="text-align:right">
            <span class="pill {{ $passing ? 'pill-pass' : 'pill-fail' }}">{{ $passing ? 'All Tests Passed' : 'Tests Failed' }}</span>
        </div>
    </div>

    <div class="stats">
        <div class="stat" style="background:#f0fdf4;border-color:#bbf7d0"><div class="n" style="color:#16a34a">{{ $run->passed_tests }}</div><div class="l">Passed</div></div>
        <div class="stat" style="background:#fef2f2;border-color:#fecaca"><div class="n" style="color:#dc2626">{{ $run->failed_tests }}</div><div class="l">Failed</div></div>
        <div class="stat" style="background:#eff6ff;border-color:#bfdbfe"><div class="n" style="color:#2563eb">{{ $run->total_tests }}</div><div class="l">Total</div></div>
        <div class="stat" style="background:#f9fafb;border-color:#e5e7eb"><div class="n" style="color:{{ $rateColour }}">{{ $rate }}%</div><div class="l">Pass Rate</div></div>
    </div>

    <div class="bar"><div style="width:{{ min(100, max(0, $rate)) }}%;background:{{ $rateColour }}"></div></div>

    <div class="facts">
        @if($run->duration_ms)<span><strong>Duration:</strong> {{ $run->duration_formatted }}</span>@endif
        <span><strong>Run Date:</strong> {{ ($run->started_at ?? $run->created_at)->format('d M Y H:i') }}</span>
        <span><strong>Branch:</strong> {{ $run->branch }}</span>
    </div>

    <div class="section-title">
        <div><h2>Results by Spec</h2></div>
        <div class="count">{{ $run->total_tests }} tests &nbsp;·&nbsp; {{ $resultsBySpec->count() }} spec(s)</div>
    </div>

    @if($resultsBySpec->isEmpty())
        <div class="empty">No test results recorded for this run.</div>
    @else
        <table class="specs">
            <thead>
                <tr>
                    <th>Spec</th>
                    <th class="num">Passed</th>
                    <th class="num">Failed</th>
                    <th class="num">Total</th>
                    <th class="status">Status</th>
                </tr>
            </thead>
            <tbody>
            @foreach($resultsBySpec as $specFile => $results)
                @php
                    $passed = $results->where('status', 'passed')->count();
                    $failed = $results->where('status', 'failed')->count();
                @endphp
                <tr>
                    <td class="spec-name mono">{{ $specFile }}</td>
                    <td class="num" style="color:#16a34a;font-weight:600">{{ $passed }}</td>
                    <td class="num {{ $failed ? '' : 'zero' }}" style="{{ $failed ? 'color:#dc2626;font-weight:600' : '' }}">{{ $failed }}</td>
                    <td class="num">{{ $results->count() }}</td>
                    <td class="status">
                        <span class="pill {{ $failed ? 'pill-fail' : 'pill-pass' }}">{{ $failed ? 'Failed' : 'Passed' }}</span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="footer-note">
    @if($client->report_footer_text)
        <p>{{ $client->report_footer_text }}</p>
    @else
        <p>For questions about this report, please contact your project manager.</p>
    @endif
    <p><strong>{{ $client->name }}</strong>@if($client->contact_email) · {{ $client->contact_email }}@endif · Generated {{ $generatedAt->format('d M Y \a\t H:i') }}</p>
    <p>&copy; {{ date('Y') }} — All Rights Reserved — {{ config('brand.legal_name') ?: $brand }}</p>
</div>

</body>
</html>
