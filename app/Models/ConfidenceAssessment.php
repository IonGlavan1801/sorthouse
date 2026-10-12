<?php

namespace App\Models;

class ConfidenceAssessment extends Model
{
    protected $table = 'confidence_assessments';

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'overall' => 'float',
            'calibrated' => 'boolean',
        ];
    }
}
