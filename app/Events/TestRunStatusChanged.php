<?php

namespace App\Events;

use App\Models\TestRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly TestRun $run
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel("test-run.{$this->run->id}"),
            new Channel("project.{$this->run->project_id}"),
            new Channel('dashboard'),
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
