<?php

namespace App\Models;

class ClassificationRun extends Model
{
    protected function casts(): array
    {
        return [
            'alternatives' => 'array',
            'signals' => 'array',
            'reason_codes' => 'array',
            'evidence_refs' => 'array',
            'raw_redacted' => 'array',
            'cached' => 'boolean',
        ];
    }
}
