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
    $assessment = $conversation->repair_assessment ?? [];
    $proposesFix = $assessment['proposes_fix'] ?? true;
    $isRegression = ($assessment['assessment'] ?? null) === \App\Services\TestRepairService::ASSESSMENT_APP_REGRESSION;
    $flagged = array_merge($assessment['removed_assertions'] ?? [], $assessment['added_skips'] ?? []);
    $badge = match (true) {
        $isRegression => ['Possible app regression', '#fee2e2', '#991b1b'],
        !$proposesFix => ['Needs investigation', '#fef3c7', '#92400e'],
        $passed => ['Verified', '#dcfce7', '#166534'],
        default => ['Needs review', '#fef3c7', '#92400e'],
    };
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
                        <span style="display:inline-block;padding:4px 10px;border-radius:9999px;font-size:12px;font-weight:600;background:{{ $badge[1] }};color:{{ $badge[2] }};">
                            {{ $badge[0] }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0;">
                        <h1 style="font-size:19px;margin:0 0 4px;">{{ $proposesFix ? 'Automated repair proposed' : 'Suite failing — no fix proposed' }}</h1>
                        <p style="font-size:14px;color:#4b5563;margin:0 0 16px;">
                            {{ $project->name ?? 'Unknown project' }} / {{ $suite->name }}
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 20px;">
                        @if($proposesFix)
                            <p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 12px;">
                                This suite has failed its last {{ $assessment['failed_runs'] ?? 'few' }} runs. The AI judged the
                                tests to be out of date with the application, and its proposed fix
                                @if($passed)
                                    passed when run against <strong>{{ $suite->base_url }}</strong>.
                                @else
                                    did not pass verification against <strong>{{ $suite->base_url }}</strong> — review the output before deciding whether to use it.
                                @endif
                            </p>
                        @else
                            <p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 12px;">
                                This suite has failed its last {{ $assessment['failed_runs'] ?? 'few' }} runs.
                                @if($isRegression)
                                    The AI thinks the <strong>application</strong> is broken rather than the tests, so it hasn't proposed any test changes. This likely needs a developer to look at {{ $suite->base_url }}.
                                @else
                                    The AI couldn't confidently tell whether the tests or the application are at fault, so it hasn't proposed any test changes.
                                @endif
                            </p>
                        @endif
                        @if(!empty($assessment['reason']))
                            <p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 12px;padding:10px 12px;background:#f9fafb;border-left:3px solid #d1d5db;">
                                <strong>AI assessment:</strong> {{ $assessment['reason'] }}
                            </p>
                        @endif
                        @if($flagged)
                            <div style="font-size:13px;line-height:1.5;color:#991b1b;margin:0 0 12px;padding:10px 12px;background:#fef2f2;border-radius:8px;">
                                <strong>Check carefully — this fix removes, changes or skips checks:</strong>
                                <ul style="margin:6px 0 0;padding-left:18px;">
                                    @foreach(array_slice($flagged, 0, 10) as $item)
                                        <li style="font-family:ui-monospace,Menlo,monospace;font-size:12px;word-break:break-all;">{{ $item['file'] }}: {{ \Illuminate\Support\Str::limit($item['line'], 140) }}</li>
                                    @endforeach
                                </ul>
                                @if(count($flagged) > 10)
                                    <p style="margin:6px 0 0;">…and {{ count($flagged) - 10 }} more.</p>
                                @endif
                            </div>
                        @endif
                        <p style="font-size:14px;line-height:1.6;color:#374151;margin:0;">
                            Nothing has been changed in the live suite.
                            @if($proposesFix)
                                Open the conversation to review the diff and click <strong>Update Suite</strong> if you want to accept it.
                            @else
                                Open the conversation to read the AI's full diagnosis.
                            @endif
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 32px;">
                        <a href="{{ $reviewUrl }}" style="display:inline-block;padding:10px 20px;border-radius:8px;background:#2563eb;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                            {{ $proposesFix ? 'Review proposed fix' : 'View diagnosis' }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
