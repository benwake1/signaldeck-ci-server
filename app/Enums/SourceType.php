<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Enums;

enum SourceType: string
{
    case Repo = 'repo';
    case Managed = 'managed';

    public function label(): string
    {
        return match ($this) {
            self::Repo => 'Repository',
            self::Managed => 'Managed',
        };
    }
}
