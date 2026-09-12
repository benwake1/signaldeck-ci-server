<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use App\Enums\RunnerType;
use App\Enums\SourceType;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestSuite extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'source_type',
        'runner_type',
        'name',
        'slug',
        'description',
        'spec_pattern',
        'branch_override',
        'base_url',
        'env_variables',
        'playwright_projects',
        'playwright_workers',
        'playwright_retries',
        'timeout_minutes',
        'active',
        'schedule_cron',
        'schedule_enabled',
        'schedule_timezone',
        'last_scheduled_at',
        'pass_rate_threshold',
    ];

    protected $casts = [
        'source_type' => SourceType::class,
        'runner_type' => RunnerType::class,
        'active' => 'boolean',
        'timeout_minutes' => 'integer',
        'playwright_projects' => 'array',
        'playwright_workers' => 'integer',
        'playwright_retries' => 'integer',
        'schedule_enabled'    => 'boolean',
        'last_scheduled_at'   => 'datetime',
        'pass_rate_threshold' => 'float',
        'last_breach_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TestSuite $suite) {
            if (empty($suite->slug)) {
                $suite->slug = Str::slug($suite->name);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    public function managedTestFiles(): HasMany
    {
        return $this->hasMany(ManagedTestFile::class);
    }

    public function isManaged(): bool
    {
        return ($this->source_type ?? SourceType::Repo) === SourceType::Managed;
    }

    /**
     * The runner type for this suite. Falls back to the project's runner type
     * when the suite doesn't have its own (i.e. repo-based suites).
     */
    public function getEffectiveRunnerType(): RunnerType
    {
        return $this->runner_type ?? $this->project->runner_type ?? RunnerType::Cypress;
    }

    public function setEnvVariablesAttribute(?array $value): void
    {
        $this->attributes['env_variables'] = $value ? Crypt::encryptString(json_encode($value)) : null;
    }

    public function getEnvVariablesAttribute(?string $value): array
    {
        if (!$value) return [];
        try {
            return json_decode(Crypt::decryptString($value), true) ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to decrypt env variables for test suite', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function getEffectiveBranchAttribute(): string
    {
        return $this->branch_override ?? $this->project->default_branch;
    }

    public function getMergedEnvVariablesAttribute(): array
    {
        return array_merge(
            $this->project->env_variables,
            $this->env_variables
        );
    }

    public function getLatestRunAttribute(): ?TestRun
    {
        return $this->testRuns()->latest()->first();
    }

    /**
     * Next scheduled run time based on the suite's cron expression and timezone.
     * Returns null if scheduling is disabled or the expression is invalid.
     */
    public function nextRunAt(): ?\Carbon\Carbon
    {
        if (!$this->schedule_enabled || !$this->schedule_cron) {
            return null;
        }

        try {
            $tz       = $this->schedule_timezone ?? config('app.timezone');
            $nextDate = (new CronExpression($this->schedule_cron))
                ->getNextRunDate('now', 0, false, $tz);
            return \Carbon\Carbon::instance($nextDate);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pass rate computed from the last 10 completed runs for this suite.
     * Returns 0.0 if no completed runs exist.
     */
    public function getHealthScoreAttribute(): float
    {
        $recentRuns = $this->testRuns()
            ->whereIn('status', ['passing', 'failed'])
            ->latest()
            ->limit(10)
            ->get();

        if ($recentRuns->isEmpty()) {
            return 0.0;
        }

        return round(
            $recentRuns->where('status', 'passing')->count() / $recentRuns->count() * 100,
            1
        );
    }

    /**
     * True when a threshold is configured and the current health score is below it.
     */
    public function getIsHealthBreachedAttribute(): bool
    {
        if ($this->pass_rate_threshold === null) {
            return false;
        }

        return $this->health_score < $this->pass_rate_threshold;
    }
}
