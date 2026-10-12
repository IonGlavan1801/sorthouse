<?php

namespace App\Models;

class ReviewDecision extends Model
{
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }
}
