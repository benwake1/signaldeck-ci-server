<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Models;

use App\Enums\RunnerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'slug',
        'description',
        'repo_url',
        'repo_provider',
        'default_branch',
        'deploy_key_private',
        'deploy_key_public',
        'runner_type',
        'playwright_available_projects',
        'env_variables',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'runner_type' => RunnerType::class,
        'playwright_available_projects' => 'array',
    ];

    protected $hidden = [
        'deploy_key_private',
    ];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name);
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    // Encrypt/decrypt deploy key
    public function setDeployKeyPrivateAttribute(?string $value): void
    {
        $this->attributes['deploy_key_private'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getDeployKeyPrivateAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    // Encrypt/decrypt env variables
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
            Log::warning('Failed to decrypt env variables for project', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function generateDeployKey(): array
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'ssh_key_');
        unlink($keyPath); // ssh-keygen won't overwrite an existing file
        exec('ssh-keygen -t ed25519 -C ' . escapeshellarg(config('app.name')) . ' -f ' . escapeshellarg($keyPath) . ' -N ' . escapeshellarg('') . ' -q 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($keyPath) || !file_exists($keyPath . '.pub')) {
            throw new \RuntimeException('ssh-keygen failed: ' . implode("\n", $output));
        }

        $private = file_get_contents($keyPath);
        $public = file_get_contents($keyPath . '.pub');

        unlink($keyPath);
        unlink($keyPath . '.pub');

        $this->deploy_key_private = $private;
        $this->deploy_key_public = $public;
        $this->save();

        return ['private' => $private, 'public' => $public];
    }

    public function isCypress(): bool
    {
        return ($this->runner_type ?? RunnerType::Cypress) === RunnerType::Cypress;
    }

    public function isPlaywright(): bool
    {
        return $this->runner_type === RunnerType::Playwright;
    }

    public function getLatestRunAttribute(): ?TestRun
    {
        return $this->testRuns()->latest()->first();
    }

    public function getPassRateAttribute(): float
    {
        $counts = $this->testRuns()
            ->whereIn('status', ['passing', 'failed'])
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'passing' THEN 1 ELSE 0 END) as passed")
            ->first();

        if (!$counts || (int) $counts->total === 0) return 0.0;
        return round(((int) $counts->passed / (int) $counts->total) * 100, 1);
    }
}
