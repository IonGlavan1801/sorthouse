<?php

namespace App\Infrastructure\AI\DTO;

final readonly class DraftResult
{
    /** @param list<array{text:string,evidence_refs:list<array{source_id:string,quote:string}>}> $claims */
    public function __construct(
        public string $subject,
        public string $body,
        public array $claims,
        public ProviderMeta $meta,
    ) {}
}
