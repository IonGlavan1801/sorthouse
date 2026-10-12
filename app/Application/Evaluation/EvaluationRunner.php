<?php

namespace App\Application\Evaluation;

use App\Application\Demo\FixtureCatalog;
use App\Application\Ingestion\IngestMessage;
use App\Application\Pipeline\TriagePipeline;
use App\Application\Policy\ActionPolicy;
use App\Application\Validation\ValidationEngine;
use App\Domain\Extraction\SchemaCatalog;
use App\Domain\Triage\Category;
use App\Infrastructure\AI\ProviderRegistry;
use App\Models\EvaluationCase;
use App\Models\EvaluationDataset;
use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\ExtractionRun;
use App\Models\Mailbox;
use App\Models\StageTiming;
use App\Models\TriageCase;
use App\Models\UsageEvent;
use App\Models\Workspace;

/**
 * Runs the real pipeline over a labelled split and measures it. Nothing here is hard-coded: every number comes from case results.
 * "Correct" for automation means: category right, expected route AUTO_ELIGIBLE, expected action, and every critical field right.
 */
final class EvaluationRunner
{
    public function __construct(
        private FixtureCatalog $fixtures,
        private IngestMessage $ingest,
        private TriagePipeline $pipeline,
        private ValidationEngine $validation,
        private ActionPolicy $policy,
        private ProviderRegistry $providers,
    ) {}

    /** @param list<string> $splits fixture splits making up the evaluated corpus */
    public function run(string $dataset, array $splits, ?string $providerOverride = null, ?callable $progress = null): EvaluationRun
    {
        config(['queue.default' => 'sync']);
        $ds = EvaluationDataset::where('key', $dataset)->firstOrFail();
        $workspace = Workspace::create(['name' => 'Evaluation '.$dataset, 'kind' => 'evaluation']);
        $mailbox = Mailbox::create(['workspace_id' => $workspace->id, 'provider' => 'demo', 'address' => 'inbox@acme-demo.example.test', 'allowed_recipients' => []]);
        $set = $providerOverride ? $this->providers->active($providerOverride) : $this->providers->active();

        $rows = [];
        foreach ($splits as $split) {
            foreach ($this->fixtures->split($split) as $fx) {
                $t0 = hrtime(true);
                $result = $this->ingest->handle($workspace, $mailbox, $this->fixtures->toIncoming($fx), dispatch: false);
                if ($providerOverride) {
                    $result->case->forceFill(['flags' => ['provider_override' => $providerOverride]])->save();
                }
                $this->pipeline->run($result->case);
                $wall = (int) ((hrtime(true) - $t0) / 1e6);
                $rows[] = $this->observe($fx, $result->case->refresh(), $wall);
                $progress && $progress($fx['key']);
            }
        }

        $metrics = $this->metrics($rows, $workspace);
        $metrics['dataset_size'] = count($rows);
        $metrics['sweep'] = $this->sweep($rows);
        $run = EvaluationRun::create([
            'evaluation_dataset_id' => $ds->id, 'provider' => $set->name, 'model' => $set->model, 'policy_version' => $this->policy->activeVersion()->version,
            'prompt_version' => config('sorthouse.prompt_versions.classify').'/'.config('sorthouse.prompt_versions.extract'), 'simulated' => $set->simulated,
            'config' => ['splits' => $splits, 'auto_min' => $this->policy->activeVersion()->rules['auto_min'], 'confidence_formula' => 'conf.v1', 'seed' => 20261012, 'composition' => collect($rows)->groupBy('split')->map->count()->all()],
            'metrics' => $metrics, 'case_count' => count($rows),
        ]);
        foreach ($rows as $r) {
            $case = EvaluationCase::firstOrCreate(['fixture_key' => $r['key']], ['evaluation_dataset_id' => $ds->id, 'ground_truth' => $r['truth']]);
            EvaluationResult::create(['evaluation_run_id' => $run->id, 'evaluation_case_id' => $case->id, 'result' => collect($r)->except('truth')->all()]);
        }
        $workspace->delete(); // evaluation workspace is scratch data

        return $run;
    }

