<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TestSuite extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'description',
        'spec_pattern',
        'branch_override',
        'env_variables',
        'timeout_minutes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'timeout_minutes' => 'integer',
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
}
