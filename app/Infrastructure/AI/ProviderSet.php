<?php

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Contracts\Classifier;
use App\Infrastructure\AI\Contracts\Drafter;
use App\Infrastructure\AI\Contracts\StructuredExtractor;

final readonly class ProviderSet
{
    public function __construct(
        public string $name,
        public string $model,
        public bool $simulated,
        public bool $fallback,
        public Classifier $classifier,
        public StructuredExtractor $extractor,
        public Drafter $drafter,
    ) {}
}
