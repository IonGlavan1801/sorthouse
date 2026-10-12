<?php

namespace App\Console\Commands;

use App\Application\Evaluation\EvaluationRunner;
use App\Application\Policy\ActionPolicy;
use App\Models\EvaluationRun;
use Illuminate\Console\Command;

class EnsureEvaluation extends Command
{
    protected $signature = 'eval:ensure';

    protected $description = 'Run dev and holdout evaluations once per policy version and provider so the demo shows measured numbers';

    public function handle(EvaluationRunner $runner, ActionPolicy $policy): int
    {
        foreach (['dev', 'holdout'] as $ds) {
            $exists = EvaluationRun::whereHas('dataset', fn ($q) => $q->where('key', $ds))->where('policy_version', $policy->activeVersion()->version)->where('provider', config('sorthouse.provider'))->exists();
            if (! $exists) {
                $run = $runner->run($ds, [$ds, 'adversarial', 'failure']);
                $this->info("Stored {$ds} evaluation ({$run->case_count} cases, provider {$run->provider}).");
            }
        }

        return self::SUCCESS;
    }
}
