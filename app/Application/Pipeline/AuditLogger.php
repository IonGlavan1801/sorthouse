<?php

namespace App\Application\Pipeline;

use App\Models\AuditEvent;
use App\Models\TriageCase;

final class AuditLogger
{
    public function record(TriageCase $case, string $event, string $actor, ?string $from = null, ?string $to = null, array $detail = []): AuditEvent
    {
        return AuditEvent::create([
            'workspace_id' => $case->workspace_id,
            'triage_case_id' => $case->id,
            'correlation_id' => $case->correlation_id,
            'event' => $event,
            'actor' => $actor,
            'from_state' => $from,
            'to_state' => $to,
            'case_version' => $case->version,
            'detail' => $detail ?: null,
        ]);
    }
}
