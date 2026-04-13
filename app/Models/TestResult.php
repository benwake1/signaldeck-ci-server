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
use Illuminate\Support\Facades\Storage;

class TestResult extends Model
{
    protected $fillable = [
        'test_run_id',
        'spec_file',
        'suite_title',
        'test_title',
        'full_title',
        'status',
        'duration_ms',
        'error_message',
        'error_stack',
        'test_code',
        'screenshot_paths',
        'video_path',
        'attempt',
    ];

    protected $casts = [
        'screenshot_paths' => 'array',
        'duration_ms' => 'integer',
        'attempt' => 'integer',
    ];

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    public function getScreenshotUrlsAttribute(): array
    {
        if (!$this->screenshot_paths) return [];

        $disk = $this->testRun?->storage_disk ?? config('filesystems.default');

        return array_map(function (string $path) use ($disk): string {
            if ($disk === 's3') {
                return Storage::disk('s3')->temporaryUrl($path, now()->addDays(30));
            }
            return asset('storage/' . $path);
        }, $this->screenshot_paths);
    }

    public function getVideoUrlAttribute(): ?string
    {
        if (!$this->video_path) return null;

        $disk = $this->testRun?->storage_disk ?? config('filesystems.default');

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->video_path, now()->addDays(30));
        }

        return asset('storage/' . $this->video_path);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_ms) return '—';
        if ($this->duration_ms < 1000) return $this->duration_ms . 'ms';
        return round($this->duration_ms / 1000, 2) . 's';
    }

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
