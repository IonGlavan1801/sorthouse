<?php

namespace App\Jobs;

use App\Application\ActionExecution\OutboxProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteOutboxMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $outboxId) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->outboxId);
        // a retry scheduled by the processor becomes available later; the scheduler sweep picks it up
    }
}
