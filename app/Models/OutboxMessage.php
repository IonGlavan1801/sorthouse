<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxMessage extends Model
{
    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function actionRequest(): BelongsTo
    {
        return $this->belongsTo(ActionRequest::class);
    }
}
