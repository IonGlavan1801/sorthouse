<?php

namespace App\Models;

class ProviderCall extends Model
{
    protected function casts(): array
    {
        return [
            'cached' => 'boolean',
        ];
    }
}
