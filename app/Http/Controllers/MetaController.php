<?php

namespace App\Http\Controllers;

use App\Application\Usage\BudgetGuard;
use App\Infrastructure\AI\CapabilityMatrix;
use App\Models\EvaluationRun;
use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetaController extends Controller
{
    public function health(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['status' => 'ok']);
    }

    public function capabilities(): JsonResponse
    {
        $configured = config('sorthouse.provider');

        return response()->json([
            'configured_provider' => $configured, 'matrix' => CapabilityMatrix::all(),
            'live_providers_configured' => collect(['openai', 'anthropic', 'gemini'])->filter(fn ($p) => ! empty(config("sorthouse.providers.{$p}.key")))->values(),
            'labels' => ['LIVE' => 'A real provider call', 'SIMULATED' => 'Deterministic baseline or simulated integration', 'FALLBACK' => 'A fallback path produced this result', 'BLOCKED' => 'Policy blocked automation'],
        ]);
    }

    public function evaluation(): JsonResponse
    {
        $runs = EvaluationRun::with('dataset')->latest()->limit(6)->get()->map(fn ($r) => [
            'id' => $r->id, 'dataset' => $r->dataset->key, 'provider' => $r->provider, 'model' => $r->model, 'simulated' => $r->simulated, 'policy' => $r->policy_version,
            'cases' => $r->case_count, 'metrics' => $r->metrics, 'config' => $r->config, 'at' => $r->created_at->toIso8601String(),
        ]);

        return response()->json(['runs' => $runs, 'note' => 'Metrics are measured on the included synthetic corpus only and do not generalise to real inboxes.']);
    }

    public function usage(Request $request, BudgetGuard $budget): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $events = UsageEvent::where('workspace_id', $workspace->id)->get();

        return response()->json([
            'session' => ['calls' => $events->count(), 'input_tokens' => $events->sum('input_tokens'), 'output_tokens' => $events->sum('output_tokens'), 'estimated_cost_usd' => round($events->sum('estimated_cost_usd'), 6)],
            'daily_budget_usd' => config('sorthouse.budget.daily_usd'), 'budget_remaining_usd' => round($budget->remaining(), 4), 'pricing_version' => config('sorthouse.pricing.version'),
            'note' => 'Estimated from a versioned price table; not reconciled with a provider invoice.',
        ]);
    }
}
