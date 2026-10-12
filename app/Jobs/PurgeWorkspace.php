<?php

namespace App\Jobs;

use App\Application\Demo\DemoSessionService;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PurgeWorkspace implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $workspaceId) {}

    public function handle(DemoSessionService $service): void
    {
        if ($workspace = Workspace::find($this->workspaceId)) {
            $service->purge($workspace);
        }
    }
}
