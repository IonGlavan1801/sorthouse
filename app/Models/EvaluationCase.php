<?php

namespace App\Models;

class EvaluationCase extends Model
{
    protected function casts(): array
    {
        return [
            'ground_truth' => 'array',
        ];
    }
}
