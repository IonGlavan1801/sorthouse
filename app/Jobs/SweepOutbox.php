<?php

namespace App\Jobs;

use App\Application\ActionExecution\OutboxProcessor;
use App\Models\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Picks up due retries and reconciles requests whose outcome stayed unknown. Scheduled every minute. */
class SweepOutbox implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(OutboxProcessor $processor): void
    {
        OutboxMessage::where('status', 'pending')->where('available_at', '<=', now())->limit(50)->pluck('id')->each(fn ($id) => ExecuteOutboxMessage::dispatch($id));
        OutboxMessage::where('status', 'pending_reconciliation')->where('updated_at', '<=', now()->subSeconds(config('sorthouse.pending_reconciliation_after_seconds')))
            ->limit(50)->pluck('id')->each(fn ($id) => $processor->reconcile($id));
    }
}
