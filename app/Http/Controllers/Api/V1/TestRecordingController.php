<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Enums\RecordingStatus;
use App\Http\Controllers\Controller;
use App\Models\TestRecordingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestRecordingController extends Controller
{
    /**
     * Lets the recorder script re-sync its on-page step counter after a full
     * page navigation reloads it from scratch — otherwise the overlay would
     * show 0 steps on every page even though the session's actions carry
     * over server-side, making an ongoing recording look cancelled.
     */
    public function show(string $token): JsonResponse
    {
        $session = TestRecordingSession::where('token', $token)->first();

        if (!$session) {
            return response()->json(['error' => 'Recording session not found'], 404);
        }

        if ($session->isExpired() && $session->status !== RecordingStatus::Expired) {
            $session->update(['status' => RecordingStatus::Expired]);
        }

        return response()->json([
            'status' => $session->status->value,
            'step_count' => count($session->actions ?? []),
        ]);
    }

    public function appendAction(Request $request, string $token): JsonResponse
    {
        $session = $this->findActiveSession($token);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:click,input,change,submit'],
            'selector' => ['required', 'string', 'max:500'],
            'url' => ['required', 'string', 'max:2000'],
            'timestamp' => ['required', 'integer'],
            'sensitive' => ['sometimes', 'boolean'],
            'value' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Click-only disambiguation metadata (see content-recorder.js) —
            // a generic tag-name selector alone was directly responsible for
            // two real generation failures (a decoy hidden image, an
            // unclickable SVG icon), so the recorder now hands over the
            // resolved link target and enough context to avoid guessing.
            'href' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visible' => ['sometimes', 'boolean'],
            'matchIndex' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'matchCount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        // Defense in depth: a sensitive step must never carry a value,
        // even though the recorder script is written to omit it entirely.
        if (!empty($validated['sensitive'])) {
            unset($validated['value']);
        }

        $actions = $session->actions ?? [];
        $actions[] = [
            'type' => $validated['type'],
            'selector' => $validated['selector'],
            'url' => $validated['url'],
            'timestamp' => $validated['timestamp'],
            'sensitive' => $validated['sensitive'] ?? false,
            'value' => $validated['value'] ?? null,
            'href' => $validated['href'] ?? null,
            'visible' => $validated['visible'] ?? null,
            'matchIndex' => $validated['matchIndex'] ?? null,
            'matchCount' => $validated['matchCount'] ?? null,
        ];

        $session->update(['actions' => $actions]);

        return response()->json(['step' => count($actions)]);
    }

    public function complete(string $token): JsonResponse
    {
        $session = $this->findActiveSession($token);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $session->update(['status' => RecordingStatus::Completed]);

        return response()->json(['status' => $session->status->value]);
    }

    private function findActiveSession(string $token): TestRecordingSession|JsonResponse
    {
        $session = TestRecordingSession::where('token', $token)->first();

        if (!$session) {
            return response()->json(['error' => 'Recording session not found'], 404);
        }

        if ($session->isExpired()) {
            if ($session->status !== RecordingStatus::Expired) {
                $session->update(['status' => RecordingStatus::Expired]);
            }
            return response()->json(['error' => 'Recording session has expired'], 410);
        }

        if ($session->status !== RecordingStatus::Recording) {
            return response()->json(['error' => 'Recording session is no longer active'], 410);
        }

        return $session;
    }
}
