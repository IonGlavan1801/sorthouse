<?php

namespace App\Models;

class ActionRun extends Model
{
    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }
}
