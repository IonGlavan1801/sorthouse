<?php

namespace App\Domain\Triage;

enum RejectReason: string
{
    case NotActionable = 'not_actionable';
    case WrongCategory = 'wrong_category';
    case WrongExtraction = 'wrong_extraction';
    case Duplicate = 'duplicate';
    case SuspectedFraud = 'suspected_fraud';
    case PolicyViolation = 'policy_violation';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
