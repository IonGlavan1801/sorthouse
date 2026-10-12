<?php

namespace App\Domain\Triage;

enum Route: string
{
    case AutoEligible = 'AUTO_ELIGIBLE';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case Blocked = 'BLOCKED';

    public function decision(): string
    {
        return match ($this) {
            self::AutoEligible => 'AUTO_PROCESS',
            self::ReviewRequired => 'REVIEW_REQUIRED',
            self::Blocked => 'BLOCKED',
        };
    }
}
