<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyDecision extends Model
{
    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'context' => 'array',
        ];
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }
}
