<?php

namespace App\Http\Controllers;

use App\Application\Demo\DemoSessionService;
use App\Application\Demo\FixtureCatalog;
use App\Application\Demo\ScenarioRunner;
use App\Http\Presenters\CasePresenter;
use App\Infrastructure\Faults\FaultInjector;
use App\Jobs\PurgeWorkspace;
use App\Models\AuditEvent;
use App\Models\TriageCase;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private DemoSessionService $demo, private CasePresenter $presenter, private FaultInjector $faults) {}

    public function launch(Request $request): JsonResponse
    {
        $launched = $this->demo->launch($request->ip());
        $request->session()->regenerate();
        $request->session()->put('demo_session_id', $launched['session']->id);

        return response()->json($this->state($launched['session']->id, $launched['workspace']), 201);
    }

    public function current(Request $request): JsonResponse
    {
        $session = $request->attributes->get('demo_session');

        return response()->json($this->state($session->id, $request->attributes->get('workspace')));
    }

    public function destroy(Request $request): JsonResponse
    {
        $session = $request->attributes->get('demo_session');
        $this->demo->revoke($session);
        PurgeWorkspace::dispatch($session->workspace_id)->delay(now()->addSeconds(5));
        $request->session()->forget('demo_session_id');

        return response()->json(['status' => 'disabled', 'purge' => 'queued', 'message' => 'The session is disabled now; stored data is purged asynchronously.']);
    }

    public function inbox(Request $request, string $id): JsonResponse
    {
        $this->assertOwn($request, $id);
        $workspace = $request->attributes->get('workspace');
        $cases = TriageCase::where('workspace_id', $workspace->id)
            ->with(['message.attachments', 'classificationRuns', 'policyDecisions.policyVersion', 'reviewDecisions'])->get()
            ->sortByDesc(fn ($c) => $c->message->received_at)->values();
        $summaries = $cases->map(fn ($c) => $this->presenter->summary($c))->all();

        return response()->json(['cases' => $summaries, 'counters' => $this->counters($summaries)]);
    }

    public function inject(Request $request, string $id, string $fixture, FixtureCatalog $catalog): JsonResponse
    {
        $this->assertOwn($request, $id);
        abort_unless($catalog->get($fixture), 404, 'Unknown fixture');
        $result = $this->demo->inject($request->attributes->get('workspace'), $fixture, $fixture.'-'.($ref = bin2hex(random_bytes(3))), 1, variant: $ref);

        return response()->json(['case_id' => $result->case->id, 'duplicate' => $result->duplicate], 201);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        $this->assertOwn($request, $id);
        $workspace = $request->attributes->get('workspace');
        $events = AuditEvent::where('workspace_id', $workspace->id)->latest('created_at')->limit(40)->get()
            ->map(fn ($e) => ['case_id' => $e->triage_case_id, 'event' => $e->event, 'actor' => $e->actor, 'from' => $e->from_state, 'to' => $e->to_state, 'at' => $e->created_at->toIso8601String()]);

        return response()->json(['events' => $events, 'counters' => $this->counterSnapshot($workspace), 'faults' => $this->faults->active($workspace->id)]);
    }

    public function scenario(Request $request, string $scenario, ScenarioRunner $runner): JsonResponse
    {
        $result = $runner->run($request->attributes->get('workspace'), $scenario);
        $case = $result['case'] ?? null;
        unset($result['case']);

        return response()->json($result + ['case_id' => $case?->id], 201);
    }

    public function scenarioReset(Request $request, ScenarioRunner $runner): JsonResponse
    {
        $runner->reset($request->attributes->get('workspace'));

        return response()->json(['status' => 'reset', 'faults' => []]);
    }

    public function scenarios(): JsonResponse
    {
        return response()->json(['scenarios' => collect(ScenarioRunner::SCENARIOS)->map(fn ($s, $k) => $s + ['key' => $k])->values()]);
    }

    private function assertOwn(Request $request, string $id): void
    {
        abort_unless($request->attributes->get('demo_session')->id === $id, 404);
    }

    private function counterSnapshot(Workspace $workspace): array
    {
        $cases = TriageCase::where('workspace_id', $workspace->id)->with(['message.attachments', 'classificationRuns', 'policyDecisions', 'reviewDecisions'])->get();

        return $this->counters($cases->map(fn ($c) => $this->presenter->summary($c))->all());
    }

    private function counters(array $summaries): array
    {
        $c = collect($summaries);
        $inflight = ['received', 'sanitizing', 'processing', 'classified', 'extracting', 'validating', 'awaiting_policy', 'failed_retryable'];

        return [
            'ready' => $c->filter(fn ($s) => $s['state'] === 'awaiting_review' && $s['issue_count'] === 0)->count(),
            'needs_review' => $c->filter(fn ($s) => ($s['state'] === 'awaiting_review' && $s['issue_count'] > 0) || $s['state'] === 'needs_review')->count(),
            'blocked' => $c->where('state', 'blocked')->count(),
            'executed' => $c->filter(fn ($s) => in_array($s['state'], ['executed'], true))->count(),
            'in_progress' => $c->filter(fn ($s) => in_array($s['state'], array_merge($inflight, ['approved', 'queued_action', 'executing', 'pending_reconciliation', 'execution_failed']), true))->count(),
            'rejected' => $c->where('state', 'rejected')->count(),
            'total' => $c->count(),
        ];
    }

    private function state(string $sessionId, Workspace $workspace): array
    {
        $session = $this->demo->resolve($sessionId);

        return [
            'session_id' => $sessionId, 'expires_at' => $session?->expires_at->toIso8601String(), 'workspace' => $workspace->name,
            'provider' => ['name' => config('sorthouse.provider'), 'live' => config('sorthouse.provider') !== 'heuristic'],
            'faults' => $this->faults->active($workspace->id),
        ];
    }
}
