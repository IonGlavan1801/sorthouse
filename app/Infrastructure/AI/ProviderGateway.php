<?php

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Exceptions\ProviderException;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;
use App\Infrastructure\AI\Exceptions\ProviderRateLimited;
use App\Infrastructure\AI\Exceptions\ProviderTimeout;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\ProviderCall;
use App\Models\TriageCase;

/** Every model/OCR call goes through here: fault injection, bounded retries with backoff + jitter, and a provider_calls row per attempt. */
final class ProviderGateway
{
    public function __construct(private FaultInjector $faults) {}

    /**
     * @template T
     *
     * @param  callable(int):T  $fn
     * @param  string  $kind  llm|ocr|parse
     * @return array{result:T,retries:int}
     */
    public function call(TriageCase $case, string $stage, string $provider, string $model, callable $fn, string $kind = 'llm'): array
    {
        $max = (int) config('sorthouse.resilience.max_attempts');
        $last = null;
        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $started = hrtime(true);
            try {
                $this->injectFaults($case->workspace_id, $kind);
                $result = $fn($attempt);
                $this->log($case, $stage, $provider, $model, 'ok', $attempt, $started);

                return ['result' => $result, 'retries' => $attempt - 1];
            } catch (ProviderException $e) {
                $last = $e;
                $this->log($case, $stage, $provider, $model, $e->kind, $attempt, $started, $e->getMessage());
                if (! $e->retryable || $attempt === $max) {
                    break;
                }
                $this->backoff($attempt);
            }
        }

        throw $last;
    }

    private function injectFaults(string $workspaceId, string $kind): void
    {
        if ($kind === 'parse') {
            return;
        }
        if ($kind === 'ocr') {
            if ($this->faults->consume($workspaceId, 'ocr_timeout')) {
                throw new ProviderTimeout('OCR timeout (injected by Failure Lab)');
            }

            return;
        }
        if ($this->faults->consume($workspaceId, 'llm_timeout')) {
            throw new ProviderTimeout('LLM timeout (injected by Failure Lab)');
        }
        if ($this->faults->consume($workspaceId, 'rate_limit')) {
            throw new ProviderRateLimited('HTTP 429 (injected by Failure Lab)');
        }
        if ($this->faults->consume($workspaceId, 'llm_invalid_json')) {
            throw new ProviderInvalidOutput('Invalid JSON (injected by Failure Lab)');
        }
    }

    private function backoff(int $attempt): void
    {
        if (! config('sorthouse.resilience.sleep')) {
            return;
        }
        $base = (int) config('sorthouse.resilience.base_delay_ms') * (2 ** ($attempt - 1));
        usleep(($base + random_int(0, (int) ($base / 2))) * 1000);
    }

    private function log(TriageCase $case, string $stage, string $provider, string $model, string $status, int $attempt, int $started, ?string $error = null): void
    {
        ProviderCall::create([
            'workspace_id' => $case->workspace_id,
            'triage_case_id' => $case->id,
            'correlation_id' => $case->correlation_id,
            'stage' => $stage,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'attempt' => $attempt,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'error' => $error,
        ]);
    }
}
