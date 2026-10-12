<?php

namespace App\Models;

class EvaluationResult extends Model
{
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }
}
