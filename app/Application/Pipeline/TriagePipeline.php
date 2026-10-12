<?php

namespace App\Application\Pipeline;

use App\Application\Classification\ClassificationProcessor;
use App\Application\Extraction\ExtractionProcessor;
use App\Application\Policy\ActionPolicy;
use App\Application\Policy\InjectionScanner;
use App\Application\Policy\PolicyOutcome;
use App\Application\Review\ReviewService;
use App\Application\Usage\PricingTable;
use App\Application\Usage\UsageRecorder;
use App\Application\Validation\ValidationEngine;
use App\Domain\Extraction\SchemaCatalog;
use App\Domain\Triage\CaseState;
use App\Domain\Triage\Category;
use App\Domain\Triage\Route;
use App\Infrastructure\AI\Exceptions\ProviderException;
use App\Infrastructure\AI\ProviderGateway;
use App\Infrastructure\AI\ProviderRegistry;
use App\Infrastructure\AI\ProviderSet;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\ExtractionRun;
use App\Models\TriageCase;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates received -> sanitized -> classified -> attachments -> extracted -> validated -> policy checked -> review/ready.
 * Every stage is resumable: persisted results are reused (keyed by input hash) so a retried job never duplicates a run.
 */
final class TriagePipeline
{
    private const ORDER = ['sanitize', 'attachments', 'classify', 'extract', 'validate', 'policy'];

    public function __construct(
        private CaseStateMachine $machine,
        private StageRunner $stages,
        private InputBuilder $inputs,
        private AttachmentProcessor $attachments,
        private ClassificationProcessor $classification,
        private ExtractionProcessor $extraction,
        private ValidationEngine $validation,
        private CaseEvaluator $evaluator,
        private InjectionScanner $injection,
        private ProviderRegistry $providers,
        private ProviderGateway $gateway,
        private UsageRecorder $usage,
        private FaultInjector $faults,
        private ReviewService $review,
    ) {}

    /** Concurrent runs for one case are serialised (Postgres advisory lock); the second resumes from the first one's persisted state. */
    public function run(TriageCase $case, int $jobAttempt = 1): void
    {
        DB::select('select pg_advisory_lock(hashtext(?))', ['pipeline:'.$case->id]);
        try {
            $this->runLocked($case, $jobAttempt);
        } finally {
            DB::select('select pg_advisory_unlock(hashtext(?))', ['pipeline:'.$case->id]);
        }
    }

    private function runLocked(TriageCase $case, int $jobAttempt): void
    {
        $case->refresh();
        $state = CaseState::from($case->state);
        if ($state->isTerminal() || in_array($state, [CaseState::AwaitingReview, CaseState::NeedsReview, CaseState::Blocked, CaseState::QueuedAction, CaseState::Executing, CaseState::Executed, CaseState::Approved], true)) {
            return; // already decided; reprocess moves the case back to `processing` first
        }
        if ($jobAttempt > 1) {
            $case->increment('attempts');
        }
        $from = match ($state) {
            CaseState::Received, CaseState::Sanitizing => 'sanitize',
            CaseState::Processing, CaseState::FailedRetryable => 'attachments',
            CaseState::Classified => 'extract',
            CaseState::Extracting => 'extract',
            CaseState::Validating => 'validate',
            default => 'policy',
        };
        if ($state === CaseState::Processing) {
            $from = $this->hasClassification($case) ? 'classify' : 'attachments';
        }
        $start = array_search($from, self::ORDER, true);

        $provider = $this->faults->consume($case->workspace_id, 'budget_exhausted')
            ? $this->providers->heuristic(fallback: true) // daily budget exhausted (Failure Lab): degrade to the simulated baseline
            : $this->providers->active($case->flags['provider_override'] ?? null);
        if ($provider->fallback) {
            $this->flag($case, ['provider_fallback' => true]);
        }

        $steps = ['sanitize' => fn () => $this->sanitize($case), 'attachments' => fn () => $this->attachmentsStage($case),
            'classify' => fn () => $this->classify($case, $provider), 'extract' => fn () => $this->extract($case, $provider),
            'validate' => fn () => $this->validate($case), 'policy' => fn () => $this->policy($case)];

        foreach (array_slice(self::ORDER, $start) as $name) {
            $case->refresh();
            if ($steps[$name]() === 'stop') {
                return;
            }
        }
    }

    private function hasClassification(TriageCase $case): bool
    {
        return $case->category !== null;
    }

    private function flag(TriageCase $case, array $flags): void
    {
        $case->forceFill(['flags' => array_merge($case->flags ?? [], $flags)])->save();
    }

