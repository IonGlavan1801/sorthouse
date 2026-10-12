<?php

namespace App\Application\Demo;

use App\Application\Ingestion\IngestMessage;
use App\Models\DemoSession;
use App\Models\Mailbox;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DemoSessionService
{
    public function __construct(private FixtureCatalog $fixtures, private IngestMessage $ingest) {}

    /** @return array{session:DemoSession,workspace:Workspace,token:string} */
    public function launch(?string $ip, bool $preload = true): array
    {
        $token = Str::random(48);
        [$workspace, $session] = DB::transaction(function () use ($token, $ip) {
            $workspace = Workspace::create(['name' => 'Demo workspace '.Str::upper(Str::random(4)), 'kind' => 'demo']);
            Mailbox::create(['workspace_id' => $workspace->id, 'provider' => 'demo', 'address' => 'inbox@acme-demo.example.test', 'allowed_recipients' => []]);
            $session = DemoSession::create([
                'workspace_id' => $workspace->id, 'token_hash' => hash('sha256', $token), 'ip_hash' => $ip ? hash('sha256', $ip.config('app.key')) : null,
                'expires_at' => now()->addMinutes(config('sorthouse.limits.session_ttl_minutes')), 'fault_flags' => [],
            ]);

            return [$workspace, $session];
        });

        if ($preload) {
            foreach ($this->fixtures->showcase() as $i => $key) {
                $this->inject($workspace, $key, minutesAgo: 20 + $i * 17);
            }
        }

        return ['session' => $session, 'workspace' => $workspace, 'token' => $token];
    }

    public function inject(Workspace $workspace, string $fixtureKey, ?string $externalId = null, ?int $minutesAgo = null, bool $dispatch = true)
    {
        $fixture = $this->fixtures->get($fixtureKey) ?? throw new \InvalidArgumentException("Unknown fixture {$fixtureKey}");
        $mailbox = Mailbox::where('workspace_id', $workspace->id)->firstOrFail();

        return $this->ingest->handle($workspace, $mailbox, $this->fixtures->toIncoming($fixture, $externalId, $minutesAgo), $dispatch);
    }

    public function resolve(string $sessionId): ?DemoSession
    {
        $s = DemoSession::find($sessionId);

        return ($s && ! $s->revoked_at && $s->expires_at->isFuture() && ! $s->purged_at) ? $s : null;
    }

    /** Disables the session immediately; the purge itself is asynchronous (PurgeWorkspace job). */
    public function revoke(DemoSession $session): void
    {
        $session->update(['revoked_at' => now(), 'expires_at' => now()]);
    }

    /** Hard delete of everything the workspace owns, including stored attachment files. */
    public function purge(Workspace $workspace): void
    {
        $dirs = ["attachments/{$workspace->id}", "quarantine/{$workspace->id}"];
        foreach ($dirs as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
        }
        $workspace->delete(); // cascades to messages, cases, runs, evidence, decisions, actions, usage and audit rows
    }

    /** @return int number of purged workspaces */
    public function purgeExpired(): int
    {
        $count = 0;
        foreach (DemoSession::where('expires_at', '<=', now())->whereNull('purged_at')->get() as $session) {
            $workspace = Workspace::find($session->workspace_id);
            if ($workspace) {
                $this->purge($workspace);
                $count++;
            }
        }

        return $count;
    }
}
