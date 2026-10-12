<?php

namespace App\Models;

class StageTiming extends Model
{
    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'started_at' => 'datetime',
        ];
    }
}
