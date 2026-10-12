<?php

namespace App\Application\Review;

use App\Application\Pipeline\AuditLogger;
use App\Application\Pipeline\CaseEvaluator;
use App\Application\Pipeline\CaseStateMachine;
use App\Application\Pipeline\InvalidTransition;
use App\Application\Pipeline\PolicyViolation;
use App\Application\Pipeline\VersionConflict;
use App\Application\Validation\ValidationEngine;
use App\Domain\Extraction\Normalizer;
use App\Domain\Extraction\SchemaCatalog;
use App\Domain\Triage\ActionType;
use App\Domain\Triage\CaseState;
use App\Domain\Triage\Category;
use App\Domain\Triage\ErrorCategory;
use App\Domain\Triage\RejectReason;
use App\Domain\Triage\Route;
use App\Jobs\ExecuteOutboxMessage;
use App\Jobs\RunTriagePipeline;
use App\Models\ActionRequest;
use App\Models\Draft;
use App\Models\ExtractionRun;
use App\Models\FieldCorrection;
use App\Models\OutboxMessage;
use App\Models\ReviewDecision;
use App\Models\TriageCase;
use App\Models\ValidationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Human decisions. Each method locks the case row, checks the expected version (optimistic locking), appends to the audit trail and,
 * for approvals, creates the action request and the outbox message in the same transaction.
 */
final class ReviewService
{
    public function __construct(
        private CaseStateMachine $machine,
        private CaseEvaluator $evaluator,
        private ValidationEngine $validation,
        private Normalizer $normalizer,
        private AuditLogger $audit,
    ) {}

    public function approve(TriageCase $case, int $expectedVersion, string $actor, ?string $note = null): TriageCase
    {
        $outboxId = null;
        $result = DB::transaction(function () use ($case, $expectedVersion, $actor, $note, &$outboxId) {
            $locked = $this->lock($case);
            if ($replay = $this->replayOf($locked, $expectedVersion, $actor)) {
                return $replay;
            }
            $this->assertVersion($locked, $expectedVersion);
            if ($locked->state !== CaseState::AwaitingReview->value) {
                throw new InvalidTransition('Only a case awaiting review can be approved (current state: '.$locked->state.')');
            }

            // The policy is checked again at approval time: nothing the reviewer clicks can bypass it.
            $outcome = $this->evaluator->evaluate($locked);
            if ($outcome->route === Route::Blocked) {
                throw new PolicyViolation('Policy blocks this case: '.implode(', ', $outcome->codes()), $outcome->codes());
            }
            $locked->refresh();
            $outboxId = $this->approveAndQueue($locked, $actor, 'approved', $note, $expectedVersion);

            return $locked->refresh();
        });
        if ($outboxId) {
            ExecuteOutboxMessage::dispatch($outboxId)->afterCommit();
        }

        return $result->refresh();
    }

    /** Policy-approved low-risk case: same path as a human approval, attributed to the policy. */
    public function autoApprove(TriageCase $case): TriageCase
    {
        $outboxId = null;
        $result = DB::transaction(function () use ($case, &$outboxId) {
            $locked = $this->lock($case);
            if ($locked->state !== CaseState::AwaitingPolicy->value || $locked->route !== Route::AutoEligible->value) {
                return $locked;
            }
            $outboxId = $this->approveAndQueue($locked, 'policy:auto', 'approved', 'AUTO_PROCESS under '.($locked->flags['policy_version'] ?? 'active policy'), $locked->version, auto: true);

            return $locked->refresh();
        });
        if ($outboxId) {
            ExecuteOutboxMessage::dispatch($outboxId)->afterCommit();
        }

        return $result->refresh();
    }

