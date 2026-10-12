<?php

namespace App\Application\Pipeline;

use RuntimeException;

class VersionConflict extends RuntimeException
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct("Case changed since you opened it (you have v{$expected}, current is v{$actual}). Refresh and review again.");
    }
}
