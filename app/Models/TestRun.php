<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestRun extends Model
{
    protected $fillable = [
        'project_id',
        'test_suite_id',
        'triggered_by',
        'status',
        'branch',
        'commit_sha',
        'total_tests',
        'passed_tests',
        'failed_tests',
        'pending_tests',
        'duration_ms',
        'log_output',
        'error_message',
        'report_html_path',
        'merged_json_path',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'total_tests' => 'integer',
        'passed_tests' => 'integer',
        'failed_tests' => 'integer',
        'pending_tests' => 'integer',
        'duration_ms' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_CLONING = 'cloning';
    const STATUS_INSTALLING = 'installing';
    const STATUS_RUNNING = 'running';
    const STATUS_PASSING = 'passing';
    const STATUS_FAILED = 'failed';
    const STATUS_ERROR = 'error';
    const STATUS_CANCELLED = 'cancelled';

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }

    public function getPassRateAttribute(): float
    {
        if ($this->total_tests === 0) return 0;
        return round(($this->passed_tests / $this->total_tests) * 100, 1);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (!$this->duration_ms) return '—';
        $seconds = intdiv($this->duration_ms, 1000);
        $minutes = intdiv($seconds, 60);
        $seconds = $seconds % 60;
        return $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
    }

    public function getStatusColourAttribute(): string
    {
        return match($this->status) {
            'passing' => 'success',
            'failed' => 'danger',
            'running' => 'warning',
            'error' => 'danger',
            'cancelled' => 'gray',
            default => 'info',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match($this->status) {
            'passing' => 'heroicon-o-check-circle',
            'failed' => 'heroicon-o-x-circle',
            'running' => 'heroicon-o-arrow-path',
            'error' => 'heroicon-o-exclamation-triangle',
            'cancelled' => 'heroicon-o-minus-circle',
            'pending' => 'heroicon-o-clock',
            'cloning' => 'heroicon-o-arrow-down-tray',
            'installing' => 'heroicon-o-cog',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['passing', 'failed', 'error', 'cancelled']);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'cloning', 'installing', 'running']);
    }

    public function getReportHtmlUrlAttribute(): ?string
    {
        return $this->report_html_path ? route('reports.html', $this) : null;
    }

    public function getReportShareUrlAttribute(): ?string
    {
        if (!$this->report_html_path) return null;

        $expiry = now()->addDays(30)->timestamp;
        $token = hash_hmac('sha256', "report-{$this->id}-{$expiry}", config('app.key'));

        return route('reports.share', ['testRun' => $this->id, 'token' => $token, 'expires' => $expiry]);
    }
}
