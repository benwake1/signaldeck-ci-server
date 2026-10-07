<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Enums;

enum RecordingStatus: string
{
    case Recording = 'recording';
    case Completed = 'completed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Recording => 'Recording…',
            self::Completed => 'Recording complete',
            self::Expired => 'Recording expired',
        };
    }
}