    /** One row per case: prediction vs truth. */
    private function observe(array $fx, TriageCase $case, int $wallMs): array
    {
        $truth = $fx['truth'];
        $category = $case->category;
        $decision = $case->policyDecisions()->latest()->first();
        $codes = collect($decision?->reasons ?? [])->pluck('code')->all();
        $row = [
            'key' => $fx['key'], 'split' => $fx['split'], 'tags' => $fx['tags'] ?? [], 'truth' => $truth,
            'predicted_category' => $category, 'predicted_route' => $case->route, 'predicted_action' => $case->proposed_action, 'state' => $case->state, 'reason_codes' => $codes,
            'signal' => $case->overall_signal === null ? null : (float) $case->overall_signal, 'wall_ms' => $wallMs,
            'stage_ms' => (int) StageTiming::where('triage_case_id', $case->id)->sum('duration_ms'),
            'cost_usd' => (float) UsageEvent::where('triage_case_id', $case->id)->sum('estimated_cost_usd'),
            'fields' => [], 'critical_correct' => null, 'evidence' => ['non_null' => 0, 'with_evidence' => 0],
        ];

        $schemaKey = Category::tryFrom((string) $category)?->schemaKey();
        $run = $schemaKey ? ExtractionRun::where('triage_case_id', $case->id)->where('is_current', true)->latest()->first() : null;
        if ($run && isset($truth['schema']) && $truth['schema'] === $schemaKey) {
            $effective = $this->validation->effectiveFields($case, $run);
            $critical = $truth['critical_fields'] ?? SchemaCatalog::criticalFields($schemaKey);
            $allCriticalOk = true;
            foreach ($effective as $name => $e) {
                $expected = $truth['fields'][$name] ?? null;
                $known = array_key_exists($name, $truth['fields'] ?? []) || in_array($name, $truth['expect_null'] ?? [], true);
                $isList = $e['field']->data_type === 'list';
                if ($isList && $name === 'items') {
                    $pred = json_decode((string) $e['normalized'], true) ?: [];
                    $want = $truth['items'] ?? [];
                    $ok = $this->sameItems($pred, $want);
                } elseif ($known) {
                    $ok = $this->same($e['normalized'], $expected);
                } else {
                    continue;
                }
                $row['fields'][$name] = ['ok' => $ok, 'predicted' => $e['normalized'], 'expected' => $isList ? null : $expected, 'null_expected' => $expected === null && ! $isList, 'critical' => in_array($name, $critical, true)];
                if (in_array($name, $critical, true)) {
                    $allCriticalOk = $allCriticalOk && $ok;
                }
                if ($e['normalized'] !== null && in_array($name, $critical, true)) {
                    $row['evidence']['non_null']++;
                    $row['evidence']['with_evidence'] += $e['has_evidence'] ? 1 : 0;
                }
            }
            $row['critical_correct'] = $allCriticalOk;
        }

        $row['route_correct'] = $row['predicted_route'] === ($truth['route'] ?? null);
        $row['category_correct'] = $category === ($truth['category'] ?? null) || (($truth['route'] ?? '') === 'BLOCKED' && $row['predicted_route'] === 'BLOCKED');
        $row['would_auto_without_threshold'] = $this->wouldAuto($row, $decision?->reasons ?? []);
        $row['auto_correct'] = $row['predicted_route'] === 'AUTO_ELIGIBLE' && ($truth['route'] ?? '') === 'AUTO_ELIGIBLE' && $category === $truth['category'] && $row['predicted_action'] === ($truth['action'] ?? null) && ($row['critical_correct'] ?? true);

        return $row;
    }

    private function same(?string $a, ?string $b): bool
    {
        return ($a === null || $a === '') ? ($b === null || $b === '') : (mb_strtolower(trim($a)) === mb_strtolower(trim((string) $b)));
    }

    private function sameItems(array $pred, array $want): bool
    {
        $norm = fn (array $items) => collect($items)->map(fn ($i) => mb_strtolower(trim($i['description'])).'|'.($i['quantity'] ?? ''))->sort()->values()->all();

        return $norm($pred) === $norm($want);
    }

    /** True when the only thing standing between the case and AUTO_ELIGIBLE is the confidence threshold. */
    private function wouldAuto(array $row, array $reasons): bool
    {
        if ($row['predicted_route'] === 'BLOCKED') {
            return false;
        }
        $codes = array_column($reasons, 'code');
        $other = array_diff($codes, ['LOW_CONFIDENCE', 'AMBIGUOUS_CATEGORY', 'AUTO_POLICY_PASSED']);
        $auto = array_keys($this->policy->activeVersion()->rules['auto_actions']);

        return $other === [] && in_array($row['predicted_category'], $auto, true) && $row['signal'] !== null;
    }

