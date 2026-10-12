<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ActionRequest extends Model
{
    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }

    public function outbox(): HasOne
    {
        return $this->hasOne(OutboxMessage::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ActionRun::class);
    }
}
