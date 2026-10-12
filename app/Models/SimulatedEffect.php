<?php

namespace App\Models;

class SimulatedEffect extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
