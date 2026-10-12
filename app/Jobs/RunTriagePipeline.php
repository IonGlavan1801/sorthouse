<?php

namespace App\Jobs;

use App\Application\Pipeline\TriagePipeline;
use App\Models\TriageCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** Receives internal ids only and reloads the case with its workspace scope. */
class RunTriagePipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public string $caseId, public string $workspaceId) {}

    public function backoff(): array
    {
        return [2, 8, 20];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->caseId))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(TriagePipeline $pipeline): void
    {
        $case = TriageCase::where('workspace_id', $this->workspaceId)->findOrFail($this->caseId);
        $pipeline->run($case, $this->attempts());
    }
}
