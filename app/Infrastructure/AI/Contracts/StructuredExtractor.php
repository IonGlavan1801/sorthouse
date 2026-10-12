<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ExtractionResult;

interface StructuredExtractor
{
    /** @param array $schemaDefinition the immutable schema_versions.definition */
    public function extract(CaseInput $input, string $schemaVersion, array $schemaDefinition): ExtractionResult;
}
