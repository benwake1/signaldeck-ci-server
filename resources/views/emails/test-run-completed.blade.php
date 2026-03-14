<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Run {{ $run->status === 'passing' ? 'Passed' : 'Failed' }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; margin: 0; padding: 24px; color: #111827; }
        .wrapper { max-width: 600px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { padding: 32px 32px 24px; border-bottom: 1px solid #f3f4f6; }
        .brand { font-size: 13px; color: #6b7280; margin-bottom: 16px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 13px; font-weight: 600; margin-bottom: 12px; }
        .status-passing { background: #dcfce7; color: #166534; }
        .status-failed  { background: #fee2e2; color: #991b1b; }
        .project-name { font-size: 22px; font-weight: 700; color: #111827; margin: 0 0 4px; }
        .client-name { font-size: 14px; color: #6b7280; margin: 0; }
        .body { padding: 28px 32px; }
        .stats { display: table; width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 24px; }
        .stat { display: table-cell; background: #f9fafb; border-radius: 8px; padding: 16px; text-align: center; width: 25%; }
        .stat-value { font-size: 26px; font-weight: 700; color: #111827; line-height: 1; }
        .stat-label { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }
        .stat-passed .stat-value { color: #16a34a; }
        .stat-failed .stat-value  { color: #dc2626; }
        .meta { font-size: 13px; color: #6b7280; margin-bottom: 24px; line-height: 1.8; }
        .meta strong { color: #374151; }
        .btn { display: block; text-align: center; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 14px 24px; border-radius: 8px; font-weight: 600; font-size: 15px; margin-bottom: 12px; }
        .btn-secondary { background: #f3f4f6; color: #374151 !important; }
        .footer { padding: 20px 32px; border-top: 1px solid #f3f4f6; font-size: 12px; color: #9ca3af; text-align: center; }
        .share-note { font-size: 12px; color: #9ca3af; text-align: center; margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">

            <div class="header">
                <div class="brand">{{ config('brand.name') ?: config('app.name') }}</div>
                <div class="status-badge {{ $run->status === 'passing' ? 'status-passing' : 'status-failed' }}">
                    {{ $run->status === 'passing' ? '✅ All Tests Passed' : '❌ Tests Failed' }}
                </div>
                <p class="project-name">{{ $run->project->name }}</p>
                <p class="client-name">{{ $run->project->client->name }}</p>
            </div>

            <div class="body">

                <table class="stats">
                    <tr>
                        <td class="stat stat-passed">
                            <div class="stat-value">{{ $run->passed_tests ?? 0 }}</div>
                            <div class="stat-label">Passed</div>
                        </td>
                        <td class="stat stat-failed">
                            <div class="stat-value">{{ $run->failed_tests ?? 0 }}</div>
                            <div class="stat-label">Failed</div>
                        </td>
                        <td class="stat">
                            <div class="stat-value">{{ $run->total_tests ?? 0 }}</div>
                            <div class="stat-label">Total</div>
                        </td>
                        <td class="stat">
                            <div class="stat-value">{{ $run->pass_rate ?? 0 }}%</div>
                            <div class="stat-label">Pass Rate</div>
                        </td>
                    </tr>
                </table>

                <div class="meta">
                    <strong>Suite:</strong> {{ $run->testSuite->name ?? '—' }}<br>
                    <strong>Branch:</strong> {{ $run->branch ?? '—' }}<br>
                    @if($run->commit_sha)
                    <strong>Commit:</strong> {{ substr($run->commit_sha, 0, 7) }}<br>
                    @endif
                    <strong>Duration:</strong> {{ $run->duration_formatted }}<br>
                    <strong>Completed:</strong> {{ $run->finished_at?->format('d M Y, H:i') ?? '—' }}
                </div>

                <a href="{{ $run->report_share_url }}" class="btn">View Full Report</a>
                <p class="share-note">This link is valid for 30 days and does not require a login.</p>

                <a href="{{ $run->report_html_url }}" class="btn btn-secondary">Open in Dashboard</a>

            </div>

            <div class="footer">
                &copy; {{ date('Y') }} {{ config('brand.legal_name') ?: config('brand.name') ?: config('app.name') }}.
                You are receiving this because you triggered this test run.
            </div>

        </div>
    </div>
</body>
</html>
