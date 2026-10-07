<?php

namespace Tests\Feature;

use App\DTOs\AiGenerationResult;
use App\DTOs\CrawlResult;
use App\Events\SuiteHealthBelowThreshold;
use App\Events\SuiteHealthBreached;
use App\Jobs\CheckSuiteHealthJob;
use App\Jobs\NotifyRepairCompletedJob;
use App\Jobs\VerifyGeneratedTestJob;
use App\Listeners\TriggerSuiteRepair;
use App\Mail\SuiteRepairProposedMailable;
use App\Models\AiConversation;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\AiTestGeneratorService;
use App\Services\ManagedSuiteService;
use App\Services\SiteCrawlerService;
use App\Services\TestRepairService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AutoRepairEligibilityTest extends TestCase
{
    private const SPEC = "it('logs in', () => {\n  cy.get('#login').click();\n  cy.get('.welcome').should('be.visible');\n  expect(1).to.equal(1);\n});";

    private TestSuite $suite;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        Cache::flush();
        Bus::fake();

        AppSetting::set('auto_repair_enabled', '1');
        AppSetting::set('ai_auto_repair_daily_limit', 0);

        $this->app->instance(SiteCrawlerService::class, new class extends SiteCrawlerService {
            public function __construct() {}

            public function crawl(string $url, array $options = []): CrawlResult
            {
                return new CrawlResult($url, 'Login', [['tag' => 'button', 'text' => 'Sign in', 'id' => 'sign-in']], [], [], []);
            }
        });

        $client = Client::create(['name' => 'C', 'slug' => 'c']);
        $project = Project::create(['client_id' => $client->id, 'name' => 'P', 'slug' => 'p', 'repo_url' => 'https://example.test/r.git', 'default_branch' => 'main']);

        $this->suite = app(ManagedSuiteService::class)->create(
            $project->id, 'cypress', 'Login', ['cypress/e2e/login.cy.js' => self::SPEC], 'https://example.cypress.io', null,
        );
    }

    private function runs(string ...$statuses): void
    {
        foreach ($statuses as $i => $status) {
            $run = new TestRun([
                'project_id' => $this->suite->project_id,
                'test_suite_id' => $this->suite->id,
                'branch' => 'main',
                'error_message' => 'Timed out retrying: expected .welcome to be visible',
            ]);
            $run->status = $status;
            $run->created_at = now()->subMinutes(100 - $i);
            $run->save();
        }
    }

    private function fakeAi(string $content, array $files = []): void
    {
        $this->app->instance(AiTestGeneratorService::class, new class($content, $files) extends AiTestGeneratorService {
            public function __construct(private string $content, private array $files) {}

            public function refine(AiConversation $conversation, string $feedback, string $framework = 'cypress'): AiGenerationResult
            {
                return new AiGenerationResult($this->files, $this->content, [], ['input' => 0, 'output' => 0]);
            }
        });
    }

    private function breach(): void
    {
        (new TriggerSuiteRepair())->handle(new SuiteHealthBelowThreshold($this->suite, 0.0, 80.0));
    }

    public function test_requires_three_consecutive_failures_by_default(): void
    {
        $this->runs(TestRun::STATUS_PASSING, TestRun::STATUS_FAILED, TestRun::STATUS_FAILED);
        $this->assertNull(app(TestRepairService::class)->failingRunForRepair($this->suite));

        $this->runs(TestRun::STATUS_FAILED);
        $this->assertNotNull(app(TestRepairService::class)->failingRunForRepair($this->suite));
    }

    public function test_errored_runs_break_the_streak(): void
    {
        $this->runs(TestRun::STATUS_FAILED, TestRun::STATUS_ERROR, TestRun::STATUS_FAILED, TestRun::STATUS_FAILED);
        $this->assertNull(app(TestRepairService::class)->failingRunForRepair($this->suite));

        $this->suite->testRuns()->delete();
        $this->runs(TestRun::STATUS_FAILED, TestRun::STATUS_FAILED, TestRun::STATUS_ERROR);
        $this->assertNull(app(TestRepairService::class)->failingRunForRepair($this->suite));
    }

    public function test_waits_for_an_in_flight_run(): void
    {
        $this->runs(TestRun::STATUS_FAILED, TestRun::STATUS_FAILED, TestRun::STATUS_FAILED, TestRun::STATUS_RUNNING);
        $this->assertNull(app(TestRepairService::class)->failingRunForRepair($this->suite));
    }

    public function test_threshold_is_configurable_but_never_below_three(): void
    {
        AppSetting::set('ai_auto_repair_consecutive_failures', 1);
        $this->assertSame(3, TestRepairService::requiredConsecutiveFailures());

        AppSetting::set('ai_auto_repair_consecutive_failures', 5);
        $this->runs(...array_fill(0, 4, TestRun::STATUS_FAILED));
        $this->assertNull(app(TestRepairService::class)->failingRunForRepair($this->suite));

        $this->runs(TestRun::STATUS_FAILED);
        $this->assertNotNull(app(TestRepairService::class)->failingRunForRepair($this->suite));
    }

    public function test_early_breach_does_not_use_up_the_daily_attempt(): void
    {
        $this->fakeAi('ASSESSMENT: app_regression — login returns 500');

        $this->runs(TestRun::STATUS_FAILED);
        $this->breach();
        $this->assertSame(0, AiConversation::count());

        $this->runs(TestRun::STATUS_FAILED, TestRun::STATUS_FAILED);
        $this->breach();
        $this->assertSame(1, AiConversation::count());
    }

    public function test_app_regression_skips_verification_and_notifies(): void
    {
        $this->runs(...array_fill(0, 3, TestRun::STATUS_FAILED));
        $this->fakeAi("ASSESSMENT: app_regression — The login endpoint returns HTTP 500.\n\nThe test is correctly catching a broken login.");

        app(TestRepairService::class)->attemptRepair($this->suite);

        $conversation = AiConversation::firstOrFail();
        $this->assertSame('app_regression', $conversation->repair_assessment['assessment']);
        $this->assertSame('The login endpoint returns HTTP 500.', $conversation->repair_assessment['reason']);
        $this->assertFalse($conversation->repair_assessment['proposes_fix']);
        $this->assertNull($conversation->verification_status);
        $this->assertSame('Sign in', $conversation->crawl_data['interactive_elements'][0]['text']);

        Bus::assertDispatched(NotifyRepairCompletedJob::class);
        Bus::assertNotDispatched(VerifyGeneratedTestJob::class);
    }

    public function test_repair_still_runs_when_the_live_page_cannot_be_crawled(): void
    {
        $this->app->instance(SiteCrawlerService::class, new class extends SiteCrawlerService {
            public function __construct() {}

            public function crawl(string $url, array $options = []): CrawlResult
            {
                throw new \RuntimeException('net::ERR_NAME_NOT_RESOLVED');
            }
        });
        $this->runs(...array_fill(0, 3, TestRun::STATUS_FAILED));
        $this->fakeAi('ASSESSMENT: unclear — no page context');

        app(TestRepairService::class)->attemptRepair($this->suite);

        $this->assertSame(['url' => 'https://example.cypress.io'], AiConversation::firstOrFail()->crawl_data);
        Bus::assertDispatched(NotifyRepairCompletedJob::class);
    }

    public function test_files_without_a_drift_assessment_are_not_proposed(): void
    {
        $this->runs(...array_fill(0, 3, TestRun::STATUS_FAILED));
        $this->fakeAi('Here is a fix.', ['cypress/e2e/login.cy.js' => "it('logs in', () => {});"]);

        app(TestRepairService::class)->attemptRepair($this->suite);

        $this->assertSame('unclear', AiConversation::firstOrFail()->repair_assessment['assessment']);
        Bus::assertNotDispatched(VerifyGeneratedTestJob::class);
    }

    public function test_drift_fix_is_verified_and_removed_assertions_are_recorded(): void
    {
        $this->runs(...array_fill(0, 3, TestRun::STATUS_FAILED));
        $fixed = "it('logs in', () => {\n  cy.get('[data-test=login]').click();\n  expect(1).to.equal(1);\n});";
        $this->fakeAi('**ASSESSMENT:** test_drift — the login button id was renamed', ['cypress/e2e/login.cy.js' => $fixed]);

        app(TestRepairService::class)->attemptRepair($this->suite);

        $assessment = AiConversation::firstOrFail()->repair_assessment;
        $this->assertTrue($assessment['proposes_fix']);
        $this->assertSame('the login button id was renamed', $assessment['reason']);
        $this->assertSame(
            [['file' => 'cypress/e2e/login.cy.js', 'line' => "cy.get('.welcome').should('be.visible');"]],
            $assessment['removed_assertions'],
        );

        Bus::assertChained([VerifyGeneratedTestJob::class, NotifyRepairCompletedJob::class]);
    }

    public function test_email_reflects_the_assessment(): void
    {
        $this->runs(...array_fill(0, 3, TestRun::STATUS_FAILED));
        $this->fakeAi('ASSESSMENT: app_regression — login returns 500');
        app(TestRepairService::class)->attemptRepair($this->suite);

        $mail = new SuiteRepairProposedMailable(AiConversation::firstOrFail(), $this->suite);
        $this->assertStringContainsString('Possible app regression', $mail->envelope()->subject);
        $mail->assertSeeInHtml('login returns 500');
        $mail->assertSeeInHtml('View diagnosis');

        AiConversation::query()->delete();
        $this->fakeAi('ASSESSMENT: test_drift — id renamed', ['cypress/e2e/login.cy.js' => "it('logs in', () => {});"]);
        app(TestRepairService::class)->attemptRepair($this->suite);

        $mail = new SuiteRepairProposedMailable(AiConversation::firstOrFail(), $this->suite);
        $this->assertStringContainsString('Proposed fix needs review', $mail->envelope()->subject);
        $mail->assertSeeInHtml('removes, changes or skips checks', false);
        $mail->assertSeeInHtml("cy.get('.welcome').should('be.visible');");
    }

    public function test_repair_trigger_is_not_throttled_by_the_alert_cooldown(): void
    {
        Event::fake([SuiteHealthBelowThreshold::class, SuiteHealthBreached::class]);
        $this->suite->forceFill(['pass_rate_threshold' => 80, 'last_breach_at' => now()->subMinutes(5)])->save();
        $this->runs(TestRun::STATUS_FAILED);

        (new CheckSuiteHealthJob(TestRun::firstOrFail()))->handle();

        Event::assertNotDispatched(SuiteHealthBreached::class);
        Event::assertDispatched(SuiteHealthBelowThreshold::class);
    }
}
