<?php

namespace App\Application\Extraction;

use App\Domain\Extraction\Normalizer;
use App\Domain\Extraction\SchemaCatalog;
use App\Infrastructure\AI\Contracts\EvidenceResolver;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ExtractionResult;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;
use App\Models\ExtractedField;
use App\Models\ExtractionRun;
use App\Models\TriageCase;

final class ExtractionProcessor
{
    public function __construct(private Normalizer $normalizer, private EvidenceResolver $resolver) {}

    /** Throws ProviderInvalidOutput so the gateway can run a visible, counted retry. Unknown fields are never silently accepted. */
    public function assertStructure(ExtractionResult $result, array $def): void
    {
        $known = array_keys($def['fields']);
        foreach (array_keys($result->fields) as $name) {
            if (! in_array($name, $known, true)) {
                throw new ProviderInvalidOutput("Extractor returned unknown field \"{$name}\"");
            }
        }
        foreach ($known as $name) {
            if (! array_key_exists($name, $result->fields)) {
                throw new ProviderInvalidOutput("Extractor omitted field \"{$name}\"");
            }
            $f = $result->fields[$name];
            if (! is_array($f) || ! array_key_exists('value', $f) || ! (is_null($f['value']) || is_string($f['value'])) || ! is_numeric($f['signal'] ?? null)) {
                throw new ProviderInvalidOutput("Field \"{$name}\" does not match the output schema");
            }
        }
    }

    /** Persists one immutable run (idempotent on case + input + schema + provider) with fields and resolved evidence. */
    public function persist(TriageCase $case, CaseInput $input, ExtractionResult $result, string $inputHash, int $retries, float $costUsd, bool $cached = false): ExtractionRun
    {
        $existing = ExtractionRun::where('triage_case_id', $case->id)->where('input_hash', $inputHash)
            ->where('schema_version', $result->schemaVersion)->where('provider', $result->meta->provider)->first();
        if ($existing) {
            return $existing; // retry after a crash: reuse the persisted run
        }

        $def = SchemaCatalog::definition($result->schemaVersion);
        ExtractionRun::where('triage_case_id', $case->id)->update(['is_current' => false]);
        $run = ExtractionRun::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'schema_version' => $result->schemaVersion,
            'input_hash' => $inputHash, 'provider' => $result->meta->provider, 'model' => $result->meta->model,
            'prompt_version' => $result->meta->promptVersion, 'status' => 'ok', 'input_refs' => $input->sourceIds(),
            'raw_output' => $this->redact($result->fields), 'warnings' => $result->warnings,
            'input_tokens' => $result->meta->inputTokens, 'output_tokens' => $result->meta->outputTokens,
            'cost_usd' => $costUsd, 'latency_ms' => $result->meta->latencyMs, 'retries' => $retries, 'cached' => $cached, 'is_current' => true,
        ]);

        foreach ($def['fields'] as $name => $meta) {
            $f = $result->fields[$name];
            $raw = $f['value'] === null ? null : ($meta['type'] === 'list' ? $this->normalizer->cleanMultiline($f['value']) : $this->normalizer->clean($f['value']));
            $norm = $this->normalizer->normalize($meta['type'], $raw)['value'];
            $evidence = [];
            foreach ($f['evidence'] ?? [] as $ref) {
                $resolved = $this->resolver->resolve($input, (string) ($ref['source_id'] ?? ''), (string) ($ref['quote'] ?? ''));
                if ($resolved) {
                    $evidence[] = $resolved;
                }
            }
            $first = $evidence[0] ?? null;
            $field = ExtractedField::create([
                'extraction_run_id' => $run->id, 'triage_case_id' => $case->id, 'field_name' => $name,
                'predicted_value' => $raw,
                'normalized_value' => is_array($norm) ? json_encode($norm) : ($norm === null ? null : (string) $norm),
                'data_type' => $meta['type'], 'is_critical' => $meta['critical'],
                'confidence_signal' => max(0, min(1, (float) $f['signal'])), 'signal_source' => $f['signal_source'] ?? 'model',
                'validation_status' => 'pending',
                'source_type' => $first ? ($input->source($first['source_id'])?->type) : null,
            ]);
            foreach ($evidence as $e) {
                $field->evidence()->create([
                    'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id,
                    'source_id' => $e['source_id'], 'locator_type' => $e['locator_type'], 'span_start' => $e['span_start'], 'span_end' => $e['span_end'],
                    'page' => $e['page'], 'bbox' => $e['bbox'], 'ocr_confidence' => $e['ocr_confidence'], 'snippet' => $e['snippet'],
                    'snippet_hash' => hash('sha256', $e['snippet']), 'provenance' => $e['provenance'],
                ]);
            }
        }

        return $run;
    }

    private function redact(array $fields): array
    {
        return array_map(fn ($f) => ['value' => $f['value'], 'signal' => $f['signal'], 'evidence' => array_map(fn ($e) => ['source_id' => $e['source_id'], 'quote' => mb_substr($e['quote'], 0, 160)], $f['evidence'] ?? [])], $fields);
    }
}
