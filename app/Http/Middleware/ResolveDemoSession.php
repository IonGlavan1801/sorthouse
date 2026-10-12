<?php

namespace App\Http\Middleware;

use App\Application\Demo\DemoSessionService;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The workspace is always derived server-side from the session; no client-supplied workspace_id is ever trusted. */
class ResolveDemoSession
{
    public function __construct(private DemoSessionService $demo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->session()->get('demo_session_id');
        $session = $sessionId ? $this->demo->resolve($sessionId) : null;
        if (! $session) {
            return response()->json(['error' => 'no_session', 'message' => 'Start a demo session first (or your session expired).'], 401);
        }
        $request->attributes->set('demo_session', $session);
        $request->attributes->set('workspace', Workspace::findOrFail($session->workspace_id));
        $reviewer = strtolower((string) $request->header('X-Reviewer', 'a'));
        $request->attributes->set('actor', 'demo:reviewer-'.(preg_match('/^[a-z0-9]{1,8}$/', $reviewer) ? $reviewer : 'a'));

        return $next($request);
    }
}
