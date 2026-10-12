<?php

namespace App\Infrastructure\AI\DTO;

final readonly class ClassificationResult
{
    /**
     * @param  list<array{label:string,score:float}>  $alternatives
     * @param  array<string,float>  $signals
     * @param  list<string>  $reasonCodes
     * @param  list<array{source_id:string,quote:string}>  $evidenceRefs
     */
    public function __construct(
        public string $label,
        public array $alternatives,
        public array $signals,
        public array $reasonCodes,
        public array $evidenceRefs,
        public ProviderMeta $meta,
    ) {}

    /** The provider-reported signal for the chosen label (not a calibrated probability). */
    public function signal(): float
    {
        return (float) ($this->signals['model_signal'] ?? 0.0);
    }
}
