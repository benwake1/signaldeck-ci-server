<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Events;

use App\Models\TestRun;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $status;

    public function __construct(
        public readonly TestRun $run
    ) {
        $this->status = $run->status;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("test-run.{$this->run->id}"),
            new PrivateChannel("project.{$this->run->project_id}"),
            new PrivateChannel('dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->run->id,
            'status' => $this->run->status,
            'passed_tests' => $this->run->passed_tests,
            'failed_tests' => $this->run->failed_tests,
            'total_tests' => $this->run->total_tests,
            'pass_rate' => $this->run->pass_rate,
            'duration_formatted' => $this->run->duration_formatted,
            'report_html_url' => $this->run->report_html_url,
        ];
    }
}
