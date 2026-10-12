<?php

namespace App\Models;

class UsageEvent extends Model
{
    protected function casts(): array
    {
        return [
            'estimated_cost_usd' => 'float',
        ];
    }
}
