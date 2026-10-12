<?php

namespace App\Domain\Triage;

enum ErrorCategory: string
{
    case ClassificationWrong = 'classification_wrong';
    case ExtractionWrong = 'extraction_wrong';
    case NormalizationWrong = 'normalization_wrong';
    case ValidationRuleWrong = 'validation_rule_wrong';
    case EvidenceWrong = 'evidence_wrong';
    case PolicyTooStrict = 'policy_too_strict';
    case PolicyTooPermissive = 'policy_too_permissive';
}
