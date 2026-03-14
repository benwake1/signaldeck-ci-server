<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'primary_colour',
        'secondary_colour',
        'accent_colour',
        'contact_name',
        'contact_email',
        'website',
        'report_footer_text',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (empty($client->slug)) {
                $client->slug = Str::slug($client->name);
            }
        });
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path) {
            return asset('storage/' . $this->logo_path);
        }
        return null;
    }

    public function getRecentRunsAttribute()
    {
        return TestRun::whereIn('project_id', $this->projects()->pluck('id'))
            ->latest()
            ->take(5)
            ->get();
    }

    public function getPassRateAttribute(): float
    {
        $runs = TestRun::whereIn('project_id', $this->projects()->pluck('id'))
            ->whereIn('status', ['passing', 'failed'])
            ->get();

        if ($runs->isEmpty()) return 0;

        $passed = $runs->where('status', 'passing')->count();
        return round(($passed / $runs->count()) * 100, 1);
    }
}
