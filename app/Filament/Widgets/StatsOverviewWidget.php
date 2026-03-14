<?php

namespace App\Filament\Widgets;

use App\Models\TestRun;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $recentRuns = TestRun::whereIn('status', ['passing', 'failed'])
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        $totalRuns   = $recentRuns->count();
        $passingRuns = $recentRuns->where('status', 'passing')->count();
        $failingRuns = $recentRuns->where('status', 'failed')->count();
        $passRate    = $totalRuns > 0 ? round(($passingRuns / $totalRuns) * 100, 1) : 0;

        $activeRuns = TestRun::whereIn('status', ['pending', 'cloning', 'installing', 'running'])->count();

        $avgDuration = TestRun::whereNotNull('duration_ms')
            ->where('created_at', '>=', now()->subDays(7))
            ->avg('duration_ms');
        $avgDurationFormatted = $avgDuration
            ? round($avgDuration / 1000 / 60, 1) . 'm'
            : '—';

        // Build trend data (last 14 days)
        $trend = collect(range(13, 0))->map(function ($daysAgo) {
            $day = now()->subDays($daysAgo)->toDateString();
            return TestRun::whereDate('created_at', $day)
                ->whereIn('status', ['passing', 'failed'])
                ->count();
        })->toArray();

        return [
            Stat::make('Pass Rate (30d)', $passRate . '%')
                ->description("{$passingRuns} passing / {$failingRuns} failing")
                ->descriptionIcon($passRate >= 80 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($passRate >= 80 ? 'success' : ($passRate >= 60 ? 'warning' : 'danger'))
                ->chart($trend),

            Stat::make('Total Runs (30d)', $totalRuns)
                ->description('Test executions this month')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('info')
                ->chart($trend),

            Stat::make('Currently Running', $activeRuns)
                ->description($activeRuns > 0 ? 'Active test jobs in queue' : 'No active runs')
                ->descriptionIcon('heroicon-m-clock')
                ->color($activeRuns > 0 ? 'warning' : 'gray'),

            Stat::make('Avg Duration (7d)', $avgDurationFormatted)
                ->description('Average test run time')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
        ];
    }
}
