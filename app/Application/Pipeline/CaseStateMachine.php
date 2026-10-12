<?php

namespace App\Application\Pipeline;

use App\Domain\Triage\CaseState;
use App\Models\TriageCase;
use Illuminate\Support\Facades\DB;

/** The only place a case changes state. Server-side validated, row-locked, versioned and audited. */
final class CaseStateMachine
{
    public function __construct(private AuditLogger $audit) {}

    public function transition(TriageCase $case, CaseState $to, string $actor, array $detail = [], array $attributes = []): TriageCase
    {
        return DB::transaction(function () use ($case, $to, $actor, $detail, $attributes) {
            /** @var TriageCase $locked */
            $locked = TriageCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $from = CaseState::from($locked->state);
            if (! $from->canTransitionTo($to)) {
                throw new InvalidTransition("Transition {$from->value} -> {$to->value} is not allowed");
            }
            $locked->fill($attributes);
            $locked->state = $to->value;
            $locked->version = $locked->version + 1;
            $locked->save();
            $this->audit->record($locked, 'case.transition', $actor, $from->value, $to->value, $detail);
            $case->setRawAttributes($locked->getAttributes(), true);

            return $case;
        });
    }

    /** Moves to $to unless the case is already there (idempotent re-entry after a retry). */
    public function ensure(TriageCase $case, CaseState $to, string $actor, array $detail = [], array $attributes = []): TriageCase
    {
        $case->refresh();
        if ($case->state === $to->value) {
            return $case;
        }

        return $this->transition($case, $to, $actor, $detail, $attributes);
    }
}
