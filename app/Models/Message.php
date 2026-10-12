<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'received_at' => 'datetime',
            'sanitization' => 'array',
        ];
    }

    public function body(): HasOne
    {
        return $this->hasOne(MessageBody::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function triageCase(): HasOne
    {
        return $this->hasOne(TriageCase::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
