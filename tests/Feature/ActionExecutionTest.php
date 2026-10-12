<?php

namespace Tests\Feature;

use App\Application\ActionExecution\OutboxProcessor;
use App\Application\Review\ReviewService;
use App\Jobs\ExecuteOutboxMessage;
use App\Jobs\SweepOutbox;
use App\Models\ActionRequest;
use App\Models\ActionRun;
use App\Models\OutboxMessage;
use App\Models\SimulatedEffect;
use App\Models\TriageCase;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ActionExecutionTest extends TestCase
{
    private function approvedComplaint(?Workspace $ws = null): TriageCase
    {
        $ws ??= $this->workspace();
        $case = $this->ingest($ws, 'showcase-complaint');

        return app(ReviewService::class)->approve($case, $case->version, 'demo:reviewer-a');
    }

    public function test_approve_does_not_execute_inside_the_http_request_it_writes_the_outbox(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-complaint');
        Queue::fake(); // from here on the worker does not run
        $case = app(ReviewService::class)->approve($case, $case->version, 'demo:reviewer-a');
        $this->assertSame('queued_action', $case->state);
        $this->assertSame(1, OutboxMessage::where('status', 'pending')->count());
        $this->assertSame(0, SimulatedEffect::count(), 'no effect before the worker runs');
        Queue::assertPushed(ExecuteOutboxMessage::class, 1);
    }

    public function test_duplicate_delivery_of_the_outbox_job_applies_the_effect_once(): void
    {
        $case = $this->approvedComplaint();
        $outbox = OutboxMessage::first();
        $processor = app(OutboxProcessor::class);
        $this->assertNull($processor->process($outbox->id));
        $this->assertNull($processor->process($outbox->id));
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame(1, ActionRun::count());
        $this->assertSame('executed', $case->refresh()->state);
    }

    public function test_worker_crash_after_action_request_commit_resumes_without_duplication(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-complaint');
        Queue::fake(); // the worker "died" before running the job
        $case = app(ReviewService::class)->approve($case, $case->version, 'demo:reviewer-a');
        $this->assertSame(1, ActionRequest::count());
        $this->assertSame(0, SimulatedEffect::count());

        // sweeper (or a redelivery) runs the same job twice
        $id = OutboxMessage::first()->id;
        app(OutboxProcessor::class)->process($id);
        app(OutboxProcessor::class)->process($id);
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame('executed', $case->refresh()->state);
    }

    public function test_timeout_after_send_goes_to_pending_reconciliation_and_never_blind_resends(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'action_timeout', 1);
        $case = $this->approvedComplaint($ws);
        $this->assertSame('pending_reconciliation', $case->state);
        $this->assertSame('pending_reconciliation', OutboxMessage::first()->status);
        $this->assertSame(1, SimulatedEffect::count(), 'the provider did apply the request even though we never saw the answer');
        $this->assertSame(['unknown_timeout'], ActionRun::pluck('outcome')->all());

        // a worker sweep before the reconcile window must not re-send
        app(OutboxProcessor::class)->process(OutboxMessage::first()->id);
        $this->assertSame(1, ActionRun::count());
    }

    public function test_reconciliation_finds_the_applied_effect_and_closes_without_resending(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'action_timeout', 1);
        $case = $this->approvedComplaint($ws);
        $result = app(OutboxProcessor::class)->reconcile(OutboxMessage::first()->id);
        $this->assertSame('reconciled_executed', $result);
        $this->assertSame('executed', $case->refresh()->state);
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame('executed', ActionRequest::first()->status);
    }

    public function test_reconciliation_resends_only_when_the_provider_confirms_the_effect_is_missing(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'action_timeout', 1);
        $case = $this->approvedComplaint($ws);
        SimulatedEffect::query()->delete(); // the provider never got it
        $id = OutboxMessage::first()->id;
        $this->assertSame('reconciled_missing', app(OutboxProcessor::class)->reconcile($id));
        $this->assertSame('queued_action', $case->refresh()->state);
        app(OutboxProcessor::class)->process($id);
        $this->assertSame('executed', $case->refresh()->state);
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame(['unknown_timeout', 'reconciled_missing', 'executed'], ActionRun::orderBy('created_at')->pluck('outcome')->all());
    }

    public function test_sweeper_reconciles_stale_pending_requests(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'action_timeout', 1);
        $case = $this->approvedComplaint($ws);
        OutboxMessage::query()->update(['updated_at' => now()->subMinutes(5)]);
        (new SweepOutbox)->handle(app(OutboxProcessor::class));
        $this->assertSame('executed', $case->refresh()->state);
        $this->assertSame(1, SimulatedEffect::count());
    }

    public function test_idempotency_key_is_derived_from_case_version_and_action_type(): void
    {
        $case = $this->approvedComplaint();
        $req = ActionRequest::first();
        $this->assertSame(hash('sha256', $case->id.'|'.$req->case_version.'|queue_reply'), $req->idempotency_key);
    }

    public function test_actions_are_simulated_and_labelled(): void
    {
        $this->approvedComplaint();
        $this->assertSame('SIMULATED', ActionRun::first()->mode);
    }
}
