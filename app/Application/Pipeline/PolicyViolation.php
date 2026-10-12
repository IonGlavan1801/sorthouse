<?php

namespace App\Application\Pipeline;

use RuntimeException;

class PolicyViolation extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(string $message, public readonly array $reasons = [])
    {
        parent::__construct($message);
    }
}
