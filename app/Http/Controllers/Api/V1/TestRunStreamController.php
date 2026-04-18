<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Models\RunEvent;
use App\Models\TestRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestRunStreamController
{
    /**
     * Maximum lifetime of a stream in seconds.
     *
     * Matches the queue worker --timeout so a stream can never outlive the job
     * that drives it. After this the stream closes with a heartbeat comment;
     * the client reconnects and will immediately receive any terminal status.
     */
    private const MAX_STREAM_SECONDS = 14400; // 4 hours

    /**
     * Per-run stream — web detail view and macOS TestRunDetailView.
     *
     * Streams log.chunk and status.changed for the given run.
     * Closes automatically when the run reaches a terminal status,
     * or after MAX_STREAM_SECONDS to prevent zombie FPM workers.
     *
     * macOS clients may send ?from_byte=N to resume after a reconnect.
     */
    public function stream(Request $request, TestRun $testRun): StreamedResponse
    {
        $fromByte = max(0, (int) $request->query('from_byte', 0));

        return response()->stream(function () use ($testRun, $fromByte) {
            // Allow the script to run indefinitely and detect disconnects manually.
            set_time_limit(0);
            ignore_user_abort(true);

            // Disable all output buffering so SSE frames are flushed immediately.
            // ini_set must come before ob_end_clean so the INI-level buffer (e.g.
            // Herd's default output_buffering=4096) is turned off for this request.
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            $run = $testRun->fresh();

            // If the run is already terminal, send its final status and exit —
            // no need to start a polling loop.
            if ($run->isComplete()) {
                $this->emitStatusChanged($run);
                return;
            }

            $log           = $run->log_output ?? '';
            $lastLogLength = min($fromByte, strlen($log)); // clamp to actual log length
            $lastStatus    = $run->status;
            $lastHeartbeat = time();
            $startedAt     = time();

            // Emit the current status immediately on connect so the client
            // is always in sync even if it missed a transition while reconnecting.
            $this->emitStatusChanged($run);
            $lastHeartbeat = time();

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                if (time() - $startedAt >= self::MAX_STREAM_SECONDS) {
                    // Signal the client to reconnect; it will re-receive terminal status.
                    echo ": stream-timeout — reconnect\n\n";
                    @flush();
                    break;
                }

                $run = $testRun->fresh();
                $log = $run->log_output ?? '';

                // --- Log chunks ---
                $currentLength = strlen($log);
                if ($currentLength > $lastLogLength) {
                    $chunk         = substr($log, $lastLogLength);
                    $lastLogLength = $currentLength;

                    $sent = $this->emit('log.chunk', [
                        'chunk'       => $chunk,
                        'byte_offset' => $lastLogLength,
                    ]);

                    if ($sent) {
                        $lastHeartbeat = time();
                    }
                }

                // --- Status changes ---
                if ($run->status !== $lastStatus) {
                    $lastStatus = $run->status;
                    $this->emitStatusChanged($run);
                    $lastHeartbeat = time();

                    if ($run->isComplete()) {
                        break;
                    }
                }

                // --- Heartbeat (SSE comment — does not trigger client message event) ---
                if (time() - $lastHeartbeat >= 15) {
                    echo ": heartbeat\n\n";
                    @flush();
                    $lastHeartbeat = time();
                }

                sleep(1);
            }
        }, 200, $this->sseHeaders());
    }

    /**
     * Global stream — macOS list view, dashboard, and RunPollingService.
     *
     * Streams run.updated, run.completed, and dashboard.stats_updated for all
     * runs the authenticated user is authorised to see. Never self-closes
     * except after MAX_STREAM_SECONDS; clients reconnect using Last-Event-ID.
     */
    public function globalStream(Request $request): StreamedResponse
    {
        $user   = $request->user();
        $cursor = max(0, (int) $request->header('Last-Event-ID', 0));

        return response()->stream(function () use ($user, $cursor) {
            set_time_limit(0);
            ignore_user_abort(true);

            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            $lastHeartbeat = time();
            $startedAt     = time();

            // On connect, send a heartbeat so the client knows the stream is live.
            echo "event: heartbeat\ndata: {}\n\n";
            @flush();

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                if (time() - $startedAt >= self::MAX_STREAM_SECONDS) {
                    echo ": stream-timeout — reconnect\n\n";
                    @flush();
                    break;
                }

                $events = RunEvent::where('id', '>', $cursor)
                    ->where(function ($q) use ($user) {
                        $q->whereNull('run_id')
                          ->orWhereHas('run', fn ($r) => $r->visibleTo($user));
                    })
                    ->orderBy('id')
                    ->limit(50)
                    ->get();

                foreach ($events as $event) {
                    echo "id: {$event->id}\n";
                    echo "event: {$event->event_type}\n";
                    echo 'data: ' . json_encode($event->payload) . "\n\n";
                    @flush();
                    $cursor        = $event->id;
                    $lastHeartbeat = time();
                }

                if (time() - $lastHeartbeat >= 15) {
                    echo "event: heartbeat\ndata: {}\n\n";
                    @flush();
                    $lastHeartbeat = time();
                }

                sleep(1);
            }
        }, 200, $this->sseHeaders());
    }

    private function emitStatusChanged(TestRun $run): void
    {
        $this->emit('status.changed', [
            'status'             => $run->status,
            'passed_tests'       => $run->passed_tests,
            'failed_tests'       => $run->failed_tests,
            'total_tests'        => $run->total_tests,
            'duration_formatted' => $run->duration_formatted,
        ]);
    }

    /**
     * Write one SSE event and flush. Returns false if the write fails
     * (broken pipe / client gone), so the caller can abort the loop.
     */
    private function emit(string $event, array $data): bool
    {
        $frame = "event: {$event}\n" . 'data: ' . json_encode($data) . "\n\n";

        echo $frame;

        return @flush() !== false;
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ];
    }
}
