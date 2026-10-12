<?php

namespace App\Models;

class AuditEvent extends Model
{
    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }
}