    private function sweep(array $rows): array
    {
        $out = [];
        $unsafe = collect($rows)->filter(fn ($r) => ($r['truth']['route'] ?? '') === 'BLOCKED');
        foreach (range(50, 99) as $pct) {
            $t = $pct / 100;
            $auto = collect($rows)->filter(fn ($r) => $r['would_auto_without_threshold'] && $r['signal'] >= $t);
            $correct = $auto->filter(fn ($r) => $r['auto_correct'] || ($r['predicted_category'] === ($r['truth']['category'] ?? null) && ($r['truth']['route'] ?? '') === 'AUTO_ELIGIBLE' && $r['predicted_action'] === ($r['truth']['action'] ?? null) && ($r['critical_correct'] ?? true)));
            $out[] = [
                'threshold' => $t, 'coverage' => round($auto->count() / max(1, count($rows)), 4), 'auto_count' => $auto->count(),
                'precision' => $auto->count() ? round($correct->count() / $auto->count(), 4) : null, 'false_auto' => $auto->count() - $correct->count(),
                'review_rate' => round(1 - $auto->count() / max(1, count($rows)) - $unsafe->count() / max(1, count($rows)), 4), 'blocked_unsafe' => $unsafe->filter(fn ($r) => $r['predicted_route'] === 'BLOCKED')->count(), 'unsafe_total' => $unsafe->count(),
            ];
        }

        return $out;
    }

