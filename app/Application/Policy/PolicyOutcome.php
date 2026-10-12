<?php

namespace App\Application\Policy;

use App\Domain\Triage\Route;
use App\Models\PolicyVersion;

final readonly class PolicyOutcome
{
    /** @param list<array{code:string,message:string}> $reasons */
    public function __construct(public Route $route, public array $reasons, public PolicyVersion $version) {}

    public function codes(): array
    {
        return array_column($this->reasons, 'code');
    }
}
