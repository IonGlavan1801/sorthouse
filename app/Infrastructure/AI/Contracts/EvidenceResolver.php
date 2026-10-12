<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\CaseInput;

interface EvidenceResolver
{
    /**
     * Resolve a model-provided quote against the real case input.
     *
     * @return array{source_id:string,locator_type:string,span_start:?int,span_end:?int,page:?int,bbox:?array,snippet:string,provenance:string,ocr_confidence:?float}|null null when the source id is unknown or the quote is not in the source
     */
    public function resolve(CaseInput $input, string $sourceId, string $quote): ?array;
}