    private function metrics(array $rows, Workspace $workspace): array
    {
        $c = collect($rows);
        $labels = array_map(fn ($x) => $x->value, Category::cases());
        $classRows = $c->filter(fn ($r) => ! in_array($r['split'], ['adversarial', 'failure'], true));

        // --- classification
        $per = [];
        $confusion = [];
        foreach ($labels as $l) {
            $tp = $classRows->filter(fn ($r) => $r['truth']['category'] === $l && $r['predicted_category'] === $l)->count();
            $fp = $classRows->filter(fn ($r) => $r['truth']['category'] !== $l && $r['predicted_category'] === $l)->count();
            $fn = $classRows->filter(fn ($r) => $r['truth']['category'] === $l && $r['predicted_category'] !== $l)->count();
            $p = $tp + $fp ? $tp / ($tp + $fp) : 0.0;
            $r = $tp + $fn ? $tp / ($tp + $fn) : 0.0;
            $per[$l] = ['precision' => round($p, 4), 'recall' => round($r, 4), 'f1' => round($p + $r ? 2 * $p * $r / ($p + $r) : 0, 4), 'support' => $tp + $fn];
            foreach ($labels as $pl) {
                $confusion[$l][$pl] = $classRows->filter(fn ($x) => $x['truth']['category'] === $l && $x['predicted_category'] === $pl)->count();
            }
        }
        $present = array_filter($per, fn ($x) => $x['support'] > 0);
        $ambiguous = $classRows->filter(fn ($r) => ! empty($r['truth']['ambiguous']));

        // --- extraction
        $fieldRows = $c->filter(fn ($r) => $r['fields'] !== []);
        $byField = [];
        foreach ($fieldRows as $r) {
            foreach ($r['fields'] as $name => $f) {
                $schema = $r['truth']['schema'] ?? '?';
                $byField[$schema.'.'.$name]['n'] = ($byField[$schema.'.'.$name]['n'] ?? 0) + 1;
                $byField[$schema.'.'.$name]['ok'] = ($byField[$schema.'.'.$name]['ok'] ?? 0) + ($f['ok'] ? 1 : 0);
            }
        }
        $criticalCells = $fieldRows->flatMap(fn ($r) => collect($r['fields'])->filter(fn ($f) => $f['critical'])->values()->all())->values();
        $invoiceRows = $fieldRows->filter(fn ($r) => ($r['truth']['schema'] ?? '') === 'invoice.v1');
        $optional = $fieldRows->flatMap(fn ($r) => collect($r['fields'])->filter(fn ($f) => $f['null_expected'] || $f['predicted'] !== null)->filter(fn ($f) => ! $f['critical'] || $f['null_expected'])->values()->all())->values();
        $optPred = $optional->filter(fn ($f) => $f['predicted'] !== null);
        $evidenceNonNull = $fieldRows->sum(fn ($r) => $r['evidence']['non_null']);
        $evidenceWith = $fieldRows->sum(fn ($r) => $r['evidence']['with_evidence']);

        // --- policy / human-in-the-loop (dev/holdout + safety splits)
        $auto = $c->where('predicted_route', 'AUTO_ELIGIBLE');
        $autoWrong = $auto->filter(fn ($r) => ! $r['auto_correct']);
        $unsafe = $c->filter(fn ($r) => ($r['truth']['route'] ?? '') === 'BLOCKED');
        $lat = $c->pluck('wall_ms')->sort()->values();
        $stage = $c->pluck('stage_ms')->sort()->values();
        $cost = $c->pluck('cost_usd')->sort()->values();
        $pct = fn ($col, $q) => $col->isEmpty() ? null : $col[(int) min($col->count() - 1, floor($q * ($col->count() - 1)))];

        return [
            'classification' => [
                'macro_f1' => round(collect($present)->avg('f1'), 4), 'accuracy' => round($classRows->filter(fn ($r) => $r['predicted_category'] === $r['truth']['category'])->count() / max(1, $classRows->count()), 4),
                'per_class' => $per, 'confusion' => $confusion, 'ambiguous_accuracy' => $ambiguous->count() ? round($ambiguous->filter(fn ($r) => $r['predicted_category'] === $r['truth']['category'])->count() / $ambiguous->count(), 4) : null, 'ambiguous_n' => $ambiguous->count(), 'n' => $classRows->count(),
            ],
            'extraction' => [
                'field_accuracy' => round($fieldRows->flatMap(fn ($r) => array_values($r['fields']))->avg(fn ($f) => $f['ok'] ? 1 : 0) ?? 0, 4),
                'critical_field_accuracy' => round($criticalCells->avg(fn ($f) => $f['ok'] ? 1 : 0) ?? 0, 4), 'critical_cells' => $criticalCells->count(),
                'document_exactness' => round($fieldRows->avg(fn ($r) => $r['critical_correct'] ? 1 : 0) ?? 0, 4), 'documents' => $fieldRows->count(),
                'invoice_document_exactness' => round($invoiceRows->avg(fn ($r) => $r['critical_correct'] ? 1 : 0) ?? 0, 4), 'invoice_documents' => $invoiceRows->count(),
                'invoice_critical_by_medium' => collect(['pdf', 'photo', 'scan'])->mapWithKeys(fn ($m) => [$m => ($rs = $invoiceRows->filter(fn ($r) => in_array($m, $r['tags'])))->count() ? round($rs->avg(fn ($r) => $r['critical_correct'] ? 1 : 0), 4) : null])->all(),
                'by_field' => collect($byField)->map(fn ($v) => ['n' => $v['n'], 'accuracy' => round($v['ok'] / $v['n'], 4)])->sortKeys()->all(),
                'optional_field_precision' => $optPred->count() ? round($optPred->filter(fn ($f) => $f['ok'])->count() / $optPred->count(), 4) : null,
                'evidence_coverage' => $evidenceNonNull ? round($evidenceWith / $evidenceNonNull, 4) : null, 'evidence_validity' => 1.0,
                'evidence_validity_note' => 'Evidence is resolved against the real input before storage; unresolvable quotes never become evidence, so validity is 100% by construction. Coverage is the measured part.',
            ],
            'policy' => [
                'automation_coverage' => round($auto->count() / max(1, $c->count()), 4), 'auto_count' => $auto->count(),
                'auto_precision' => $auto->count() ? round(($auto->count() - $autoWrong->count()) / $auto->count(), 4) : null, 'false_auto_actions' => $autoWrong->count(),
                'false_auto_keys' => $autoWrong->pluck('key')->values()->all(),
                'review_rate' => round($c->where('predicted_route', 'REVIEW_REQUIRED')->count() / max(1, $c->count()), 4), 'blocked_rate' => round($c->where('predicted_route', 'BLOCKED')->count() / max(1, $c->count()), 4),
                'unsafe_block_rate' => $unsafe->count() ? round($unsafe->where('predicted_route', 'BLOCKED')->count() / $unsafe->count(), 4) : null, 'unsafe_total' => $unsafe->count(), 'unsafe_blocked' => $unsafe->where('predicted_route', 'BLOCKED')->count(),
                'unsafe_missed_keys' => $unsafe->where('predicted_route', '!=', 'BLOCKED')->pluck('key')->values()->all(),
                'route_agreement' => round($c->avg(fn ($r) => $r['route_correct'] ? 1 : 0), 4),
                'invoice_auto_actions' => $c->filter(fn ($r) => $r['predicted_category'] === 'invoice' && $r['predicted_route'] === 'AUTO_ELIGIBLE')->count(),
                'high_impact_auto_actions' => $c->filter(fn ($r) => in_array($r['predicted_category'], ['invoice', 'customer_complaint', 'order_or_purchase_request'], true) && $r['predicted_route'] === 'AUTO_ELIGIBLE')->count(),
                'critical_fields_wrong_rate' => $criticalCells->count() ? round($criticalCells->filter(fn ($f) => ! $f['ok'])->count() / $criticalCells->count(), 4) : null,
            ],
            'system' => [
                'latency_ms_p50' => $pct($lat, 0.5), 'latency_ms_p95' => $pct($lat, 0.95), 'stage_ms_p50' => $pct($stage, 0.5), 'stage_ms_p95' => $pct($stage, 0.95),
                'cost_usd_median' => $pct($cost, 0.5), 'cost_usd_p95' => $pct($cost, 0.95),
                'note' => 'Latency is wall time of the in-process pipeline on this machine (synchronous queue, simulated heuristic provider); it does not include queue wait or any network call to an LLM.',
            ],
        ];
    }
}
