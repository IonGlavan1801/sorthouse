<?php

namespace Tests;

use App\Application\Demo\DemoSessionService;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\TriageCase;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sorthouse.resilience.sleep' => false]);
        $this->seed();
        Storage::fake('local');
    }

    protected function workspace(): Workspace
    {
        return app(DemoSessionService::class)->launch('127.0.0.1', preload: false)['workspace'];
    }

    protected function fault(Workspace $ws, string $key, int $times = 1): void
    {
        app(FaultInjector::class)->set($ws->id, $key, $times);
    }

    protected function codes(TriageCase $case): array
    {
        return collect($case->policyDecisions()->latest()->first()?->reasons ?? [])->pluck('code')->all();
    }

    /** Ingests a fixture; with the sync queue the whole pipeline runs inside this call. */
    protected function ingest(Workspace $ws, string $fixture, ?string $externalId = null): TriageCase
    {
        return app(DemoSessionService::class)->inject($ws, $fixture, $externalId)->case->refresh();
    }
}
