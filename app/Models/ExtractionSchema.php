<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtractionSchema extends Model
{
    public function versions(): HasMany
    {
        return $this->hasMany(SchemaVersion::class);
    }
}
