<?php

namespace App\Application\ActionExecution;

use App\Domain\Triage\ActionType;
use App\Models\ActionRequest;

interface ActionExecutor
{
    public function supports(ActionType $type): bool;

    public function mode(): string; // SIMULATED | SANDBOX | LIVE

    public function execute(ActionRequest $request): ExecutionResult;

    /** Asks the (simulated) third party whether it already applied the effect for this idempotency key. */
    public function lookup(string $idempotencyKey): ?array;
}
