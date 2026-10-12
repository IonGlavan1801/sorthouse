<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractedField extends Model
{
    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'confidence_signal' => 'float',
        ];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(FieldEvidence::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(FieldCorrection::class);
    }
}