    /** @return string outbox id */
    private function approveAndQueue(TriageCase $case, string $actor, string $decision, ?string $note, int $approvedVersion, bool $auto = false): string
    {
        $type = ActionType::tryFrom((string) $case->proposed_action);
        if ($type === null || ! in_array($type, Category::from($case->category)->allowedActions(), true)) {
            throw new PolicyViolation('No allow-listed action is prepared for this case', ['ACTION_NOT_ALLOWED']);
        }
        $params = $case->proposed_action_params ?? [];
        if (Validator::make($params, $type->paramRules())->fails()) {
            throw new PolicyViolation('The prepared action parameters are invalid', ['ACTION_PARAMS_INVALID']);
        }

        $this->machine->transition($case, CaseState::Approved, $actor, ['auto' => $auto], $auto ? [] : []);
        ReviewDecision::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'decision' => $decision, 'actor' => $actor,
            'reason_code' => $auto ? 'AUTO_PROCESS' : null, 'note' => $note, 'case_version' => $approvedVersion, 'snapshot' => $this->snapshot($case),
        ]);

        $key = hash('sha256', $case->id.'|'.$approvedVersion.'|'.$type->value);
        $request = ActionRequest::firstOrCreate(['idempotency_key' => $key], [
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'action_type' => $type->value,
            'params' => $params, 'case_version' => $approvedVersion, 'status' => 'queued', 'requested_by' => $actor,
        ]);
        $outbox = OutboxMessage::firstOrCreate(['idempotency_key' => $key], [
            'workspace_id' => $case->workspace_id, 'action_request_id' => $request->id, 'status' => 'pending', 'available_at' => now(),
        ]);
        if ($type === ActionType::QueueReply || $type === ActionType::SendReply) {
            Draft::whereKey($params['draft_id'] ?? null)->update(['status' => 'approved']);
        }
        $this->machine->transition($case, CaseState::QueuedAction, 'system:outbox', ['action' => $type->value, 'idempotency_key' => substr($key, 0, 16)]);

        return $outbox->id;
    }

    /**
     * @param  array{category?:string,fields?:array<string,?string>,draft_body?:string,reason?:string,error_category?:string}  $payload
     */
    public function correct(TriageCase $case, int $expectedVersion, string $actor, array $payload): array
    {
        $dispatchFresh = false;
        $summary = DB::transaction(function () use ($case, $expectedVersion, $actor, $payload, &$dispatchFresh) {
            $locked = $this->lock($case);
            $this->assertVersion($locked, $expectedVersion);
            if (! CaseState::from($locked->state)->isReviewable()) {
                throw new InvalidTransition('Only a case in review can be corrected (current state: '.$locked->state.')');
            }

            $before = $this->currentIssueCodes($locked);
            $this->machine->transition($locked, CaseState::Corrected, $actor, ['corrected_fields' => array_keys($payload['fields'] ?? []), 'category' => $payload['category'] ?? null]);
            $decision = ReviewDecision::create([
                'workspace_id' => $locked->workspace_id, 'triage_case_id' => $locked->id, 'decision' => 'corrected', 'actor' => $actor,
                'reason_code' => $payload['error_category'] ?? null, 'note' => $payload['reason'] ?? null, 'case_version' => $expectedVersion, 'snapshot' => $this->snapshot($locked),
            ]);

            if (! empty($payload['draft_body'])) {
                $draft = Draft::where('triage_case_id', $locked->id)->latest()->first();
                $draft?->update(['edited_body' => $payload['draft_body']]);
            }

            // category correction: the pipeline continues from extraction with the human's label
            if (! empty($payload['category']) && $payload['category'] !== $locked->category) {
                $new = Category::tryFrom($payload['category']) ?? throw new PolicyViolation('Unknown category');
                $this->audit->record($locked, 'review.category_corrected', $actor, detail: ['from' => $locked->category, 'to' => $new->value, 'error_category' => ErrorCategory::ClassificationWrong->value]);
                $locked->forceFill(['category' => $new->value, 'flags' => array_merge($locked->flags ?? [], ['category_corrected' => true])])->save();
                $this->machine->transition($locked, CaseState::AwaitingPolicy, $actor, ['reason' => 'category corrected']);
                $this->machine->transition($locked, CaseState::AwaitingReview, 'system:policy', ['reason' => 'category corrected; reprocessing']);
                $this->machine->transition($locked, CaseState::Processing, $actor, ['reason' => 'continue with corrected category']);
                $dispatchFresh = true;

                return ['category_changed' => true, 'issues_before' => $before, 'issues_after' => null];
            }

            $run = $this->currentRun($locked);
            if ($run) {
                $def = SchemaCatalog::definition($run->schema_version);
                foreach ($payload['fields'] ?? [] as $name => $value) {
                    $field = $run->fields()->where('field_name', $name)->first();
                    if (! $field || ! isset($def['fields'][$name])) {
                        throw new PolicyViolation("Field {$name} is not part of {$run->schema_version}");
                    }
                    $previous = FieldCorrection::where('extracted_field_id', $field->id)->latest()->first();
                    $clean = $value === null || trim((string) $value) === '' ? null : ($def['fields'][$name]['type'] === 'list' ? $this->normalizer->cleanMultiline((string) $value) : $this->normalizer->clean((string) $value));
                    $norm = $this->normalizer->normalize($def['fields'][$name]['type'], $clean)['value'];
                    FieldCorrection::create([
                        'workspace_id' => $locked->workspace_id, 'triage_case_id' => $locked->id, 'extracted_field_id' => $field->id, 'review_decision_id' => $decision->id,
                        'field_name' => $name, 'original_value' => $previous?->corrected_value ?? $field->predicted_value, 'corrected_value' => $clean,
                        'normalized_value' => is_array($norm) ? json_encode($norm) : ($norm === null ? null : (string) $norm),
                        'error_category' => $payload['error_category'] ?? ErrorCategory::ExtractionWrong->value, 'reason' => $payload['reason'] ?? null, 'actor' => $actor,
                    ]);
                }
                $vr = $this->validation->validate($locked, $run, withCorrections: true);
            }

            $this->machine->transition($locked, CaseState::AwaitingPolicy, 'system:policy', ['revalidated' => true]);
            $outcome = $this->evaluator->evaluate($locked, $vr ?? null);
            // a human touched the case: it always returns to review (unless policy blocks it), never straight to execution
            $this->machine->transition($locked, $outcome->route === Route::Blocked ? CaseState::Blocked : CaseState::AwaitingReview, 'system:policy', ['reasons' => $outcome->codes()]);

            return ['category_changed' => false, 'issues_before' => $before, 'issues_after' => $this->currentIssueCodes($locked), 'route' => $outcome->route->value];
        });

        if ($dispatchFresh) {
            RunTriagePipeline::dispatch($case->id, $case->workspace_id)->afterCommit();
        }

        return $summary + ['case' => $case->refresh()];
    }

    public function reject(TriageCase $case, int $expectedVersion, string $actor, string $reasonCode, ?string $note = null): TriageCase
    {
        $reason = RejectReason::tryFrom($reasonCode) ?? throw new PolicyViolation('Reject requires a reason from the list');

        return DB::transaction(function () use ($case, $expectedVersion, $actor, $reason, $note) {
            $locked = $this->lock($case);
            $this->assertVersion($locked, $expectedVersion);
            if (! in_array($locked->state, [CaseState::AwaitingReview->value, CaseState::NeedsReview->value, CaseState::Blocked->value], true)) {
                throw new InvalidTransition('This case cannot be rejected from state '.$locked->state);
            }
            ReviewDecision::create([
                'workspace_id' => $locked->workspace_id, 'triage_case_id' => $locked->id, 'decision' => 'rejected', 'actor' => $actor,
                'reason_code' => $reason->value, 'note' => $note, 'case_version' => $expectedVersion, 'snapshot' => $this->snapshot($locked),
            ]);
            // nothing prepared may execute any more
            ActionRequest::where('triage_case_id', $locked->id)->whereIn('status', ['queued'])->update(['status' => 'cancelled']);
            OutboxMessage::whereIn('action_request_id', ActionRequest::where('triage_case_id', $locked->id)->pluck('id'))->where('status', 'pending')->update(['status' => 'failed']);
            $this->machine->transition($locked, CaseState::Rejected, $actor, ['reason' => $reason->value]);

            return $locked->refresh();
        });
    }

    /** Sends the case back through the pipeline with a fresh epoch, so earlier runs stay in history. */
    public function reprocess(TriageCase $case, string $actor): TriageCase
    {
        DB::transaction(function () use ($case, $actor) {
            $locked = $this->lock($case);
            if (! in_array($locked->state, [CaseState::AwaitingReview->value, CaseState::NeedsReview->value, CaseState::Blocked->value, CaseState::FailedRetryable->value], true)) {
                throw new InvalidTransition('Reprocess is only available for cases in review, blocked or failed (state: '.$locked->state.')');
            }
            $flags = $locked->flags ?? [];
            $flags['epoch'] = ($flags['epoch'] ?? 0) + 1;
            unset($flags['category_corrected'], $flags['provider_fallback'], $flags['ocr_failed'], $flags['extraction_failed']);
            $locked->forceFill(['flags' => $flags, 'category' => null, 'route' => null])->save();
            $this->machine->transition($locked, CaseState::Processing, $actor, ['reprocess' => true, 'epoch' => $flags['epoch']]);
        });
        RunTriagePipeline::dispatch($case->id, $case->workspace_id)->afterCommit();

        return $case->refresh();
    }

    private function lock(TriageCase $case): TriageCase
    {
        return TriageCase::where('workspace_id', $case->workspace_id)->whereKey($case->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(TriageCase $case, int $expected): void
    {
        if ($case->version !== $expected) {
            throw new VersionConflict($expected, $case->version);
        }
    }

    /** Same actor re-sending the same approval returns the existing result instead of a conflict. */
    private function replayOf(TriageCase $case, int $expectedVersion, string $actor): ?TriageCase
    {
        $prior = ReviewDecision::where('triage_case_id', $case->id)->where('decision', 'approved')->where('case_version', $expectedVersion)->where('actor', $actor)->exists();
        if ($prior) {
            $this->audit->record($case, 'review.approve_replayed', $actor, detail: ['expected_version' => $expectedVersion]);

            return $case;
        }

        return null;
    }

    private function currentRun(TriageCase $case): ?ExtractionRun
    {
        $key = Category::tryFrom((string) $case->category)?->schemaKey();

        return $key ? ExtractionRun::where('triage_case_id', $case->id)->where('schema_version', $key)->where('is_current', true)->latest()->first() : null;
    }

    private function currentIssueCodes(TriageCase $case): array
    {
        $run = $this->currentRun($case);
        $vr = $run ? ValidationRun::where('extraction_run_id', $run->id)->with('issues')->latest()->first() : null;

        return $vr ? $vr->issues->map(fn ($i) => ['code' => $i->code, 'severity' => $i->severity, 'field' => $i->field_name])->all() : [];
    }

    private function snapshot(TriageCase $case): array
    {
        $run = $this->currentRun($case);
        $effective = $run ? $this->validation->effectiveFields($case, $run) : [];

        return [
            'category' => $case->category, 'route' => $case->route, 'overall_signal' => $case->overall_signal, 'proposed_action' => $case->proposed_action,
            'params' => $case->proposed_action_params, 'version' => $case->version,
            'fields' => collect($effective)->map(fn ($e) => ['predicted' => $e['field']->predicted_value, 'effective' => $e['raw'], 'corrected' => $e['corrected']])->all(),
        ];
    }
}
