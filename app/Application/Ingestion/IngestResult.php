<?php

namespace App\Application\Ingestion;

use App\Models\Message;
use App\Models\TriageCase;

final readonly class IngestResult
{
    public function __construct(public Message $message, public TriageCase $case, public bool $duplicate) {}
}
