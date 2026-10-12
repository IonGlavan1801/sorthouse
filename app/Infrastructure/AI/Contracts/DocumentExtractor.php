<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\SourceDoc;

interface DocumentExtractor
{
    public function supports(string $detectedMime): bool;

    /** @return list<SourceDoc> */
    public function extract(string $attachmentId, string $absolutePath, string $detectedMime, array $metadata = []): array;
}
