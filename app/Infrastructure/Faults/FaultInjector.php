<?php

namespace App\Infrastructure\Faults;

use App\Models\DemoSession;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, per-session fault adapter used by the Failure Lab and by tests.
 * Flags are counters: ['llm_timeout' => 2] fails the next two LLM attempts and then lets calls through.
 */
final class FaultInjector
{
    public const KEYS = ['llm_timeout', 'ocr_timeout', 'llm_invalid_json', 'rate_limit', 'worker_crash_after_persist', 'action_timeout', 'validation_lookup_timeout', 'budget_exhausted'];

    public function set(string $workspaceId, string $key, int $times): void
    {
        $session = $this->session($workspaceId);
        if (! $session) {
            return;
        }
        $flags = $session->fault_flags ?? [];
        $flags[$key] = $times;
        $session->update(['fault_flags' => $flags]);
    }

    public function clear(string $workspaceId): void
    {
        $this->session($workspaceId)?->update(['fault_flags' => []]);
    }

    public function active(string $workspaceId): array
    {
        return array_filter($this->session($workspaceId)?->fault_flags ?? [], fn ($n) => $n > 0);
    }

    /** True once per remaining count; atomically decremented. */
    public function consume(string $workspaceId, string $key): bool
    {
        return DB::transaction(function () use ($workspaceId, $key) {
            $session = DemoSession::where('workspace_id', $workspaceId)->orderByDesc('created_at')->lockForUpdate()->first();
            $flags = $session?->fault_flags ?? [];
            if (($flags[$key] ?? 0) <= 0) {
                return false;
            }
            $flags[$key]--;
            $session->update(['fault_flags' => $flags]);

            return true;
        });
    }

    private function session(string $workspaceId): ?DemoSession
    {
        return DemoSession::where('workspace_id', $workspaceId)->orderByDesc('created_at')->first();
    }
}
