<?php

namespace App\Application\Classification;

use App\Application\Usage\UsageRecorder;
use App\Domain\Triage\Category;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\ProviderGateway;
use App\Infrastructure\AI\ProviderSet;
use App\Models\ClassificationRun;
use App\Models\TriageCase;

final class ClassificationProcessor
{
    public function __construct(private ProviderGateway $gateway, private UsageRecorder $usage) {}

    /** @return array{label:string,retries:int,cached:bool} */
    public function classify(TriageCase $case, CaseInput $input, ProviderSet $provider, string $inputHash): array
    {
        $existing = ClassificationRun::where('triage_case_id', $case->id)->where('input_hash', $inputHash)
            ->where('taxonomy_version', Category::TAXONOMY_VERSION)->where('provider', $provider->name)->first();
        if ($existing) {
            $case->forceFill(['category' => $existing->label])->save();

            return ['label' => $existing->label, 'retries' => 0, 'cached' => true];
        }

        $call = $this->gateway->call($case, 'classify', $provider->name, $provider->model, fn () => $provider->classifier->classify($input));
        $r = $call['result'];
        ClassificationRun::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'input_hash' => $inputHash,
            'provider' => $r->meta->provider, 'model' => $r->meta->model, 'prompt_version' => $r->meta->promptVersion,
            'taxonomy_version' => Category::TAXONOMY_VERSION, 'label' => $r->label, 'alternatives' => $r->alternatives,
            'signals' => $r->signals, 'reason_codes' => $r->reasonCodes, 'evidence_refs' => $r->evidenceRefs,
            'raw_redacted' => ['label' => $r->label, 'alternatives' => $r->alternatives], 'latency_ms' => $r->meta->latencyMs, 'cached' => false,
        ]);
        $this->usage->record($case->workspace_id, $case->id, 'classify', $r->meta);
        $case->forceFill(['category' => $r->label])->save();

        return ['label' => $r->label, 'retries' => $call['retries'], 'cached' => false];
    }
}
