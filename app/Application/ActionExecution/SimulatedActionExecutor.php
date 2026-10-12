<?php

namespace App\Application\ActionExecution;

use App\Domain\Triage\ActionType;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\ActionRequest;
use App\Models\Draft;
use App\Models\SimulatedEffect;
use App\Models\TriageCase;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * SIMULATED effects: nothing leaves the system. The `simulated_effects` table stands in for the third party and enforces
 * idempotency by key, which is what lets reconciliation prove a timed-out request was (or was not) applied.
 */
final class SimulatedActionExecutor implements ActionExecutor
{
    public function __construct(private FaultInjector $faults) {}

    public function supports(ActionType $type): bool
    {
        return true;
    }

    public function mode(): string
    {
        return 'SIMULATED';
    }

    public function execute(ActionRequest $request): ExecutionResult
    {
        $type = ActionType::from($request->action_type);
        $case = $request->workspace_id ? TriageCase::find($request->triage_case_id) : null;
        $payload = ['params' => $request->params, 'description' => $this->describe($type, $request)];

        if ($type === ActionType::QueueReply || $type === ActionType::SendReply) {
            $draft = Draft::find($request->params['draft_id'] ?? null);
            // recipients are server-owned: always the original sender, never text from the message
            $payload['to'] = $case?->message?->sender_email;
            $payload['subject'] = $draft?->subject;
            $payload['body'] = $draft?->edited_body ?? $draft?->body;
        }

        $applied = true;
        try {
            SimulatedEffect::create(['workspace_id' => $request->workspace_id, 'idempotency_key' => $request->idempotency_key, 'action_type' => $type->value, 'payload' => $payload]);
        } catch (UniqueConstraintViolationException) {
            $applied = false; // duplicate delivery: the third party already has this key, nothing is applied twice
        }

        if ($applied && $type === ActionType::QueueReply) {
            Draft::whereKey($request->params['draft_id'] ?? null)->update(['status' => 'queued']);
        }

        if ($this->faults->consume($request->workspace_id, 'action_timeout')) {
            return new ExecutionResult('unknown_timeout', ['note' => 'Request timed out after it was sent; the outcome is unknown.']);
        }

        return new ExecutionResult('executed', ['duplicate_suppressed' => ! $applied, 'effect' => $payload['description']]);
    }

    public function lookup(string $idempotencyKey): ?array
    {
        return SimulatedEffect::where('idempotency_key', $idempotencyKey)->first()?->payload;
    }

    private function describe(ActionType $type, ActionRequest $request): string
    {
        return match ($type) {
            ActionType::CreateDemoTask => 'Demo task created: '.($request->params['title'] ?? ''),
            ActionType::MoveToDemoFolder => 'Message moved to demo folder "'.($request->params['folder'] ?? '').'"',
            ActionType::MarkDemoStatus => 'Message marked "'.($request->params['status'] ?? '').'"',
            ActionType::QueueReply => 'Reply queued (not sent) to the original sender',
            ActionType::SendReply => 'Reply sent in the sandbox to the original sender',
            ActionType::ExportInvoiceJson => 'Invoice JSON exported ('.count($request->params['payload'] ?? []).' fields)',
            ActionType::RequestHumanReview => 'Human review requested',
        };
    }
}
