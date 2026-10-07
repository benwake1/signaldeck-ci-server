<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Pages;

use App\Models\Project;
use App\Models\TestResult;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class FlakyTests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Flaky Tests';
    protected static ?string $navigationGroup = 'Testing';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.flaky-tests';

    // All authenticated dashboard users (admin + pm) may view test analytics.
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public ?int $projectFilter = null;
    public int $minScore = 0;

    // Used to bust the table cache when filters change
    public string $tableFiltersKey = '';

    private string $flakyScoreSql = "ROUND((CASE WHEN SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) <= SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) THEN SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) ELSE SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) END / CAST(COUNT(*) AS FLOAT)) * 100, 1)";

    public function mount(): void
    {
        $this->projectFilter = request()->integer('project') ?: null;
    }

    public function updatedProjectFilter(): void
    {
        $this->resetTable();
    }

    public function updatedMinScore(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $sql = $this->flakyScoreSql;

        // "Recent statuses" for the dots column are batch-fetched separately (see
        // getRecentStatusesMap()) once the current page's rows are known, instead of
        // via a correlated subquery here — see that method's docblock for why.
        $query = TestResult::query()
            ->join('test_runs', 'test_runs.id', '=', 'test_results.test_run_id')
            ->join('projects', 'projects.id', '=', 'test_runs.project_id')
            ->whereIn('test_results.status', ['passed', 'failed'])
            ->select([
                DB::raw('MAX(test_results.id) as id'),
                'test_results.spec_file',
                'test_results.full_title',
                DB::raw('projects.name as project_name'),
                DB::raw('projects.id as project_id'),
                DB::raw('COUNT(*) as run_count'),
                DB::raw("SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) as pass_count"),
                DB::raw("SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) as fail_count"),
                DB::raw("{$sql} as flaky_score"),
                DB::raw('MAX(test_results.created_at) as last_seen'),
            ])
            ->groupBy('test_results.spec_file', 'test_results.full_title', 'projects.id', 'projects.name')
            ->havingRaw('COUNT(*) >= 3')
            ->havingRaw("SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) > 0")
            ->havingRaw("SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) > 0");

        if ($this->projectFilter) {
            $query->where('projects.id', $this->projectFilter);
        }

        if ($this->minScore > 0) {
            $query->havingRaw("{$sql} >= ?", [$this->minScore]);
        }

        return $table
            ->query($query)
            ->defaultSort('flaky_score', 'desc')
            ->columns([
                TextColumn::make('full_title')
                    ->label('Test')
                    ->description(fn ($record) => basename($record->spec_file))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('project_name')
                    ->label('Project')
                    ->badge()
                    ->color('info'),
                TextColumn::make('flaky_score')
                    ->label('Flakiness')
                    ->badge()
                    ->color(fn ($state) => (float) $state >= 40 ? 'danger' : ((float) $state >= 20 ? 'warning' : 'info'))
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->sortable(),
                TextColumn::make('pass_count')
                    ->label('Pass Rate')
                    ->badge()
                    ->formatStateUsing(fn ($state, $record) => $record->run_count > 0
                        ? round(($state / $record->run_count) * 100) . '%'
                        : '0%')
                    ->color(fn ($state, $record) => match(true) {
                        $record->run_count > 0 && round(($state / $record->run_count) * 100) >= 80 => 'success',
                        $record->run_count > 0 && round(($state / $record->run_count) * 100) >= 50 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('recent_runs')
                    ->label('Last 10 Runs')
                    ->state(fn ($record) => $this->buildDotsHtml(
                        $this->getRecentStatusesMap()[$this->recentStatusesKey($record->project_id, $record->spec_file, $record->full_title)] ?? ''
                    ))
                    ->html(),
                TextColumn::make('last_seen')
                    ->label('Last Seen')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Action::make('history')
                    ->label('History')
                    ->url(fn ($record) => TestHistory::getUrl([
                        'project' => $record->project_id,
                        'spec'    => urlencode($record->spec_file),
                        'title'   => urlencode($record->full_title),
                    ]))
                    ->button()
                    ->size(ActionSize::Small)
                    ->color('info')
                    ->extraAttributes(['class' => '!rounded-full']),
            ])
            ->emptyStateHeading('No flaky tests detected 🎉')
            ->emptyStateDescription('Tests need at least 3 runs with mixed pass/fail results to be flagged as flaky.')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    private ?array $recentStatusesByKey = null;

    private function recentStatusesKey(int $projectId, string $specFile, string $fullTitle): string
    {
        return $projectId . '|' . $specFile . '|' . $fullTitle;
    }

    /**
     * Batch-fetch the last 10 run statuses per (project, spec, title) group, scoped
     * to only the rows on the current table page.
     *
     * This used to be a correlated subquery in the main GROUP BY query, evaluated
     * once per test_result row. MySQL's "aggregate using temporary table" strategy
     * ran that subquery during the *pre-aggregation* join — once per source row
     * (thousands of times), not once per output group — and each execution sorted
     * every cross-project row sharing the same spec_file/full_title before filtering
     * by project_id, because full_title/spec_file collide across seeded demo
     * projects. That's what timed out once test_results grew large; pagination and
     * indexing alone couldn't fix it because the subquery still ran against the
     * whole table on every page load.
     *
     * Fetching it here instead, once per page render for only the ~10-50 groups
     * actually displayed, keeps the cost bounded regardless of table growth.
     */
    private function getRecentStatusesMap(): array
    {
        if ($this->recentStatusesByKey !== null) {
            return $this->recentStatusesByKey;
        }

        $tuples = $this->getTableRecords()
            ->map(fn ($record) => [(int) $record->project_id, $record->spec_file, $record->full_title])
            ->unique(fn (array $tuple) => implode('|', $tuple))
            ->values();

        if ($tuples->isEmpty()) {
            return $this->recentStatusesByKey = [];
        }

        $placeholders = $tuples->map(fn () => '(?, ?, ?)')->implode(', ');
        $bindings = $tuples->flatMap(fn (array $tuple) => $tuple)->all();

        $rows = DB::select("
            SELECT project_id, spec_file, full_title, GROUP_CONCAT(status ORDER BY rn) as recent_statuses
            FROM (
                SELECT
                    runs.project_id,
                    tr.spec_file,
                    tr.full_title,
                    tr.status,
                    ROW_NUMBER() OVER (
                        PARTITION BY runs.project_id, tr.spec_file, tr.full_title
                        ORDER BY tr.created_at DESC
                    ) as rn
                FROM test_results tr
                INNER JOIN test_runs runs ON runs.id = tr.test_run_id
                WHERE tr.status IN ('passed', 'failed')
                  AND (runs.project_id, tr.spec_file, tr.full_title) IN ({$placeholders})
            ) ranked
            WHERE rn <= 10
            GROUP BY project_id, spec_file, full_title
        ", $bindings);

        $map = [];
        foreach ($rows as $row) {
            $map[$this->recentStatusesKey((int) $row->project_id, $row->spec_file, $row->full_title)] = $row->recent_statuses;
        }

        return $this->recentStatusesByKey = $map;
    }

    /**
     * Build dot-indicator HTML from a comma-separated status string, newest-first
     * (see getRecentStatusesMap()).
     */
    private function buildDotsHtml(string $recentStatuses): string
    {
        if ($recentStatuses === '') {
            return '<div class="flex items-center gap-0.5"></div>';
        }

        // GROUP_CONCAT returns newest-first; reverse so oldest is on the left.
        $statuses = array_reverse(explode(',', $recentStatuses));

        $html = '<div class="flex items-center gap-0.5">';
        foreach ($statuses as $status) {
            $color = $status === 'passed' ? 'bg-green-400' : 'bg-red-400';
            $html .= '<span class="inline-block w-3 h-3 rounded-full ' . $color . '" title="' . htmlspecialchars($status) . '"></span>';
        }
        $html .= '</div>';

        return $html;
    }

    public function getProjects(): array
    {
        return Project::orderBy('name')->pluck('name', 'id')->toArray();
    }
}
