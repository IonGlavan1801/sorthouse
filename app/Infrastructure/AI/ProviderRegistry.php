<?php

namespace App\Infrastructure\AI;

use App\Application\Usage\BudgetGuard;
use App\Infrastructure\AI\Contracts\LanguageModel;
use App\Infrastructure\AI\Providers\HeuristicClassifier;
use App\Infrastructure\AI\Providers\HeuristicDrafter;
use App\Infrastructure\AI\Providers\HeuristicExtractor;
use App\Infrastructure\AI\Providers\Llm\AnthropicModel;
use App\Infrastructure\AI\Providers\Llm\GeminiModel;
use App\Infrastructure\AI\Providers\Llm\LlmClassifier;
use App\Infrastructure\AI\Providers\Llm\LlmDrafter;
use App\Infrastructure\AI\Providers\Llm\LlmExtractor;
use App\Infrastructure\AI\Providers\Llm\OpenAiModel;

final class ProviderRegistry
{
    public function __construct(private BudgetGuard $budget) {}

    public function configuredName(): string
    {
        return config('sorthouse.provider', 'heuristic');
    }

    public function languageModel(string $name): ?LanguageModel
    {
        $c = config("sorthouse.providers.{$name}");
        if (! $c) {
            return null;
        }

        return match ($name) {
            'openai' => new OpenAiModel((string) $c['key'], (string) $c['model'], $c['base_url']),
            'anthropic' => new AnthropicModel((string) $c['key'], (string) $c['model'], $c['base_url']),
            'gemini' => new GeminiModel((string) $c['key'], (string) $c['model'], $c['base_url']),
            default => null,
        };
    }

    public function isConfigured(string $name): bool
    {
        $c = config("sorthouse.providers.{$name}");

        return $name === 'heuristic' || (! empty($c['key']) && ! empty($c['model']));
    }

    /** The provider used for new work. Falls back to the baseline (flagged) when the configured one is unusable or over budget. */
    public function active(?string $override = null): ProviderSet
    {
        $name = $override ?? $this->configuredName();
        if ($name !== 'heuristic') {
            if (! $this->isConfigured($name) || $this->budget->exhausted()) {
                return $this->heuristic(fallback: true);
            }
            $lm = $this->languageModel($name);
            if ($lm) {
                return new ProviderSet($name, $lm->model(), false, false, new LlmClassifier($lm), new LlmExtractor($lm), new LlmDrafter($lm));
            }
        }

        return $this->heuristic(fallback: false);
    }

    public function heuristic(bool $fallback = false): ProviderSet
    {
        return new ProviderSet('heuristic', HeuristicClassifier::MODEL, true, $fallback, new HeuristicClassifier, new HeuristicExtractor, new HeuristicDrafter);
    }
}
