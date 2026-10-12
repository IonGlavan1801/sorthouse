<?php

namespace App\Models;

class Draft extends Model
{
    protected function casts(): array
    {
        return [
            'claims' => 'array',
        ];
    }
}
