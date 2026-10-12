<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\ProviderMeta;

interface LanguageModel
{
    public function name(): string;

    public function model(): string;

    /**
     * Generates a JSON object constrained (where the provider supports it) by $jsonSchema.
     * $images are local file paths sent as image input when the provider supports vision.
     *
     * @return array{json:array,meta:ProviderMeta}
     */
    public function generate(string $system, string $user, array $jsonSchema, array $images = []): array;

    public function capabilities(): array;
}
