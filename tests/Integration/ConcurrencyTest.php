<?php

namespace Tests\Integration;

use App\Application\Demo\DemoSessionService;
use App\Application\Pipeline\TriagePipeline;
use App\Application\Review\ReviewService;
use App\Models\ActionRequest;
use App\Models\Message;
use App\Models\OutboxMessage;
use App\Models\SimulatedEffect;
use App\Models\TriageCase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Real parallelism: two forked PHP processes with their own database connections hit the same case at the same moment.
 * Runs against committed data (no wrapping transaction) and truncates afterwards.
 */
class ConcurrencyTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required');
        }
        $this->artisan('migrate:fresh', ['--seed' => true]);
        config(['sorthouse.resilience.sleep' => false]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public' and tablename not in ('migrations')"))->pluck('tablename')->map(fn ($t) => '"'.$t.'"')->implode(',');
        DB::statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");
        parent::tearDown();
    }

    /** @param list<callable():mixed> $jobs */
    private function race(array $jobs): array
    {
        $dir = sys_get_temp_dir().'/sorthouse-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                while (! file_exists("{$dir}/go")) {
                    usleep(1000);
                }
                try {
                    $job();
                    $out = 'ok';
                } catch (\Throwable $e) {
                    $out = class_basename($e).': '.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $out);
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        usleep(150_000);
        touch("{$dir}/go");
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge();
        DB::reconnect();
        $results = [];
        foreach (array_keys($jobs) as $i) {
            $results[] = file_get_contents("{$dir}/{$i}");
        }

        return $results;
    }

    public function test_two_reviewers_approving_simultaneously_produce_one_action(): void
    {
        $ws = app(DemoSessionService::class)->launch('1.1.1.1', preload: false)['workspace'];
        $case = app(DemoSessionService::class)->inject($ws, 'showcase-complaint')->case->refresh();
        $this->assertSame('awaiting_review', $case->state);
        $version = $case->version;

        $results = $this->race([
            fn () => app(ReviewService::class)->approve(TriageCase::find($case->id), $version, 'demo:reviewer-a'),
            fn () => app(ReviewService::class)->approve(TriageCase::find($case->id), $version, 'demo:reviewer-b'),
        ]);

        $this->assertCount(1, array_filter($results, fn ($r) => $r === 'ok'), json_encode($results));
        $this->assertCount(1, array_filter($results, fn ($r) => str_starts_with($r, 'VersionConflict')), json_encode($results));
        $this->assertSame(1, ActionRequest::count());
        $this->assertSame(1, OutboxMessage::count());
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame('executed', $case->refresh()->state);
    }

    public function test_same_reviewer_replaying_the_same_approval_in_parallel_is_one_action(): void
    {
        $ws = app(DemoSessionService::class)->launch('1.1.1.1', preload: false)['workspace'];
        $case = app(DemoSessionService::class)->inject($ws, 'showcase-complaint')->case->refresh();
        $version = $case->version;
        $this->race([
            fn () => app(ReviewService::class)->approve(TriageCase::find($case->id), $version, 'demo:reviewer-a'),
            fn () => app(ReviewService::class)->approve(TriageCase::find($case->id), $version, 'demo:reviewer-a'),
            fn () => app(ReviewService::class)->approve(TriageCase::find($case->id), $version, 'demo:reviewer-a'),
        ]);
        $this->assertSame(1, ActionRequest::count());
        $this->assertSame(1, OutboxMessage::count());
        $this->assertSame(1, SimulatedEffect::count());
    }

    public function test_two_pipeline_jobs_for_the_same_case_leave_one_current_run(): void
    {
        $ws = app(DemoSessionService::class)->launch('1.1.1.1', preload: false)['workspace'];
        config(['queue.default' => 'database']);
        $case = app(DemoSessionService::class)->inject($ws, 'showcase-invoice-pdf')->case;
        $this->assertSame('received', $case->refresh()->state);

        $results = $this->race([
            fn () => app(TriagePipeline::class)->run(TriageCase::find($case->id)),
            fn () => app(TriagePipeline::class)->run(TriageCase::find($case->id)),
        ]);
        $this->assertSame(['ok', 'ok'], $results);
        $case->refresh();
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(1, $case->classificationRuns()->count());
        $this->assertSame(1, $case->extractionRuns()->count());
        $this->assertSame(1, $case->extractionRuns()->where('is_current', true)->count());
        $this->assertSame(1, $case->policyDecisions()->count());
        $transitions = $case->auditEvents()->where('event', 'case.transition')->orderBy('created_at')->pluck('to_state')->all();
        $this->assertSame(['sanitizing', 'processing', 'classified', 'extracting', 'validating', 'awaiting_policy', 'awaiting_review'], $transitions, 'no incompatible or duplicated transitions');
    }

    public function test_ingesting_the_same_email_in_parallel_creates_one_case(): void
    {
        $ws = app(DemoSessionService::class)->launch('1.1.1.1', preload: false)['workspace'];
        config(['queue.default' => 'database']);
        $jobs = [];
        foreach (range(1, 6) as $i) {
            $jobs[] = fn () => app(DemoSessionService::class)->inject($ws, 'showcase-rfq', 'same-id-'.($i % 2));
        }
        $results = $this->race($jobs);
        $this->assertSame(array_fill(0, 6, 'ok'), $results);
        $this->assertSame(1, TriageCase::where('workspace_id', $ws->id)->count());
        $this->assertSame(5, Message::where('workspace_id', $ws->id)->first()->duplicate_count);
    }
}
