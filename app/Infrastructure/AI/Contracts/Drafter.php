<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\DraftResult;

interface Drafter
{
    /** @param array<string,mixed> $facts normalized facts the draft may use */
    public function draft(CaseInput $input, string $category, array $facts): DraftResult;
}
