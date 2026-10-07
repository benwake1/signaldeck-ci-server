<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    use Prunable;

    protected $fillable = [
        'ulid',
        'user_id',
        'project_id',
        'test_suite_id',
        'title',
        'messages',
        'crawl_data',
        'recording_data',
        'framework',
        'status',
        'verification_status',
        'verification_output',
        'total_tokens',
        'provider',
        'model',
        'repair_assessment',
    ];

    protected $casts = [
        'messages'             => 'array',
        'crawl_data'           => 'array',
        'recording_data'       => 'array',
        'status'               => ConversationStatus::class,
        'verification_status'  => VerificationStatus::class,
        'repair_assessment'    => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AiConversation $conversation) {
            if (empty($conversation->ulid)) {
                $conversation->ulid = (string) Str::ulid();
            }
        });
    }

    public function prunable()
    {
        // Completed conversations: prune after 90 days
        // Active/failed conversations with no updates: prune after 30 days
        return static::where(function ($query) {
            $query->where('status', ConversationStatus::Completed)
                ->where('updated_at', '<=', now()->subDays(90));
        })->orWhere(function ($query) {
            $query->whereIn('status', [ConversationStatus::Active, ConversationStatus::Failed])
                ->where('updated_at', '<=', now()->subDays(30));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }
}
