<?php

namespace App\Infrastructure\AI\Providers\Llm;

use App\Domain\Triage\Category;
use App\Infrastructure\AI\Contracts\Classifier;
use App\Infrastructure\AI\Contracts\LanguageModel;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ClassificationResult;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;

final class LlmClassifier implements Classifier
{
    public function __construct(private LanguageModel $model) {}

    public function classify(CaseInput $input): ClassificationResult
    {
        $labels = array_map(fn (Category $c) => $c->value, Category::cases());
        $taxonomy = implode("\n", array_map(fn (Category $c) => "- {$c->value}: {$c->definition()}", Category::cases()));
        $system = Prompts::GUARD."\nClassify the message against taxonomy ".Category::TAXONOMY_VERSION.":\n{$taxonomy}\nUse other_or_uncertain when two labels are close.";
        $out = $this->model->generate($system, Prompts::content($input), Prompts::classifySchema($labels));
        $json = $out['json'];

        if (! isset($json['label']) || ! in_array($json['label'], $labels, true)) {
            throw new ProviderInvalidOutput('Classifier returned a label outside the taxonomy');
        }

        return new ClassificationResult(
            $json['label'],
            array_values(array_map(fn ($a) => ['label' => $a['label'], 'score' => (float) $a['score']], $json['alternatives'] ?? [])),
            ['model_signal' => max(0.0, min(1.0, (float) ($json['model_signal'] ?? 0)))],
            array_values(array_map('strval', $json['reason_codes'] ?? [])),
            array_values($json['evidence'] ?? []),
            $out['meta'],
        );
    }
}
