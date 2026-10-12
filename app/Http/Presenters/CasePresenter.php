<?php

namespace App\Http\Presenters;

use App\Application\Confidence\ConfidenceAggregator;
use App\Application\Validation\ValidationEngine;
use App\Domain\Extraction\SchemaCatalog;
use App\Domain\Triage\Category;
use App\Models\ActionRequest;
use App\Models\ConfidenceAssessment;
use App\Models\Draft;
use App\Models\ExtractionRun;
use App\Models\FieldCorrection;
use App\Models\PolicyDecision;
use App\Models\ProviderCall;
use App\Models\ReviewDecision;
use App\Models\StageTiming;
use App\Models\TriageCase;
use App\Models\UsageEvent;
use App\Models\ValidationRun;

final class CasePresenter
{
    public function __construct(private ValidationEngine $validation, private ConfidenceAggregator $confidence) {}

    public function badges(TriageCase $case, ?object $classification = null): array
    {
        $badges = [];
        $flags = $case->flags ?? [];
        $simulated = ($classification?->provider ?? 'heuristic') === 'heuristic';
        $badges[] = $simulated ? 'SIMULATED' : 'LIVE';
        if (! empty($flags['provider_fallback'])) {
            $badges[] = 'FALLBACK';
        }
        if ($case->route === 'BLOCKED' || $case->state === 'blocked') {
            $badges[] = 'BLOCKED';
        }

        return array_values(array_unique($badges));
    }

    public function summary(TriageCase $case): array
    {
        $m = $case->message;
        $cr = $case->classificationRuns->sortByDesc('created_at')->first();
        $decision = $case->policyDecisions->sortByDesc('created_at')->first();
        $vr = ValidationRun::where('triage_case_id', $case->id)->latest()->with('issues')->first();

        return [
            'id' => $case->id, 'state' => $case->state, 'route' => $case->route, 'category' => $case->category, 'priority' => $case->priority,
            'risk_band' => $case->risk_band, 'overall_signal' => $case->overall_signal, 'confidence_band' => $case->confidence_band, 'version' => $case->version,
            'due_at' => $case->due_at?->toIso8601String(), 'proposed_action' => $case->proposed_action, 'correlation_id' => $case->correlation_id,
            'duplicates' => $m->duplicate_count, 'attachments' => $m->attachments->count(),
            'sender' => ['name' => $m->sender_name, 'email' => $m->sender_email], 'subject' => $m->subject, 'received_at' => $m->received_at->toIso8601String(),
            'issue_count' => $vr?->issues->count() ?? 0, 'error_count' => $vr?->issues->where('severity', 'error')->count() ?? 0,
            'reasons' => collect($decision?->reasons ?? [])->pluck('code')->all(), 'badges' => $this->badges($case, $cr),
            'auto_processed' => $case->reviewDecisions->contains(fn ($d) => $d->actor === 'policy:auto'),
        ];
    }

