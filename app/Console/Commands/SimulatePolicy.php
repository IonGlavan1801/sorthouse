<?php

namespace App\Console\Commands;

use App\Models\EvaluationResult;
use App\Models\EvaluationRun;
use App\Models\PolicyVersion;
use Illuminate\Console\Command;

class SimulatePolicy extends Command
{
    protected $signature = 'policy:simulate {candidate=policy.v2-candidate} {dataset=holdout}';

    protected $description = 'Replay the latest evaluation run under a candidate policy version: what would have been auto-processed?';

    public function handle(): int
    {
        $run = EvaluationRun::whereHas('dataset', fn ($q) => $q->where('key', $this->argument('dataset')))->latest()->first();
        $candidate = PolicyVersion::where('version', $this->argument('candidate'))->first();
        if (! $run || ! $candidate) {
            $this->error('Need an evaluation run and an existing policy version.');

            return self::FAILURE;
        }
        $rows = $run->hasMany(EvaluationResult::class, 'evaluation_run_id')->get()->map(fn ($r) => $r->result);
        $summ = function (float $t) use ($rows) {
            $auto = $rows->filter(fn ($r) => $r['would_auto_without_threshold'] && $r['signal'] >= $t);
            $ok = $auto->filter(fn ($r) => $r['correct_if_automated']);

            return ['auto' => $auto->count(), 'coverage' => round($auto->count() / max(1, $rows->count()), 4), 'false_auto' => $auto->count() - $ok->count()];
        };
        $cur = $summ((float) $run->config['auto_min']);
        $new = $summ((float) $candidate->rules['auto_min']);
        $this->table(['policy', 'auto_min', 'auto-processed', 'coverage', 'false auto actions'], [
            [$run->policy_version, $run->config['auto_min'], $cur['auto'], $cur['coverage'], $cur['false_auto']],
            [$candidate->version, $candidate->rules['auto_min'], $new['auto'], $new['coverage'], $new['false_auto']],
        ]);
        $this->line($new['false_auto'] > $cur['false_auto'] ? 'The candidate raises false auto actions: do not activate.' : 'No increase in false auto actions on this set (a small synthetic set is weak evidence).');

        return self::SUCCESS;
    }
}
