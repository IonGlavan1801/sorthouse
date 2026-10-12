<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractionRun extends Model
{
    protected function casts(): array
    {
        return [
            'input_refs' => 'array',
            'raw_output' => 'array',
            'warnings' => 'array',
            'cached' => 'boolean',
            'is_current' => 'boolean',
            'cost_usd' => 'float',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ExtractedField::class);
    }
}