    public function detail(TriageCase $case): array
    {
        $case->loadMissing(['message.body', 'message.attachments', 'classificationRuns', 'policyDecisions', 'reviewDecisions', 'auditEvents']);
        $m = $case->message;
        $cr = $case->classificationRuns->sortByDesc('created_at')->first();
        $category = Category::tryFrom((string) $case->category);
        $schemaKey = $category?->schemaKey();
        $run = $schemaKey ? ExtractionRun::where('triage_case_id', $case->id)->where('schema_version', $schemaKey)->where('is_current', true)->latest()->first() : null;
        $vr = $run ? ValidationRun::where('extraction_run_id', $run->id)->latest()->with('issues')->first() : null;
        $decision = $case->policyDecisions->sortByDesc('created_at')->first();
        $assessment = ConfidenceAssessment::where('triage_case_id', $case->id)->latest()->first();
        $draft = Draft::where('triage_case_id', $case->id)->latest()->first();

        return $this->summary($case->loadMissing('classificationRuns')) + [
            'message' => [
                'id' => $m->id, 'text' => $m->body?->text_plain, 'html' => $m->body?->html_sanitized, 'recipients' => $m->recipients, 'sanitization' => $m->sanitization,
                'attachments' => $m->attachments->map(fn ($a) => [
                    'id' => $a->id, 'filename' => $a->filename, 'mime' => $a->detected_mime, 'declared_mime' => $a->declared_mime, 'size' => $a->size_bytes, 'sha256' => substr($a->sha256, 0, 16),
                    'scan_status' => $a->scan_status, 'scan_reason' => $a->scan_reason, 'scan_mode' => $a->metadata['scan_mode'] ?? 'SIMULATED', 'extraction_status' => $a->extraction_status,
                    'is_image' => str_starts_with((string) $a->detected_mime, 'image/'), 'ocr_simulated' => isset($a->metadata['ocr']),
                    'sources' => collect($a->metadata['sources'] ?? [])->map(fn ($s) => ['id' => $s['id'], 'type' => $s['type'], 'page' => $s['page'] ?? null, 'text' => $s['text']])->all(),
                ])->all(),
            ],
            'classification' => $cr ? [
                'label' => $cr->label, 'alternatives' => $cr->alternatives, 'signals' => $cr->signals, 'reason_codes' => $cr->reason_codes, 'evidence' => $cr->evidence_refs,
                'provider' => $cr->provider, 'model' => $cr->model, 'prompt_version' => $cr->prompt_version, 'taxonomy_version' => $cr->taxonomy_version, 'latency_ms' => $cr->latency_ms,
                'signal_label' => 'model signal (not a calibrated probability)',
            ] : null,
            'category_info' => $category ? ['definition' => $category->definition(), 'allowed_actions' => array_map(fn ($a) => $a->value, $category->allowedActions())] : null,
            'extraction' => $run ? $this->extraction($case, $run, $vr) : null,
            'validation' => $vr ? ['rules_version' => $vr->rules_version, 'schema_valid' => $vr->schema_valid, 'with_corrections' => $vr->with_corrections, 'issues' => $vr->issues->map(fn ($i) => ['code' => $i->code, 'severity' => $i->severity, 'field' => $i->field_name, 'message' => $i->message])->all()] : null,
            'confidence' => $assessment ? ['formula' => $assessment->formula_version, 'overall' => $assessment->overall, 'band' => $assessment->band, 'signals' => $assessment->signals, 'calibrated' => $assessment->calibrated, 'label' => 'signal, not a probability'] : null,
            'policy' => $decision ? ['decision' => $decision->decision, 'reasons' => $decision->reasons, 'version' => $decision->policyVersion?->version, 'context' => $decision->context] : null,
            'policy_history' => $case->policyDecisions->sortBy('created_at')->values()->map(fn (PolicyDecision $d) => ['decision' => $d->decision, 'case_version' => $d->case_version, 'policy' => $d->policyVersion?->version, 'codes' => collect($d->reasons)->pluck('code')->all(), 'at' => $d->created_at->toIso8601String()])->all(),
            'draft' => $draft ? ['id' => $draft->id, 'subject' => $draft->subject, 'body' => $draft->body, 'edited_body' => $draft->edited_body, 'claims' => $draft->claims, 'status' => $draft->status, 'template_version' => $draft->template_version, 'provider' => $draft->provider, 'sent' => false] : null,
            'proposed' => $case->proposed_action ? ['type' => $case->proposed_action, 'params' => $case->proposed_action_params] : null,
            'actions' => ActionRequest::where('triage_case_id', $case->id)->with(['runs', 'outbox'])->get()->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->action_type, 'status' => $a->status, 'idempotency_key' => substr($a->idempotency_key, 0, 16), 'requested_by' => $a->requested_by,
                'outbox' => $a->outbox ? ['status' => $a->outbox->status, 'attempts' => $a->outbox->attempts] : null,
                'runs' => $a->runs->map(fn ($r) => ['attempt' => $r->attempt, 'mode' => $r->mode, 'outcome' => $r->outcome, 'latency_ms' => $r->latency_ms, 'detail' => $r->detail])->all(),
            ])->all(),
            'reviews' => $case->reviewDecisions->sortBy('created_at')->values()->map(fn (ReviewDecision $d) => ['decision' => $d->decision, 'actor' => $d->actor, 'reason' => $d->reason_code, 'note' => $d->note, 'case_version' => $d->case_version, 'at' => $d->created_at->toIso8601String()])->all(),
            'corrections' => FieldCorrection::where('triage_case_id', $case->id)->orderBy('created_at')->get()->map(fn ($c) => ['field' => $c->field_name, 'original' => $c->original_value, 'corrected' => $c->corrected_value, 'normalized' => $c->normalized_value, 'actor' => $c->actor, 'category' => $c->error_category, 'reason' => $c->reason, 'at' => $c->created_at->toIso8601String()])->all(),
            'audit' => $case->auditEvents->sortBy('created_at')->values()->map(fn ($a) => ['event' => $a->event, 'actor' => $a->actor, 'from' => $a->from_state, 'to' => $a->to_state, 'version' => $a->case_version, 'detail' => $a->detail, 'at' => $a->created_at->toIso8601String()])->all(),
            'can' => $this->can($case),
        ];
    }

    private function can(TriageCase $case): array
    {
        $review = in_array($case->state, ['awaiting_review', 'needs_review'], true);

        return [
            'approve' => $case->state === 'awaiting_review' && $case->route !== 'BLOCKED' && $case->proposed_action !== null,
            'correct' => $review,
            'reject' => $review || $case->state === 'blocked',
            'reprocess' => in_array($case->state, ['awaiting_review', 'needs_review', 'blocked', 'failed_retryable'], true),
            'reconcile' => $case->state === 'pending_reconciliation',
        ];
    }

    private function extraction(TriageCase $case, ExtractionRun $run, ?ValidationRun $vr): array
    {
        $def = SchemaCatalog::definition($run->schema_version);
        $effective = $this->validation->effectiveFields($case, $run);
        $fields = [];
        foreach ($def['fields'] as $name => $meta) {
            $e = $effective[$name] ?? null;
            if (! $e) {
                continue;
            }
            $f = $e['field'];
            $statusNow = $this->confidence->fieldStatus($name, $meta['required'], $e['normalized'], $e['has_evidence'], $e['corrected'], $vr);
            $fields[] = [
                'name' => $name, 'type' => $meta['type'], 'required' => $meta['required'], 'critical' => $meta['critical'],
                'predicted' => $f->predicted_value, 'predicted_normalized' => $f->normalized_value, 'effective' => $e['raw'], 'effective_normalized' => $e['normalized'],
                'corrected' => $e['corrected'], 'signal' => $f->confidence_signal, 'signal_source' => $f->signal_source, 'status' => $statusNow,
                'ocr_confidence' => $f->evidence->pluck('ocr_confidence')->filter(fn ($c) => $c !== null)->map(fn ($c) => (float) $c)->min(),
                'evidence' => $f->evidence->map(fn ($ev) => ['id' => $ev->id, 'source_id' => $ev->source_id, 'locator' => $ev->locator_type, 'page' => $ev->page, 'bbox' => $ev->bbox, 'snippet' => $ev->snippet, 'span' => [$ev->span_start, $ev->span_end], 'provenance' => $ev->provenance, 'ocr_confidence' => $ev->ocr_confidence])->all(),
            ];
        }

        return [
            'schema_version' => $run->schema_version, 'provider' => $run->provider, 'model' => $run->model, 'prompt_version' => $run->prompt_version, 'status' => $run->status,
            'retries' => $run->retries, 'input_tokens' => $run->input_tokens, 'output_tokens' => $run->output_tokens, 'cost_usd' => $run->cost_usd, 'latency_ms' => $run->latency_ms,
            'warnings' => $run->warnings, 'fields' => $fields, 'object' => $this->validation->toObject($run->schema_version, $effective), 'run_id' => $run->id,
        ];
    }

    public function trace(TriageCase $case): array
    {
        $stages = StageTiming::where('triage_case_id', $case->id)->orderBy('started_at')->orderBy('created_at')->get();
        $calls = ProviderCall::where('triage_case_id', $case->id)->orderBy('created_at')->get();
        $usage = UsageEvent::where('triage_case_id', $case->id)->get();
        $cr = $case->classificationRuns()->latest()->first();
        $run = ExtractionRun::where('triage_case_id', $case->id)->where('is_current', true)->latest()->first();
        $decision = $case->policyDecisions()->latest()->with('policyVersion')->first();

        return [
            'correlation_id' => $case->correlation_id, 'case_id' => $case->id, 'message_id' => $case->message_id,
            'versions' => [
                'taxonomy' => $cr?->taxonomy_version, 'classify_prompt' => $cr?->prompt_version, 'extract_prompt' => $run?->prompt_version, 'schema' => $run?->schema_version,
                'confidence_formula' => ConfidenceAggregator::FORMULA, 'validation_rules' => ValidationEngine::RULES_VERSION, 'policy' => $decision?->policyVersion?->version,
                'pricing' => config('sorthouse.pricing.version'),
            ],
            'providers' => ['classification' => $cr ? ['provider' => $cr->provider, 'model' => $cr->model] : null, 'extraction' => $run ? ['provider' => $run->provider, 'model' => $run->model] : null],
            'stages' => $stages->map(fn ($s) => ['stage' => $s->stage, 'status' => $s->status, 'duration_ms' => $s->duration_ms, 'retries' => $s->retries, 'detail' => $s->detail, 'at' => $s->started_at->toIso8601String()])->all(),
            'provider_calls' => $calls->map(fn ($c) => ['stage' => $c->stage, 'provider' => $c->provider, 'model' => $c->model, 'status' => $c->status, 'attempt' => $c->attempt, 'latency_ms' => $c->latency_ms, 'error' => $c->error])->all(),
            'usage' => ['input_tokens' => $usage->sum('input_tokens'), 'output_tokens' => $usage->sum('output_tokens'), 'estimated_cost_usd' => round($usage->sum('estimated_cost_usd'), 6), 'cost_label' => 'estimated (versioned price table, not reconciled with a provider invoice)'],
            'total_ms' => $stages->sum('duration_ms'), 'duplicates' => $case->message->duplicate_count, 'attempts' => $case->attempts,
            'ingestion' => \App\Models\IngestionRun::where('message_id', $case->message_id)->orderBy('created_at')->get()->map(fn ($i) => ['outcome' => $i->outcome, 'detail' => $i->detail, 'at' => $i->created_at->toIso8601String()])->all(),
        ];
    }
}
