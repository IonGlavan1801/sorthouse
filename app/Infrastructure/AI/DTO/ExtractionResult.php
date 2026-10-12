<?php

namespace App\Infrastructure\AI\DTO;

final readonly class ExtractionResult
{
    /**
     * @param  array<string,mixed>  $fields  name => ['value'=>?string,'signal'=>float,'signal_source'=>string,'evidence'=>list<array{source_id:string,quote:string}>]; unknown names are kept so the schema validator can reject them
     * @param  list<string>  $warnings
     * @param  list<array{type:string,params:array,recipient?:string}>  $proposedActions
     */
    public function __construct(
        public string $schemaVersion,
        public array $fields,
        public array $warnings,
        public array $proposedActions,
        public ProviderMeta $meta,
    ) {}
}
