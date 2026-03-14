<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return array_map(fn($path) => asset('storage/' . $path), $this->screenshot_paths);
    }

    public function getVideoUrlAttribute(): ?string
    {
        return $this->video_path ? asset('storage/' . $this->video_path) : null;
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
