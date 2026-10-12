<?php

namespace App\Infrastructure\AI\Providers\Llm;

use App\Infrastructure\AI\Contracts\LanguageModel;
use App\Infrastructure\AI\Contracts\StructuredExtractor;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ExtractionResult;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;

final class LlmExtractor implements StructuredExtractor
{
    public function __construct(private LanguageModel $model) {}

    public function extract(CaseInput $input, string $schemaVersion, array $schemaDefinition): ExtractionResult
    {
        $names = array_keys($schemaDefinition['fields']);
        $descriptions = implode("\n", array_map(fn ($n, $f) => "- {$n} ({$f['type']}".($f['required'] ? ', required' : '').')', $names, $schemaDefinition['fields']));
        $system = Prompts::GUARD."\nExtract the fields of schema {$schemaVersion}. Return each value exactly as printed in the source, or null when it is absent. "
            ."Do not compute, convert or guess values.\nFields:\n{$descriptions}";
        $images = array_values(array_filter(array_map(fn ($s) => $s->imagePath, $input->sources)));
        $out = $this->model->generate($system, Prompts::content($input), Prompts::extractSchema($names), $images);
        $raw = $out['json']['fields'] ?? null;
        if (! is_array($raw)) {
            throw new ProviderInvalidOutput('Extractor output has no "fields" object');
        }

        $fields = [];
        foreach ($raw as $name => $f) {
            $fields[$name] = [
                'value' => isset($f['value']) ? (string) $f['value'] : null,
                'signal' => max(0.0, min(1.0, (float) ($f['signal'] ?? 0))),
                'signal_source' => 'model',
                'evidence' => array_values($f['evidence'] ?? []),
            ];
        }

        return new ExtractionResult($schemaVersion, $fields, [], [], $out['meta']);
    }
}
