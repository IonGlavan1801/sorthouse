<?php

namespace Tests\Feature;

use App\Application\Pipeline\InvalidTransition;
use App\Application\Pipeline\PolicyViolation;
use App\Application\Pipeline\VersionConflict;
use App\Application\Review\ReviewService;
use App\Http\Presenters\CasePresenter;
use App\Models\ActionRequest;
use App\Models\AuditEvent;
use App\Models\AutomationPolicy;
use App\Models\FieldCorrection;
use App\Models\OutboxMessage;
use App\Models\PolicyVersion;
use App\Models\ReviewDecision;
use App\Models\SimulatedEffect;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    private function review(): ReviewService
    {
        return app(ReviewService::class);
    }

    public function test_approve_queues_one_action_through_the_outbox_and_executes_simulated(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(0, ActionRequest::count(), 'nothing is queued before approval');
        $this->assertSame(0, $case->drafts()->where('status', '!=', 'draft')->count());

        $case = $this->review()->approve($case, $case->version, 'demo:reviewer-a');
        $this->assertSame('executed', $case->state);
        $this->assertSame(1, ActionRequest::count());
        $this->assertSame(1, OutboxMessage::count());
        $this->assertSame('done', OutboxMessage::first()->status);
        $this->assertSame(1, SimulatedEffect::count());
        $effect = SimulatedEffect::first();
        $this->assertSame('queue_reply', $effect->action_type);
        $this->assertSame('elena.costa@customer-mail.example.test', $effect->payload['to'], 'recipient is server-owned (the original sender)');
        $this->assertSame('queued', $case->drafts()->first()->status);
        $states = AuditEvent::where('triage_case_id', $case->id)->where('event', 'case.transition')->pluck('to_state')->all();
        $this->assertSame(['approved', 'queued_action', 'executing', 'executed'], array_slice($states, -4));
    }

    public function test_second_approval_with_the_same_version_conflicts_and_does_not_duplicate(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $v = $case->version;
        $this->review()->approve($case->refresh(), $v, 'demo:reviewer-a');
        $this->expectException(VersionConflict::class);
        try {
            $this->review()->approve($case->refresh(), $v, 'demo:reviewer-b');
        } finally {
            $this->assertSame(1, ActionRequest::count());
            $this->assertSame(1, OutboxMessage::count());
            $this->assertSame(1, SimulatedEffect::count());
        }
    }

    public function test_replayed_approve_by_the_same_actor_is_idempotent(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $v = $case->version;
        $this->review()->approve($case->refresh(), $v, 'demo:reviewer-a');
        $this->review()->approve($case->refresh(), $v, 'demo:reviewer-a');
        $this->review()->approve($case->refresh(), $v, 'demo:reviewer-a');
        $this->assertSame(1, ActionRequest::count());
        $this->assertSame(1, OutboxMessage::count());
        $this->assertSame(1, SimulatedEffect::count());
        $this->assertSame(2, AuditEvent::where('event', 'review.approve_replayed')->count());
    }

    public function test_stale_version_is_refused_instead_of_approving_an_old_snapshot(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $this->review()->correct($case->refresh(), $case->version, 'demo:reviewer-a', ['fields' => ['order_number' => 'ORD-00001']]);
        $this->expectException(VersionConflict::class);
        $this->review()->approve($case->refresh(), $case->version - 1 > 0 ? 1 : 1, 'demo:reviewer-a'); // v1 is long gone
    }

    public function test_reject_closes_the_case_with_a_reason_and_nothing_executes(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $case = $this->review()->reject($case, $case->version, 'demo:reviewer-a', 'not_actionable', 'test');
        $this->assertSame('rejected', $case->state);
        $this->assertSame(0, ActionRequest::count());
        $this->assertSame(0, SimulatedEffect::count());
        $d = ReviewDecision::first();
        $this->assertSame(['rejected', 'not_actionable'], [$d->decision, $d->reason_code]);
        $this->expectException(InvalidTransition::class);
        $this->review()->approve($case->refresh(), $case->version, 'demo:reviewer-a');
    }

    public function test_reject_requires_a_reason_from_the_list(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $this->expectException(PolicyViolation::class);
        $this->review()->reject($case, $case->version, 'demo:reviewer-a', 'because');
    }

    public function test_correction_keeps_the_original_prediction_and_reruns_validation(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-invoice-photo');
        $before = collect(app(CasePresenter::class)->detail($case)['validation']['issues'])->pluck('code')->all();
        $this->assertContains('LOW_OCR_CONFIDENCE', $before);

        $out = $this->review()->correct($case, $case->version, 'demo:reviewer-a', ['fields' => ['invoice_number' => 'INV-40881'], 'reason' => 'OCR confused 8/B', 'error_category' => 'extraction_wrong']);
        $case = $out['case'];
        $this->assertSame('awaiting_review', $case->state);
        $detail = app(CasePresenter::class)->detail($case);
        $field = collect($detail['extraction']['fields'])->firstWhere('name', 'invoice_number');
        $this->assertSame('INV-4OBBl', $field['predicted'], 'the original prediction is untouched');
        $this->assertSame('INV-40881', $field['effective']);
        $this->assertTrue($field['corrected']);
        $this->assertSame('valid', $field['status']);
        $this->assertNotContains('LOW_OCR_CONFIDENCE', array_column($detail['validation']['issues'], 'code'));
        $c = FieldCorrection::first();
        $this->assertSame(['INV-4OBBl', 'INV-40881', 'demo:reviewer-a', 'extraction_wrong'], [$c->original_value, $c->corrected_value, $c->actor, $c->error_category]);
        $this->assertSame('production_demo', $c->dataset, 'corrections never flow into the evaluation holdout');
        $this->assertSame(2, $case->policyDecisions()->count(), 'policy was evaluated again after the correction');
        $this->assertSame(0, ActionRequest::count(), 'a correction never executes anything by itself');
    }

    public function test_correction_changes_the_export_payload_that_gets_approved(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-invoice-photo');
        $out = $this->review()->correct($case, $case->version, 'demo:reviewer-a', ['fields' => ['invoice_number' => 'INV-40881']]);
        $case = $this->review()->approve($out['case'], $out['case']->version, 'demo:reviewer-a');
        $this->assertSame('executed', $case->state);
        $this->assertSame('INV-40881', ActionRequest::first()->params['payload']['invoice_number']);
        $this->assertSame('invoice.v1', ActionRequest::first()->params['schema_version']);
    }

    public function test_correcting_a_total_to_a_mismatch_is_caught_by_revalidation(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-invoice-pdf');
        $out = $this->review()->correct($case, $case->version, 'demo:reviewer-a', ['fields' => ['total' => '1.00 EUR']]);
        $codes = array_column($out['issues_after'], 'code');
        $this->assertContains('TOTAL_MISMATCH', $codes);
    }

    public function test_choosing_the_category_for_an_uncertain_message_continues_the_pipeline(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-ambiguous');
        $this->assertSame('other_or_uncertain', $case->category);
        $out = $this->review()->correct($case, $case->version, 'demo:reviewer-a', ['category' => 'customer_complaint']);
        $case = $out['case']->refresh();
        $this->assertSame('customer_complaint', $case->category);
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(1.0, (float) $case->confidence_signal ?: 1.0);
        $this->assertSame('complaint.v1', $case->extractionRuns()->first()->schema_version);
        $this->assertTrue($case->flags['category_corrected']);
        $this->assertNotNull($case->classificationRuns()->first(), 'the original model classification stays in history');
    }

    public function test_policy_version_used_is_kept_in_the_decision_history_after_a_change(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-spam');
        $first = $case->policyDecisions()->first();
        $this->assertSame('policy.v1', $first->policyVersion->version);

        $policy = AutomationPolicy::first();
        $policy->update(['active_version_id' => PolicyVersion::where('version', 'policy.v2-candidate')->first()->id]);
        $other = $this->ingest($ws, 'showcase-general');
        $this->assertSame('policy.v2-candidate', $other->policyDecisions()->first()->policyVersion->version);
        $this->assertSame('policy.v1', $first->fresh()->policyVersion->version, 'older decisions keep the version they were made under');
    }

    public function test_blocked_case_cannot_be_approved(): void
    {
        $case = $this->ingest($this->workspace(), 'adv-injection-email');
        $this->assertSame('blocked', $case->state);
        $this->expectException(InvalidTransition::class);
        $this->review()->approve($case, $case->version, 'demo:reviewer-a');
    }

    public function test_every_transition_has_an_actor_and_a_version(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $this->review()->approve($case->refresh(), $case->version, 'demo:reviewer-a');
        foreach (AuditEvent::where('triage_case_id', $case->id)->where('event', 'case.transition')->get() as $e) {
            $this->assertNotEmpty($e->actor);
            $this->assertNotNull($e->case_version);
            $this->assertNotNull($e->to_state);
        }
    }
}
