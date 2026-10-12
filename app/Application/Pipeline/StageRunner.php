<?php

namespace App\Application\Pipeline;

use App\Models\StageTiming;
use App\Models\TriageCase;

/** Wraps a pipeline stage so every stage leaves a measured timing row (never hard-coded durations). */
final class StageRunner
{
    /**
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public function run(TriageCase $case, string $stage, callable $fn, array &$detail = [])
    {
        $started = now();
        $t0 = hrtime(true);
        $status = 'ok';
        $retries = 0;
        try {
            $result = $fn();
            if (is_array($result) && isset($result['_retries'])) {
                $retries = (int) $result['_retries'];
            }

            return $result;
        } catch (\Throwable $e) {
            $status = 'failed';
            $detail['error'] = $e->getMessage();
            throw $e;
        } finally {
            StageTiming::create([
                'workspace_id' => $case->workspace_id,
                'triage_case_id' => $case->id,
                'correlation_id' => $case->correlation_id,
                'stage' => $stage,
                'status' => $status === 'ok' && ($detail['status'] ?? null) ? $detail['status'] : $status,
                'duration_ms' => (int) ((hrtime(true) - $t0) / 1e6),
                'retries' => $detail['retries'] ?? $retries,
                'detail' => $detail ?: null,
                'started_at' => $started,
            ]);
        }
    }
}
