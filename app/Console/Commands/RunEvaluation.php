<?php

namespace App\Console\Commands;

use App\Application\Evaluation\EvaluationRunner;
use Illuminate\Console\Command;

class RunEvaluation extends Command
{
    protected $signature = 'eval:run {dataset=holdout : dev|holdout} {--provider= : override the configured provider} {--with-safety=1 : include adversarial + failure fixtures} {--json= : write metrics to this file}';

    protected $description = 'Run the pipeline over a labelled split and store measured metrics';

    public function handle(EvaluationRunner $runner): int
    {
        $dataset = $this->argument('dataset');
        $splits = [$dataset];
        if ($this->option('with-safety')) {
            $splits = array_merge($splits, ['adversarial', 'failure']);
        }
        $bar = $this->output->createProgressBar();
        $run = $runner->run($dataset, $splits, $this->option('provider') ?: null, fn () => $bar->advance());
        $bar->finish();
        $this->newLine();
        $m = $run->metrics;
        $this->table(['metric', 'value'], [
            ['cases', $run->case_count], ['provider', $run->provider.' ('.($run->simulated ? 'SIMULATED' : 'LIVE').')'], ['policy', $run->policy_version],
            ['macro F1', $m['classification']['macro_f1']], ['accuracy', $m['classification']['accuracy']],
            ['critical field accuracy', $m['extraction']['critical_field_accuracy']], ['document exactness', $m['extraction']['document_exactness']],
            ['automation coverage', $m['policy']['automation_coverage']], ['auto precision', $m['policy']['auto_precision']], ['false auto actions', $m['policy']['false_auto_actions']],
            ['review rate', $m['policy']['review_rate']], ['unsafe blocked', $m['policy']['unsafe_blocked'].'/'.$m['policy']['unsafe_total']],
            ['latency p50/p95 ms', $m['system']['latency_ms_p50'].' / '.$m['system']['latency_ms_p95']],
        ]);
        if ($m['policy']['false_auto_actions'] > 0) {
            $this->warn('False auto actions: '.implode(', ', $m['policy']['false_auto_keys']));
        }
        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode(['run' => ['id' => $run->id, 'dataset' => $dataset, 'provider' => $run->provider, 'model' => $run->model, 'simulated' => $run->simulated, 'policy' => $run->policy_version, 'cases' => $run->case_count, 'config' => $run->config, 'at' => now()->toIso8601String()], 'metrics' => $m], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
