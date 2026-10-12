<?php

namespace App\Infrastructure\AI\DTO;

final readonly class ProviderMeta
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $promptVersion,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $imageUnits = 0,
        public int $latencyMs = 0,
        public bool $simulated = false,
        public bool $cached = false,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
