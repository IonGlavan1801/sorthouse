<?php

namespace App\Models;

class IngestionRun extends Model
{
    protected $table = 'ingestion_runs';

    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }
}
