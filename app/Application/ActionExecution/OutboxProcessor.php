<?php

namespace App\Application\ActionExecution;

use App\Application\Pipeline\AuditLogger;
use App\Application\Pipeline\CaseStateMachine;
use App\Domain\Triage\ActionType;
use App\Domain\Triage\CaseState;
use App\Models\ActionRequest;
use App\Models\ActionRun;
use App\Models\OutboxMessage;
use App\Models\TriageCase;
use Illuminate\Support\Facades\DB;

/** Executes approved actions from the transactional outbox. Approval and outbox row are written together; execution happens here, never in the HTTP request. */
final class OutboxProcessor
{
    public const MAX_ATTEMPTS = 4;

    public function __construct(private ActionExecutor $executor, private CaseStateMachine $machine, private AuditLogger $audit) {}

    public function process(string $outboxId): ?OutboxMessage
    {
        $claimed = DB::transaction(function () use ($outboxId) {
            $outbox = OutboxMessage::whereKey($outboxId)->lockForUpdate()->first();
            if (! $outbox || $outbox->status !== 'pending' || ($outbox->available_at && $outbox->available_at->isFuture())) {
                return null; // duplicate delivery / replay: someone else owns it or it is done
            }
            $outbox->update(['status' => 'processing', 'attempts' => $outbox->attempts + 1]);
            $request = ActionRequest::findOrFail($outbox->action_request_id);
            $request->update(['status' => 'executing']);

            return [$outbox->fresh(), $request->fresh()];
        });
        if (! $claimed) {
            return null;
        }
        [$outbox, $request] = $claimed;
        $case = TriageCase::findOrFail($request->triage_case_id);
        $this->machine->ensure($case, CaseState::Executing, 'system:outbox', ['attempt' => $outbox->attempts]);

        $started = hrtime(true);
        try {
            $result = $this->executor->supports(ActionType::from($request->action_type)) ? $this->executor->execute($request) : new ExecutionResult('failed', ['error' => 'No executor']);
        } catch (\Throwable $e) {
            $result = new ExecutionResult('failed', ['error' => $e->getMessage()]);
        }
        $latency = (int) ((hrtime(true) - $started) / 1e6);
        ActionRun::create([
            'workspace_id' => $request->workspace_id, 'action_request_id' => $request->id, 'attempt' => $outbox->attempts,
            'mode' => $this->executor->mode(), 'outcome' => $result->outcome, 'detail' => $result->detail, 'latency_ms' => $latency,
        ]);

        match ($result->outcome) {
            'executed' => $this->markExecuted($outbox, $request, $case, $result->detail),
            'unknown_timeout' => $this->markPendingReconciliation($outbox, $request, $case),
            default => $this->markFailed($outbox, $request, $case, $result->detail),
        };

        return $outbox->fresh();
    }

    private function markExecuted(OutboxMessage $outbox, ActionRequest $request, TriageCase $case, array $detail): void
    {
        $outbox->update(['status' => 'done', 'processed_at' => now()]);
        $request->update(['status' => 'executed']);
        $this->machine->ensure($case, CaseState::Executed, 'system:outbox', $detail);
    }

    private function markPendingReconciliation(OutboxMessage $outbox, ActionRequest $request, TriageCase $case): void
    {
        $outbox->update(['status' => 'pending_reconciliation']);
        $request->update(['status' => 'pending_reconciliation']);
        $this->machine->ensure($case, CaseState::PendingReconciliation, 'system:outbox', ['note' => 'Timeout after send: outcome unknown. Reconciling before any re-send.']);
    }

    private function markFailed(OutboxMessage $outbox, ActionRequest $request, TriageCase $case, array $detail): void
    {
        $this->machine->ensure($case, CaseState::ExecutionFailed, 'system:outbox', $detail);
        if ($outbox->attempts >= self::MAX_ATTEMPTS) {
            $outbox->update(['status' => 'failed']);
            $request->update(['status' => 'execution_failed']);

            return;
        }
        $delay = (int) (2 ** $outbox->attempts + random_int(0, 2));
        $outbox->update(['status' => 'pending', 'available_at' => now()->addSeconds($delay)]);
        $request->update(['status' => 'queued']);
        $this->machine->ensure($case, CaseState::QueuedAction, 'system:outbox', ['retry_in_seconds' => $delay]);
    }

    /** Asks the third party first; re-sends only when it confirms the effect is absent. */
    public function reconcile(string $outboxId): ?string
    {
        $outbox = OutboxMessage::find($outboxId);
        if (! $outbox || $outbox->status !== 'pending_reconciliation') {
            return null;
        }
        $request = ActionRequest::findOrFail($outbox->action_request_id);
        $case = TriageCase::findOrFail($request->triage_case_id);
        $found = $this->executor->lookup($request->idempotency_key);

        if ($found !== null) {
            ActionRun::create(['workspace_id' => $request->workspace_id, 'action_request_id' => $request->id, 'attempt' => $outbox->attempts, 'mode' => $this->executor->mode(), 'outcome' => 'reconciled_executed', 'detail' => ['found_effect' => true]]);
            $outbox->update(['status' => 'done', 'processed_at' => now()]);
            $request->update(['status' => 'executed']);
            $this->machine->ensure($case, CaseState::Executed, 'system:reconciler', ['reconciled' => 'effect found at provider; no re-send']);

            return 'reconciled_executed';
        }

        ActionRun::create(['workspace_id' => $request->workspace_id, 'action_request_id' => $request->id, 'attempt' => $outbox->attempts, 'mode' => $this->executor->mode(), 'outcome' => 'reconciled_missing', 'detail' => ['found_effect' => false]]);
        $outbox->update(['status' => 'pending', 'available_at' => now()]);
        $request->update(['status' => 'queued']);
        $this->machine->ensure($case, CaseState::QueuedAction, 'system:reconciler', ['reconciled' => 'effect absent at provider; safe to send again']);

        return 'reconciled_missing';
    }
}
