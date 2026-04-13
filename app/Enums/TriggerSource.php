<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Enums;

enum TriggerSource: string
{
    case Manual   = 'manual';
    case Webhook  = 'webhook';
    case Schedule = 'schedule';
    case Api      = 'api';

    public function label(): string
    {
        return match($this) {
            self::Manual   => 'Manual',
            self::Webhook  => 'Webhook',
            self::Schedule => 'Schedule',
            self::Api      => 'API',
        };
    }
}
