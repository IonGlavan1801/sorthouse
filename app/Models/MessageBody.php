<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageBody extends Model
{
    protected $table = 'message_bodies';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
