<?php

namespace App\Application\Drafting;

use App\Application\Usage\UsageRecorder;
use App\Infrastructure\AI\Contracts\EvidenceResolver;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\ProviderGateway;
use App\Infrastructure\AI\ProviderSet;
use App\Models\Draft;
use App\Models\TriageCase;

/** Drafts are proposals only: they use extracted facts, cite evidence for every claim and are never sent without approval. */
final class DraftService
{
    public function __construct(private ProviderGateway $gateway, private EvidenceResolver $resolver, private UsageRecorder $usage) {}

    public function ensure(TriageCase $case, CaseInput $input, string $category, array $facts, ProviderSet $provider): ?Draft
    {
        if (! in_array($category, ['customer_complaint', 'request_for_quote'], true)) {
            return null;
        }
        $existing = Draft::where('triage_case_id', $case->id)->where('status', 'draft')->latest()->first();
        if ($existing) {
            return $existing;
        }

        try {
            $call = $this->gateway->call($case, 'draft', $provider->name, $provider->model, fn () => $provider->drafter->draft($input, $category, $facts));
        } catch (\Throwable) {
            return null; // a failed draft never blocks triage; the reviewer can still act on the extraction
        }
        $result = $call['result'];
        $this->usage->record($case->workspace_id, $case->id, 'draft', $result->meta);

        $claims = array_map(function ($claim) use ($input) {
            $verified = array_values(array_filter($claim['evidence_refs'], fn ($r) => $this->resolver->resolve($input, (string) ($r['source_id'] ?? ''), (string) ($r['quote'] ?? '')) !== null));
            $claim['evidence_refs'] = $verified;
            $claim['verified'] = $verified !== [];

            return $claim;
        }, $result->claims);

        return Draft::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'template_version' => 'reply.v1',
            'prompt_version' => $result->meta->promptVersion, 'provider' => $result->meta->provider,
            'subject' => $result->subject, 'body' => $result->body, 'claims' => $claims, 'status' => 'draft',
        ]);
    }
}
