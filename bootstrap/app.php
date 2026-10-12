<?php

use App\Application\Pipeline\InvalidTransition;
use App\Application\Pipeline\PolicyViolation;
use App\Application\Pipeline\VersionConflict;
use App\Http\Middleware\ResolveDemoSession;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['demo.session' => ResolveDemoSession::class]);
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (VersionConflict $e) => response()->json(['error' => 'version_conflict', 'message' => $e->getMessage(), 'expected_version' => $e->expected, 'current_version' => $e->actual], 409));
        $exceptions->render(fn (InvalidTransition $e) => response()->json(['error' => 'invalid_transition', 'message' => $e->getMessage()], 409));
        $exceptions->render(fn (PolicyViolation $e) => response()->json(['error' => 'policy_blocked', 'message' => $e->getMessage(), 'reasons' => $e->reasons], 422));
    })->create();
