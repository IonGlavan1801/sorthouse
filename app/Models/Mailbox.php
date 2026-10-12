<?php

namespace App\Models;

class Mailbox extends Model
{
    protected $table = 'mailboxes';

    protected function casts(): array
    {
        return [
            'allowed_recipients' => 'array',
        ];
    }
}