    private function sanitize(TriageCase $case): string
    {
        $this->machine->ensure($case, CaseState::Sanitizing, 'system:pipeline');
        $detail = [];
        $quarantined = $this->stages->run($case, 'sanitize', function () use ($case, &$detail) {
            $message = $case->message;
            $detail = ['html_report' => $message->sanitization, 'attachments' => $message->attachments->map(fn ($a) => ['name' => $a->filename, 'mime' => $a->detected_mime, 'scan' => $a->scan_status, 'reason' => $a->scan_reason, 'sha256' => substr($a->sha256, 0, 12)])->all()];

            return $message->attachments->contains(fn ($a) => $a->scan_status === 'quarantined');
        }, $detail);

        if ($quarantined) {
            // Never reaches a parser or model: quarantined content stops here.
            $this->flag($case, ['quarantined' => true]);
            $case->category ??= Category::OtherOrUncertain->value;
            $case->save();
            $this->machine->ensure($case, CaseState::Blocked, 'system:security', ['reason' => 'ATTACHMENT_QUARANTINED'], ['route' => Route::Blocked->value, 'risk_band' => 'blocked']);
            $this->recordSecurityDecision($case, 'ATTACHMENT_QUARANTINED', 'An attachment failed the security gate (extension/MIME mismatch or unsafe content) and was quarantined before parsing.');

            return 'stop';
        }
        $this->machine->ensure($case, CaseState::Processing, 'system:pipeline');

        return 'continue';
    }

    private function recordSecurityDecision(TriageCase $case, string $code, string $message): void
    {
        $policy = app(ActionPolicy::class);
        $version = $policy->activeVersion();
        $policy->record($case, new PolicyOutcome(Route::Blocked, [['code' => $code, 'message' => $message]], $version), ['category' => $case->category]);
    }

    private function attachmentsStage(TriageCase $case): string
    {
        $this->machine->ensure($case, CaseState::Processing, 'system:pipeline');
        $detail = [];
        $stats = $this->stages->run($case, 'attachments', function () use ($case, &$detail) {
            $case->load('message.attachments');
            $stats = $this->attachments->process($case);
            $detail = $stats;
            $detail['retries'] = $stats['retries'];

            return $stats;
        }, $detail);

        if ($stats['failed'] > 0) {
            // OCR/parse failed after retries: the item stays in the queue for a human instead of disappearing.
            $this->flag($case, ['ocr_failed' => true, 'provider_fallback' => true]);
            $this->machine->ensure($case, CaseState::NeedsReview, 'system:pipeline', ['reason' => 'OCR_OR_PARSE_FAILED'], ['route' => Route::ReviewRequired->value]);
            $this->policyReview($case, 'OCR_OR_PARSE_FAILED', 'Document text extraction failed after retries (fallback: human review).');

            return 'stop';
        }

        return 'continue';
    }

    private function policyReview(TriageCase $case, string $code, string $message): void
    {
        $policy = app(ActionPolicy::class);
        $policy->record($case, new PolicyOutcome(Route::ReviewRequired, [['code' => $code, 'message' => $message]], $policy->activeVersion()), ['category' => $case->category]);
    }

    private function classify(TriageCase $case, ProviderSet $provider): string
    {
        if (! empty($case->flags['category_corrected'])) {
            $this->machine->ensure($case, CaseState::Classified, 'system:pipeline', ['category' => $case->category, 'source' => 'human correction']);

            return 'continue';
        }
        $detail = [];
        $outcome = $this->stages->run($case, 'classify', function () use ($case, $provider, &$detail) {
            $input = $this->inputs->build($case);
            $hash = hash('sha256', $input->hash().'|'.($case->flags['epoch'] ?? 0));
            $this->flag($case, ['injection' => $this->injection->scan($input)]);
            try {
                $call = $this->classification->classify($case, $input, $provider, $hash);
            } catch (ProviderException $e) {
                $detail = ['error' => $e->getMessage(), 'kind' => $e->kind, 'retries' => (int) config('sorthouse.resilience.max_attempts') - 1, 'status' => 'fallback'];

                return 'fallback';
            }
            $detail = ['label' => $call['label'], 'retries' => $call['retries'], 'cached' => $call['cached'], 'provider' => $provider->name];

            return 'ok';
        }, $detail);

        if ($outcome === 'fallback') {
            $this->flag($case, ['provider_fallback' => true]);
            $case->forceFill(['category' => Category::OtherOrUncertain->value])->save();
            $this->machine->ensure($case, CaseState::NeedsReview, 'system:pipeline', ['reason' => 'CLASSIFIER_UNAVAILABLE'], ['route' => Route::ReviewRequired->value]);
            $this->policyReview($case, 'PROVIDER_FALLBACK', 'The classifier was unavailable after retries; a person must choose the category (item kept in queue).');

            return 'stop';
        }
        $this->machine->ensure($case, CaseState::Classified, 'system:pipeline', ['category' => $case->category]);

        return 'continue';
    }

