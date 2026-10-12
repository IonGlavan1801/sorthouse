<?php

namespace App\Application\Usage;

use App\Infrastructure\AI\DTO\ProviderMeta;
use App\Models\UsageEvent;

final class UsageRecorder
{
    public function record(string $workspaceId, ?string $caseId, string $stage, ProviderMeta $meta, int $pageUnits = 0): UsageEvent
    {
        return UsageEvent::create([
            'workspace_id' => $workspaceId,
            'triage_case_id' => $caseId,
            'stage' => $stage,
            'provider' => $meta->provider,
            'model' => $meta->model,
            'input_tokens' => $meta->inputTokens,
            'output_tokens' => $meta->outputTokens,
            'image_units' => $meta->imageUnits,
            'page_units' => $pageUnits,
            'latency_ms' => $meta->latencyMs,
            'estimated_cost_usd' => PricingTable::estimate($meta->model, $meta->inputTokens, $meta->outputTokens),
            'pricing_version' => PricingTable::version(),
        ]);
    }
}
