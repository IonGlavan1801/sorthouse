<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationPolicy extends Model
{
    protected $table = 'automation_policies';

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }
}
