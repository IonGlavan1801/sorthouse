<?php

namespace App\Application\Pipeline;

use App\Application\Confidence\ConfidenceAggregator;
use App\Application\Drafting\DraftService;
use App\Application\Policy\ActionPlanner;
use App\Application\Policy\ActionPolicy;
use App\Application\Policy\PolicyOutcome;
use App\Application\Validation\ValidationEngine;
use App\Domain\Extraction\SchemaCatalog;
use App\Domain\Triage\Category;
use App\Infrastructure\AI\ProviderRegistry;
use App\Models\ClassificationRun;
use App\Models\ExtractionRun;
use App\Models\TriageCase;
use App\Models\ValidationRun;

/** Scores a case and asks the ActionPolicy what may happen. Used after the pipeline and again after every human correction. */
final class CaseEvaluator
{
    public function __construct(
        private ValidationEngine $validation,
        private ConfidenceAggregator $confidence,
        private ActionPolicy $policy,
        private ActionPlanner $planner,
        private DraftService $drafts,
        private InputBuilder $inputs,
        private ProviderRegistry $providers,
    ) {}

    public function evaluate(TriageCase $case, ?ValidationRun $vr = null, bool $recordDecision = true): PolicyOutcome
    {
        $case->refresh();
        $category = Category::from($case->category ?? Category::OtherOrUncertain->value);
        $schemaKey = $category->schemaKey();
        $run = $schemaKey ? ExtractionRun::where('triage_case_id', $case->id)->where('schema_version', $schemaKey)->where('is_current', true)->latest()->first() : null;
        $vr ??= $run ? ValidationRun::where('extraction_run_id', $run->id)->with('issues')->latest()->first() : null;
        $cr = ClassificationRun::where('triage_case_id', $case->id)->latest()->first();
        $flags = $case->flags ?? [];

        $effective = $run ? $this->validation->effectiveFields($case, $run) : [];
        $facts = $this->facts($effective);
        $input = $this->inputs->build($case);

        $draft = $run ? $this->drafts->ensure($case, $input, $category->value, $facts, $this->providers->active($flags['provider_override'] ?? null)) : null;
        $object = $run ? $this->validation->toObject($run->schema_version, $effective) : [];
        $planned = $this->planner->plan($category, $facts, $draft?->id, $object, (string) $schemaKey);

        $categoryCorrected = ! empty($flags['category_corrected']);
        $classSignal = $categoryCorrected ? 1.0 : (float) ($cr?->signals['model_signal'] ?? 0.0);
        $thresholds = $this->policy->thresholds($this->policy->activeVersion());
        $assessment = $this->confidence->assess($case, $classSignal, $run, $vr, $effective, $schemaKey ? SchemaCatalog::definition($schemaKey)['fields'] : [], $thresholds, (bool) config('sorthouse.calibrated', false));

        $issues = $vr?->issues ?? collect();
        $mailbox = $case->message->mailbox ?? null;
        $ctx = [
            'category' => $category,
            'overall' => (float) $assessment->overall,
            'classification_signal' => $classSignal,
            'classification_margin' => (float) ($cr?->signals['margin'] ?? 9),
            'errors' => $issues->where('severity', 'error')->where('code', '!=', 'SCHEMA_REQUIRED_FIELD')->pluck('code')->unique()->values()->all(),
            'warnings' => $issues->where('severity', 'warning')->pluck('code')->unique()->values()->all(),
            'missing_required' => $issues->where('code', 'SCHEMA_REQUIRED_FIELD')->pluck('field_name')->values()->all(),
            'injection' => $flags['injection'] ?? [],
            'quarantined' => $case->message->attachments->contains(fn ($a) => $a->scan_status === 'quarantined'),
            'rejected_attachments' => $case->message->attachments->contains(fn ($a) => $a->scan_status === 'rejected'),
            'provider_fallback' => ! empty($flags['provider_fallback']),
            'proposed_actions' => $flags['proposed_actions'] ?? [],
            'allowed_recipients' => array_merge([$case->message->sender_email], $case->message->mailbox?->allowed_recipients ?? []),
            'planned_action' => $planned,
            'has_schema' => $schemaKey !== null,
        ];

        $outcome = $this->policy->evaluate($ctx);
        $risk = $outcome->route->value === 'BLOCKED' ? 'blocked' : $category->risk();
        $case->forceFill([
            'route' => $outcome->route->value,
            'overall_signal' => $assessment->overall,
            'confidence_band' => $assessment->band,
            'risk_band' => $risk,
            'proposed_action' => $planned['type'] ?? null,
            'proposed_action_params' => $planned['params'] ?? null,
        ])->save();

        if ($recordDecision) {
            $this->policy->record($case, $outcome, collect($ctx)->except(['category', 'planned_action'])->all() + ['category' => $category->value, 'assessment_id' => $assessment->id, 'policy_version' => $outcome->version->version]);
        }

        return $outcome;
    }

    /** Effective normalized values with list fields decoded, plus an evidence map for draft claims. */
    public function facts(array $effective): array
    {
        $facts = [];
        foreach ($effective as $name => $e) {
            $v = $e['normalized'];
            $facts[$name] = ($e['field']->data_type === 'list' && $v !== null) ? json_decode((string) $v, true) : $v;
            $facts['_evidence'][$name] = $e['field']->evidence->map(fn ($ev) => ['source_id' => $ev->source_id, 'quote' => $ev->snippet])->all();
        }

        return $facts;
    }
}