    private function extract(TriageCase $case, ProviderSet $provider): string
    {
        $category = Category::from($case->category);
        $schemaKey = $category->schemaKey();
        if ($schemaKey === null) {
            // spam / general / other / order: no schema, straight to policy
            $this->scoreAndRoute($case);

            return 'stop';
        }

        $this->machine->ensure($case, CaseState::Extracting, 'system:pipeline', ['schema' => $schemaKey]);
        $def = SchemaCatalog::definition($schemaKey);
        $detail = [];
        $run = $this->stages->run($case, 'extract', function () use ($case, $provider, $def, $schemaKey, &$detail) {
            $input = $this->inputs->build($case);
            $hash = hash('sha256', $input->hash().'|'.($case->flags['epoch'] ?? 0));
            $existing = ExtractionRun::where('triage_case_id', $case->id)->where('input_hash', $hash)->where('schema_version', $schemaKey)->where('provider', $provider->name)->first();
            if ($existing) {
                $detail = ['reused_run' => $existing->id, 'cached' => true];

                return $existing;
            }
            try {
                $call = $this->gateway->call($case, 'extract', $provider->name, $provider->model, function () use ($provider, $input, $schemaKey, $def) {
                    $result = $provider->extractor->extract($input, $schemaKey, $def);
                    $this->extraction->assertStructure($result, $def);

                    return $result;
                });
            } catch (ProviderException $e) {
                $detail = ['error' => $e->getMessage(), 'kind' => $e->kind, 'status' => 'fallback', 'retries' => (int) config('sorthouse.resilience.max_attempts') - 1];

                return null;
            }
            $result = $call['result'];
            $cost = PricingTable::estimate($result->meta->model, $result->meta->inputTokens, $result->meta->outputTokens);
            $run = $this->extraction->persist($case, $input, $result, $hash, $call['retries'], $cost);
            $this->usage->record($case->workspace_id, $case->id, 'extract', $result->meta);
            $this->flag($case, ['proposed_actions' => $result->proposedActions]);
            $detail = ['retries' => $call['retries'], 'provider' => $provider->name, 'run' => $run->id, 'warnings' => $result->warnings];

            if ($this->faults->consume($case->workspace_id, 'worker_crash_after_persist')) {
                throw new SimulatedWorkerCrash('Worker crashed after persisting the extraction run (Failure Lab)');
            }

            return $run;
        }, $detail);

        if ($run === null) {
            $this->flag($case, ['provider_fallback' => true, 'extraction_failed' => true]);
            $this->machine->ensure($case, CaseState::FailedRetryable, 'system:pipeline', ['reason' => 'EXTRACTOR_UNAVAILABLE']);
            $this->machine->ensure($case, CaseState::NeedsReview, 'system:pipeline', ['reason' => 'EXTRACTOR_UNAVAILABLE'], ['route' => Route::ReviewRequired->value]);
            $this->policyReview($case, 'PROVIDER_FALLBACK', 'The extractor failed or returned invalid output after retries; the case needs a person.');

            return 'stop';
        }

        return 'continue';
    }

    private function validate(TriageCase $case): string
    {
        $category = Category::from($case->category);
        $run = ExtractionRun::where('triage_case_id', $case->id)->where('schema_version', $category->schemaKey())->where('is_current', true)->latest()->firstOrFail();
        $this->machine->ensure($case, CaseState::Validating, 'system:pipeline');
        $detail = [];
        $this->stages->run($case, 'validate', function () use ($case, $run, &$detail) {
            $vr = $this->validation->validate($case, $run);
            $detail = ['issues' => $vr->issues->map(fn ($i) => $i->code)->all(), 'schema_valid' => $vr->schema_valid, 'rules' => $vr->rules_version];
        }, $detail);

        $this->scoreAndRoute($case);

        return 'stop';
    }

    private function policy(TriageCase $case): string
    {
        $this->scoreAndRoute($case);

        return 'stop';
    }

    /** Confidence + ActionPolicy + routing to review / auto / blocked. */
    private function scoreAndRoute(TriageCase $case): void
    {
        $case->refresh();
        if (in_array($case->state, [CaseState::Classified->value, CaseState::Validating->value, CaseState::Extracting->value], true)) {
            $this->machine->ensure($case, CaseState::AwaitingPolicy, 'system:pipeline');
        }
        $detail = [];
        $outcome = $this->stages->run($case, 'policy', function () use ($case, &$detail) {
            $outcome = $this->evaluator->evaluate($case);
            $detail = ['route' => $outcome->route->value, 'reasons' => $outcome->codes(), 'policy' => $outcome->version->version];

            return $outcome;
        }, $detail);

        $case->refresh();
        match ($outcome->route) {
            Route::Blocked => $this->machine->ensure($case, CaseState::Blocked, 'system:policy', ['reasons' => $outcome->codes()]),
            Route::ReviewRequired => $this->machine->ensure($case, CaseState::AwaitingReview, 'system:policy', ['reasons' => $outcome->codes()]),
            Route::AutoEligible => $this->review->autoApprove($case),
        };
    }
}
