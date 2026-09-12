<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suite Repair Proposed</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;">
@php
    $project = $suite->project;
    $passed = $conversation->verification_status === \App\Enums\VerificationStatus::Passed;
    $brandName = config('brand.name') ?: config('app.name');
@endphp

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:32px 16px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="padding:20px 32px;text-align:center;background:#f3f4f6;">
                        <span style="font-size:15px;font-weight:600;color:#6b7280;letter-spacing:-0.01em;">{{ $brandName }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 32px 4px;">
                        <span style="display:inline-block;padding:4px 10px;border-radius:9999px;font-size:12px;font-weight:600;background:{{ $passed ? '#dcfce7' : '#fef3c7' }};color:{{ $passed ? '#166534' : '#92400e' }};">
                            {{ $passed ? 'Verified' : 'Needs review' }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0;">
                        <h1 style="font-size:19px;margin:0 0 4px;">Automated repair proposed</h1>
                        <p style="font-size:14px;color:#4b5563;margin:0 0 16px;">
                            {{ $project->name ?? 'Unknown project' }} / {{ $suite->name }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 20px;">
                        <p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 12px;">
                            This suite's health dropped below its threshold, so an AI-generated fix was
                            attempted and
                            @if($passed)
                                passed when run against <strong>{{ $suite->base_url }}</strong>.
                            @else
                                did not pass verification against <strong>{{ $suite->base_url }}</strong> — review the output before deciding whether to use it.
                            @endif
                        </p>
                        <p style="font-size:14px;line-height:1.6;color:#374151;margin:0;">
                            Nothing has been changed in the live suite. Open the conversation to review the
                            diff and click <strong>Update Suite</strong> if you want to accept it.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 32px;">
                        <a href="{{ $reviewUrl }}" style="display:inline-block;padding:10px 20px;border-radius:8px;background:#2563eb;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                            Review proposed fix
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
