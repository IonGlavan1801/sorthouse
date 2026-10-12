<?php

namespace App\Models;

class PolicyVersion extends Model
{
    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }
}
