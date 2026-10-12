<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoSession extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'purged_at' => 'datetime',
            'fault_flags' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
