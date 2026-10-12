<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ValidationRun extends Model
{
    protected function casts(): array
    {
        return [
            'schema_valid' => 'boolean',
            'with_corrections' => 'boolean',
        ];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ValidationIssue::class);
    }
}
