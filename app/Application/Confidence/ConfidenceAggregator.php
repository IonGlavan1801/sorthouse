<?php

namespace App\Application\Confidence;

use App\Models\ConfidenceAssessment;
use App\Models\ExtractionRun;
use App\Models\TriageCase;
use App\Models\ValidationRun;

/**
 * conf.v1 — combines separate signals; it does not claim to be a probability.
 *   field_signal  = min(model_signal, ocr_confidence?) x (evidence ? 1 : 0.5) x (validation error ? 0.4 : warning ? 0.8 : 1)
 *   extraction    = 0.6 x min(critical field signals, required missing = 0) + 0.4 x mean(non-null field signals), capped at 0.50 with validation errors, 0.85 with warnings
 *   overall       = min(classification model_signal, extraction)
 * Whether the thresholds are calibrated is recorded with the assessment (see docs/EVALUATION.md).
 */
final class ConfidenceAggregator
{
    public const FORMULA = 'conf.v1';

    public function fieldStatus(string $name, bool $required, ?string $value, bool $hasEvidence, bool $corrected, ?ValidationRun $vr): string
    {
        $issues = $vr?->issues->where('field_name', $name) ?? collect();
        if ($value === null || $value === '' || $value === '[]') {
            return $required ? 'missing' : 'empty';
        }
        if (! $hasEvidence && ! $corrected) {
            return 'unsupported';
        }
        if ($issues->where('severity', 'error')->isNotEmpty()) {
            return 'invalid';
        }
        if ($issues->where('severity', 'warning')->isNotEmpty()) {
            return 'needs_review';
        }

        return 'valid';
    }

    /** @param array<string,array> $effective from ValidationEngine::effectiveFields */
    public function assess(TriageCase $case, float $classificationSignal, ?ExtractionRun $run, ?ValidationRun $vr, array $effective, array $schemaFields, array $thresholds, bool $calibrated): ConfidenceAssessment
    {
        $signals = ['classification' => round($classificationSignal, 4), 'fields' => []];
        $extraction = null;

        if ($run && $vr) {
            $criticalMin = 1.0;
            $nonNull = [];
            foreach ($schemaFields as $name => $meta) {
                $e = $effective[$name] ?? null;
                $f = $e['field'] ?? null;
                $value = $e['normalized'] ?? null;
                $corrected = $e['corrected'] ?? false;
                $status = $this->fieldStatus($name, $meta['required'], $value, (bool) ($e['has_evidence'] ?? false), $corrected, $vr);
                $model = $corrected ? 1.0 : (float) ($f->confidence_signal ?? 0);
                $ocr = $f && ! $corrected ? $f->evidence->pluck('ocr_confidence')->filter(fn ($c) => $c !== null)->map(fn ($c) => (float) $c)->min() : null;
                $signal = $value === null || $value === '' ? 0.0 : min($model, $ocr ?? 1.0) * (($e['has_evidence'] ?? false) || $corrected ? 1.0 : 0.5);
                $signal *= match ($status) {
                    'invalid', 'unsupported' => 0.4,
                    'needs_review' => 0.8,
                    default => 1.0,
                };
                if ($value !== null && $value !== '') {
                    $nonNull[] = $signal;
                }
                if ($meta['critical'] || ($meta['required'] && ($value === null || $value === ''))) {
                    $criticalMin = min($criticalMin, ($value === null || $value === '') && $meta['required'] ? 0.0 : ($value === null ? 1.0 : $signal));
                }
                $signals['fields'][$name] = [
                    'model_signal' => round($model, 4), 'ocr_confidence' => $ocr, 'evidence_resolved' => (bool) ($e['has_evidence'] ?? false) || $corrected,
                    'human_corrected' => $corrected, 'validation_status' => $status, 'field_signal' => round($signal, 4), 'critical' => $meta['critical'],
                ];
            }
            $mean = $nonNull ? array_sum($nonNull) / count($nonNull) : 0.0;
            $extraction = 0.6 * $criticalMin + 0.4 * $mean;
            if ($vr->issues->where('severity', 'error')->isNotEmpty()) {
                $extraction = min($extraction, 0.50);
            } elseif ($vr->issues->where('severity', 'warning')->isNotEmpty()) {
                $extraction = min($extraction, 0.85);
            }
            $signals['extraction'] = round($extraction, 4);
        }

        $overall = $extraction === null ? $classificationSignal : min($classificationSignal, $extraction);
        $band = $overall >= $thresholds['auto_min'] ? 'high' : ($overall >= $thresholds['block_min'] ? 'medium' : 'low');

        return ConfidenceAssessment::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'case_version' => $case->version,
            'formula_version' => self::FORMULA, 'signals' => $signals, 'overall' => round($overall, 4), 'band' => $band, 'calibrated' => $calibrated,
        ]);
    }
}
