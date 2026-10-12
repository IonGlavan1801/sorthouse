<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRun extends Model
{
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'metrics' => 'array',
            'simulated' => 'boolean',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'evaluation_dataset_id');
    }
}
