<?php

namespace Tests\Feature;

use App\Application\Pipeline\SimulatedWorkerCrash;
use App\Application\Pipeline\TriagePipeline;
use App\Application\Review\ReviewService;
use App\Http\Presenters\CasePresenter;
use App\Models\ClassificationRun;
use App\Models\ExtractionRun;
use App\Models\ProviderCall;
use App\Models\StageTiming;
use App\Models\TriageCase;
use App\Models\ValidationIssue;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    private function issues($case): array
    {
        return ValidationIssue::whereIn('validation_run_id', $case->validationRuns()->pluck('id'))->pluck('code')->all();
    }

    public function test_clear_pdf_invoice_is_valid_and_ready_for_approval(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-invoice-pdf');
        $detail = app(CasePresenter::class)->detail($case);
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame('invoice', $case->category);
        $this->assertTrue($detail['validation']['schema_valid']);
        $this->assertSame([], $detail['validation']['issues']);
        $this->assertSame(['HIGH_IMPACT_ACTION'], $this->codes($case), 'only reason for review: financial actions are approval-only');
        $this->assertSame('INV-20481', $detail['extraction']['object']['invoice_number']);
        $this->assertSame(493050, $detail['extraction']['object']['total_minor']);
        foreach ($detail['extraction']['fields'] as $f) {
            if ($f['effective'] !== null) {
                $this->assertNotEmpty($f['evidence'], $f['name'].' must carry evidence');
            }
        }
        $stages = StageTiming::where('triage_case_id', $case->id)->pluck('stage')->all();
        foreach (['sanitize', 'attachments', 'classify', 'extract', 'validate', 'policy'] as $stage) {
            $this->assertContains($stage, $stages);
        }
    }

    public function test_photo_invoice_flags_the_misread_field_and_shows_bbox_evidence(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-invoice-photo');
        $detail = app(CasePresenter::class)->detail($case);
        $field = collect($detail['extraction']['fields'])->firstWhere('name', 'invoice_number');
        $this->assertSame('INV-4OBBl', $field['predicted'], 'the simulated OCR confuses 0/O, 8/B and 1/l');
        $this->assertLessThan(0.8, $field['ocr_confidence']);
        $this->assertSame('needs_review', $field['status']);
        $this->assertSame('bbox', $field['evidence'][0]['locator']);
        $this->assertCount(4, $field['evidence'][0]['bbox']);
        $this->assertContains('LOW_OCR_CONFIDENCE', $this->issues($case));
        $this->assertContains('LOW_OCR_CONFIDENCE', $this->codes($case));
        $this->assertSame('awaiting_review', $case->state);
    }

    public function test_cross_field_mismatch_goes_to_review_even_with_high_model_signal(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-invoice-mismatch');
        $this->assertContains('TOTAL_MISMATCH', $this->issues($case));
        $this->assertSame('REVIEW_REQUIRED', $case->route);
        $field = collect(app(CasePresenter::class)->detail($case)['extraction']['fields'])->firstWhere('name', 'total');
        $this->assertGreaterThan(0.9, $field['signal'], 'the model signal for the total is high');
        $this->assertSame('invalid', $field['status'], 'but deterministic validation overrides it');
    }

    public function test_ambiguous_message_requires_a_human_to_choose_the_category(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-ambiguous');
        $this->assertSame('other_or_uncertain', $case->category);
        $this->assertSame('awaiting_review', $case->state);
        $this->assertContains('UNCERTAIN_CATEGORY', $this->codes($case));
        $this->assertNotEmpty(ClassificationRun::first()->alternatives);
    }

    public function test_low_risk_spam_is_auto_processed_through_the_outbox(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-spam');
        $this->assertSame('executed', $case->state);
        $this->assertSame('AUTO_ELIGIBLE', $case->route);
        $this->assertSame(['policy:auto'], $case->reviewDecisions()->pluck('actor')->all());
        $this->assertSame(1, $case->actionRequests()->count());
        $this->assertSame('SIMULATED', $case->actionRequests()->first()->runs()->first()->mode);
    }

    public function test_required_fields_missing_means_review_not_fabricated_values(): void
    {
        $case = $this->ingest($this->workspace(), 'fail-corrupt-pdf'); // text layer unreadable: nothing to extract from
        $this->assertSame('needs_review', $case->state);
        $this->assertContains('OCR_OR_PARSE_FAILED', $this->codes($case));
        $this->assertSame(0, $case->extractionRuns()->count());
    }

    public function test_llm_timeout_is_retried_then_falls_back_to_review_without_losing_the_item(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'llm_timeout', 9);
        $case = $this->ingest($ws, 'showcase-complaint');
        $this->assertSame('needs_review', $case->state);
        $this->assertContains('PROVIDER_FALLBACK', $this->codes($case));
        $this->assertSame(3, ProviderCall::where('triage_case_id', $case->id)->where('stage', 'classify')->where('status', 'timeout')->count());
        $detail = app(CasePresenter::class)->detail($case);
        $this->assertContains('FALLBACK', $detail['badges']);
    }

    public function test_llm_timeout_that_recovers_shows_the_retry(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'llm_timeout', 1);
        $case = $this->ingest($ws, 'showcase-complaint');
        $this->assertSame('awaiting_review', $case->state);
        $calls = ProviderCall::where('triage_case_id', $case->id)->where('stage', 'classify')->orderBy('attempt')->pluck('status')->all();
        $this->assertSame(['timeout', 'ok'], $calls);
        $this->assertSame(1, StageTiming::where('triage_case_id', $case->id)->where('stage', 'classify')->first()->retries);
    }

    public function test_rate_limit_backs_off_and_succeeds(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'rate_limit', 2);
        $case = $this->ingest($ws, 'showcase-complaint');
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(['rate_limited', 'rate_limited', 'ok'], ProviderCall::where('triage_case_id', $case->id)->where('stage', 'classify')->orderBy('attempt')->pluck('status')->all());
    }

    public function test_invalid_structured_output_is_retried_visibly_and_never_enters_as_valid(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'llm_invalid_json', 9);
        $case = $this->ingest($ws, 'showcase-invoice-pdf');
        $this->assertSame('needs_review', $case->state);
        $this->assertSame(0, ExtractionRun::where('triage_case_id', $case->id)->count(), 'an invalid output must not be persisted as a run');
        $this->assertSame(0, $case->validationRuns()->count());
        $this->assertContains('invalid_output', ProviderCall::where('triage_case_id', $case->id)->pluck('status')->all());
    }

    public function test_invalid_json_repair_retry_is_counted_not_hidden(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'llm_invalid_json', 1);
        $case = $this->ingest($ws, 'showcase-invoice-pdf');
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(['invalid_output', 'ok'], ProviderCall::where('triage_case_id', $case->id)->where('stage', 'classify')->orderBy('attempt')->pluck('status')->all());
    }

    public function test_ocr_timeout_retries_then_stays_in_the_queue_for_a_human(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'ocr_timeout', 9);
        $case = $this->ingest($ws, 'showcase-invoice-photo');
        $this->assertSame('needs_review', $case->state);
        $this->assertContains('OCR_OR_PARSE_FAILED', $this->codes($case));
        $this->assertSame(3, ProviderCall::where('triage_case_id', $case->id)->where('stage', 'ocr')->count());
    }

    public function test_worker_crash_after_persist_resumes_without_duplicate_runs(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'worker_crash_after_persist', 1);
        try {
            $this->ingest($ws, 'showcase-invoice-pdf');
            $this->fail('the simulated crash should surface');
        } catch (SimulatedWorkerCrash) {
        }
        $case = TriageCase::where('workspace_id', $ws->id)->firstOrFail();
        $this->assertSame('extracting', $case->state, 'crashed between persisting the run and the final status');
        $this->assertSame(1, $case->extractionRuns()->count());

        app(TriagePipeline::class)->run($case, 2); // queue retry
        $case->refresh();
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(1, $case->extractionRuns()->count(), 'retry reuses the persisted run');
        $this->assertSame(1, $case->classificationRuns()->count());
        $this->assertSame(1, $case->extractionRuns()->first()->fields()->where('field_name', 'invoice_number')->count());
        $this->assertSame(1, $case->attempts);
    }

    public function test_vendor_lookup_timeout_leaves_the_vendor_unvalidated(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'validation_lookup_timeout', 1);
        $case = $this->ingest($ws, 'showcase-invoice-pdf');
        $this->assertContains('VENDOR_LOOKUP_PENDING', $this->issues($case));
        $this->assertNotContains('MISSING_PO', $this->issues($case));
        $this->assertSame('REVIEW_REQUIRED', $case->route);
    }

    public function test_budget_exhausted_degrades_to_simulated_baseline_with_fallback_badge(): void
    {
        $ws = $this->workspace();
        $this->fault($ws, 'budget_exhausted', 1);
        $case = $this->ingest($ws, 'showcase-complaint');
        $this->assertSame('awaiting_review', $case->state);
        $this->assertContains('PROVIDER_FALLBACK', $this->codes($case));
        $this->assertContains('FALLBACK', app(CasePresenter::class)->detail($case)['badges']);
    }

    public function test_every_stage_leaves_a_measured_timing_and_a_correlation_id(): void
    {
        $case = $this->ingest($this->workspace(), 'showcase-complaint');
        $trace = app(CasePresenter::class)->trace($case);
        $this->assertStringStartsWith('corr_', $trace['correlation_id']);
        $stages = array_column($trace['stages'], 'stage');
        foreach (['sanitize', 'attachments', 'classify', 'extract', 'validate', 'policy'] as $s) {
            $this->assertContains($s, $stages);
        }
        $this->assertSame('heuristic', $trace['providers']['classification']['provider']);
        $this->assertSame('taxonomy.v1', $trace['versions']['taxonomy']);
        $this->assertSame('complaint.v1', $trace['versions']['schema']);
        $this->assertSame('policy.v1', $trace['versions']['policy']);
        $this->assertSame(0.0, (float) $trace['usage']['estimated_cost_usd']);
    }

    public function test_reprocess_keeps_history_and_creates_a_new_run(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-complaint');
        app(ReviewService::class)->reprocess($case, 'demo:reviewer-a');
        $case->refresh();
        $this->assertSame('awaiting_review', $case->state);
        $this->assertSame(2, $case->classificationRuns()->count());
        $this->assertSame(2, $case->extractionRuns()->count());
        $this->assertSame(1, $case->extractionRuns()->where('is_current', true)->count());
    }
}
