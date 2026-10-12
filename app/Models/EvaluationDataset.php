<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationDataset extends Model
{
    public function cases(): HasMany
    {
        return $this->hasMany(EvaluationCase::class);
    }
}
