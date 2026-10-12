<?php

namespace App\Infrastructure\AI\Providers\Llm;

use App\Infrastructure\AI\Contracts\Drafter;
use App\Infrastructure\AI\Contracts\LanguageModel;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\DraftResult;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;

final class LlmDrafter implements Drafter
{
    public function __construct(private LanguageModel $model) {}

    public function draft(CaseInput $input, string $category, array $facts): DraftResult
    {
        unset($facts['_evidence']);
        $system = Prompts::GUARD."\nDraft a short, polite reply for a {$category} message. Use only facts from the message and the verified facts JSON. "
            .'Do not invent order status, prices, dates, refunds or commitments. Every factual claim must cite evidence.';
        $user = Prompts::content($input)."\nVerified facts:\n".json_encode($facts, JSON_UNESCAPED_UNICODE);
        $out = $this->model->generate($system, $user, Prompts::draftSchema());
        $json = $out['json'];
        if (! isset($json['subject'], $json['body'])) {
            throw new ProviderInvalidOutput('Draft is missing subject or body');
        }

        return new DraftResult((string) $json['subject'], (string) $json['body'], array_values($json['claims'] ?? []), $out['meta']);
    }
}
