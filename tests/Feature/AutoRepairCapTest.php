<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Events\SuiteHealthBelowThreshold;
use App\Listeners\TriggerSuiteRepair;
use App\Models\AppSetting;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TestRepairService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AutoRepairCapTest extends TestCase
{
    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        Cache::flush();

        $this->spy = new class extends TestRepairService {
            public array $repaired = [];
            public function __construct() {}
            public function failingRunForRepair(TestSuite $suite): ?TestRun { return new TestRun(['status' => TestRun::STATUS_FAILED]); }
            public function attemptRepair(TestSuite $suite): void { $this->repaired[] = $suite->id; }
        };
        $this->app->instance(TestRepairService::class, $this->spy);

        AppSetting::set('auto_repair_enabled', '1');
    }

    private function breach(int $id, array $attrs = []): void
    {
        $suite = new TestSuite(array_merge(['source_type' => SourceType::Managed, 'base_url' => 'https://x.test'], $attrs));
        $suite->id = $id;

        (new TriggerSuiteRepair())->handle(new SuiteHealthBelowThreshold($suite, 50.0, 80.0));
    }

    public function test_daily_cap_stops_repairs_after_the_limit(): void
    {
        AppSetting::set('ai_auto_repair_daily_limit', 2);

        foreach ([1, 2, 3] as $id) {
            $this->breach($id);
        }

        $this->assertSame([1, 2], $this->spy->repaired);
    }

    public function test_zero_means_unlimited(): void
    {
        AppSetting::set('ai_auto_repair_daily_limit', 0);

        foreach (range(1, 8) as $id) {
            $this->breach($id);
        }

        $this->assertCount(8, $this->spy->repaired);
    }

    public function test_same_suite_is_only_repaired_once_per_day(): void
    {
        AppSetting::set('ai_auto_repair_daily_limit', 10);

        $this->breach(1);
        $this->breach(1);

        $this->assertSame([1], $this->spy->repaired);
    }

    public function test_skips_when_disabled_unmanaged_or_without_base_url(): void
    {
        $this->breach(1, ['base_url' => null]);
        $this->breach(2, ['source_type' => SourceType::Repo]);
        AppSetting::set('auto_repair_enabled', '0');
        $this->breach(3);

        $this->assertSame([], $this->spy->repaired);
    }
}
