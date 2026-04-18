<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['run_id', 'event_type', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TestRun::class, 'run_id');
    }
}
