<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    public function demoSessions(): HasMany
    {
        return $this->hasMany(DemoSession::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(TriageCase::class);
    }
}
