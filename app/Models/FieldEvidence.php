<?php

namespace App\Models;

class FieldEvidence extends Model
{
    protected $table = 'field_evidence';

    protected function casts(): array
    {
        return [
            'bbox' => 'array',
        ];
    }
}
