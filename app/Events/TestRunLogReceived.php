<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunLogReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $runId,
        public readonly string $message
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("test-run.{$this->runId}")];
    }

    public function broadcastAs(): string
    {
        return 'log.received';
    }
}
