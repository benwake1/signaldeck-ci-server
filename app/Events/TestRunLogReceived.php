<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunLogReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $runId,
        public readonly string $message
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("test-run.{$this->runId}")];
    }

    public function broadcastAs(): string
    {
        return 'log.received';
    }
}
