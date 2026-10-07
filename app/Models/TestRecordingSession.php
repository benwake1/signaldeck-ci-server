<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use App\Enums\RecordingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TestRecordingSession extends Model
{
    protected $fillable = [
        'token',
        'project_id',
        'ai_conversation_id',
        'status',
        'actions',
        'expires_at',
    ];

    protected $casts = [
        'status'     => RecordingStatus::class,
        'actions'    => 'array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TestRecordingSession $session) {
            if (empty($session->token)) {
                do {
                    $token = Str::random(32);
                } while (static::where('token', $token)->exists());

                $session->token = $token;
            }

            if (empty($session->actions)) {
                $session->actions = [];
            }
        });
    }

    public function isExpired(): bool
    {
        return $this->status === RecordingStatus::Expired || $this->expires_at->isPast();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
