<?php

namespace App\Console\Commands;

use App\Console\Support\CorpusBuilder;
use Illuminate\Console\Command;

class BuildFixtures extends Command
{
    protected $signature = 'fixtures:build';

    protected $description = 'Regenerate the deterministic synthetic corpus in /fixtures (needs dev dependencies: FPDF, GD)';

    public function handle(): int
    {
        if (! class_exists(\FPDF::class)) {
            $this->error('FPDF is a dev dependency: run composer install with dev packages.');

            return self::FAILURE;
        }
        $summary = (new CorpusBuilder(base_path('fixtures')))->build();
        foreach ($summary as $split => $cats) {
            $this->line(sprintf('%-16s %d  %s', $split, array_sum($cats), json_encode($cats)));
        }

        return self::SUCCESS;
    }
}
