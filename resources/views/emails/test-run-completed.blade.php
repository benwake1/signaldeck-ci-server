<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Run {{ $run->status === 'passing' ? 'Passed' : 'Failed' }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;">
@php
    $isPassing    = $run->status === 'passing';
    $brandColor   = config('brand.primary_color') ?: '#2563eb';
    $brandName    = config('brand.name') ?: config('app.name');
    $logoPath     = config('brand.logo_path');
    $statusBg     = $isPassing ? '#dcfce7' : '#fee2e2';
    $statusColor  = $isPassing ? '#166534' : '#991b1b';
    $statusLabel  = $isPassing ? '✅ All Tests Passed' : '❌ Tests Failed';
@endphp

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;">

                {{-- Brand header --}}
                <tr>
                    <td style="background:#f3f4f6;border-radius:12px 12px 0 0;padding:24px 32px;text-align:center;">
                        @if($logoPath)
                            <img src="{{ asset($logoPath) }}" alt="{{ $brandName }}" style="max-height:40px;width:auto;display:inline-block;">
                        @else
                            <span style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.02em;">{{ $brandName }}</span>
                        @endif
                    </td>
                </tr>

                {{-- Status bar --}}
                <tr>
                    <td style="background:{{ $statusBg }};padding:14px 32px;text-align:center;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
                        <span style="font-size:15px;font-weight:700;color:{{ $statusColor }};">{{ $statusLabel }}</span>
                    </td>
                </tr>

                {{-- Card body --}}
                <tr>
                    <td style="background:#ffffff;border-radius:0 0 12px 12px;border:1px solid #e5e7eb;border-top:0;overflow:hidden;">

                        {{-- Project info --}}
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td style="padding:28px 32px 20px;border-bottom:1px solid #f3f4f6;">
                                    <p style="margin:0 0 2px;font-size:22px;font-weight:700;color:#111827;line-height:1.2;">{{ $run->project->name }}</p>
                                    <p style="margin:0;font-size:14px;color:#6b7280;">{{ $run->project->client->name }}</p>
                                </td>
                            </tr>
                        </table>

                        {{-- Stats --}}
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="padding:24px 32px 0;">
                            <tr>
                                <td style="padding:0 8px 24px 0;">
                                    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                                        <tr>
                                            <td width="22%" style="background:#f9fafb;border-radius:8px;padding:16px 8px;text-align:center;">
                                                <div style="font-size:26px;font-weight:700;color:#16a34a;line-height:1;">{{ $run->passed_tests ?? 0 }}</div>
                                                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Passed</div>
                                            </td>
                                            <td width="5%" style="padding:0 4px;"></td>
                                            <td width="22%" style="background:#f9fafb;border-radius:8px;padding:16px 8px;text-align:center;">
                                                <div style="font-size:26px;font-weight:700;color:{{ ($run->failed_tests ?? 0) > 0 ? '#dc2626' : '#111827' }};line-height:1;">{{ $run->failed_tests ?? 0 }}</div>
                                                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Failed</div>
                                            </td>
                                            <td width="5%" style="padding:0 4px;"></td>
                                            <td width="22%" style="background:#f9fafb;border-radius:8px;padding:16px 8px;text-align:center;">
                                                <div style="font-size:26px;font-weight:700;color:#111827;line-height:1;">{{ $run->total_tests ?? 0 }}</div>
                                                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Total</div>
                                            </td>
                                            <td width="5%" style="padding:0 4px;"></td>
                                            <td width="24%" style="background:#f9fafb;border-radius:8px;padding:16px 8px;text-align:center;">
                                                <div style="font-size:26px;font-weight:700;color:#111827;line-height:1;">{{ $run->pass_rate ?? 0 }}%</div>
                                                <div style="font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.05em;margin-top:4px;">Pass Rate</div>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        {{-- Meta --}}
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td style="padding:0 32px 24px;">
                                    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f9fafb;border-radius:8px;padding:16px;">
                                        <tr>
                                            <td style="font-size:13px;color:#6b7280;line-height:2;">
                                                <span style="color:#374151;font-weight:600;">Suite</span>&nbsp;&nbsp;{{ $run->testSuite->name ?? '—' }}<br>
                                                <span style="color:#374151;font-weight:600;">Branch</span>&nbsp;&nbsp;{{ $run->branch ?? '—' }}<br>
                                                @if($run->commit_sha)
                                                <span style="color:#374151;font-weight:600;">Commit</span>&nbsp;&nbsp;<code style="font-size:12px;background:#e5e7eb;padding:1px 5px;border-radius:4px;">{{ substr($run->commit_sha, 0, 8) }}</code><br>
                                                @endif
                                                <span style="color:#374151;font-weight:600;">Duration</span>&nbsp;&nbsp;{{ $run->duration_formatted }}<br>
                                                <span style="color:#374151;font-weight:600;">Completed</span>&nbsp;&nbsp;{{ $run->finished_at?->format('d M Y, H:i') ?? '—' }}
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>

                        {{-- CTAs --}}
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                            <tr>
                                <td style="padding:0 32px 12px;">
                                    <a href="{{ $run->report_share_url }}" style="display:block;text-align:center;background:{{ $brandColor }};color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:8px;font-weight:600;font-size:15px;">
                                        View Full Report (Shareable)
                                    </a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:0 32px 8px;text-align:center;">
                                    <span style="font-size:12px;color:#9ca3af;">This link is valid for 30 days and does not require a login.</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:0 32px 28px;">
                                    <br />
                                    <a href="{{ $run->report_html_url }}" style="display:block;text-align:center;background:#f3f4f6;color:#374151;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">
                                        Open in Dashboard
                                    </a>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:20px 0;text-align:center;">
                        <p style="margin:0;font-size:12px;color:#9ca3af;">
                            You are receiving this because you triggered this test run.<br>
                        </p>
                        <br />
                        <p style="margin:0;font-size:12px;color:#9ca3af;">
                            &copy; {{ date('Y') }} {{ config('brand.legal_name') ?: $brandName }}.<br>
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
