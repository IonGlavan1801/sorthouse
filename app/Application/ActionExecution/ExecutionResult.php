<?php

namespace App\Application\ActionExecution;

final readonly class ExecutionResult
{
    public function __construct(public string $outcome, public array $detail = []) {} // executed | failed | unknown_timeout
}
