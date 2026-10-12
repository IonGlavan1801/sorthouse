<?php

namespace App\Models;

class SchemaVersion extends Model
{
    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }
}
