<?php

namespace Tests\Security;

use App\Application\Demo\DemoSessionService;
use App\Models\ActionRequest;
use App\Models\Attachment;
use App\Models\FieldEvidence;
use App\Models\TriageCase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $svc = app(DemoSessionService::class);
        $this->a = $svc->launch('10.0.0.1', preload: false);
        $this->b = $svc->launch('10.0.0.2', preload: false);
    }

    private function asA()
    {
        return $this->withSession(['demo_session_id' => $this->a['session']->id]);
    }

    private function bCase(string $fixture = 'showcase-invoice-pdf'): TriageCase
    {
        return $this->ingest($this->b['workspace'], $fixture);
    }

    public function test_cannot_read_another_workspaces_case_trace_attachment_or_evidence(): void
    {
        $case = $this->bCase();
        $attachment = Attachment::where('message_id', $case->message_id)->first();
        $evidence = FieldEvidence::where('triage_case_id', $case->id)->first();

        foreach ([
            "/api/triage-cases/{$case->id}", "/api/triage-cases/{$case->id}/trace",
            "/api/triage-cases/{$case->id}/attachments/{$attachment->id}", "/api/triage-cases/{$case->id}/evidence/{$evidence->id}",
        ] as $url) {
            $this->asA()->getJson($url)->assertNotFound();
        }
    }

    public function test_cannot_act_on_another_workspaces_case(): void
    {
        $case = $this->bCase('showcase-complaint');
        foreach (['review/approve' => ['expected_version' => $case->version], 'review/reject' => ['expected_version' => $case->version, 'reason_code' => 'duplicate'], 'review/correct' => ['expected_version' => $case->version, 'category' => 'general_question'], 'reprocess' => [], 'reconcile' => []] as $path => $body) {
            $this->asA()->postJson("/api/triage-cases/{$case->id}/{$path}", $body)->assertNotFound();
        }
        $this->assertSame('awaiting_review', $case->refresh()->state);
        $this->assertSame(0, ActionRequest::count());
    }

    public function test_the_attachment_of_my_workspace_cannot_be_fetched_through_another_case_id(): void
    {
        $mine = $this->ingest($this->a['workspace'], 'showcase-invoice-pdf');
        $theirs = $this->bCase();
        $theirAttachment = Attachment::where('message_id', $theirs->message_id)->first();
        $this->asA()->getJson("/api/triage-cases/{$mine->id}/attachments/{$theirAttachment->id}")->assertNotFound();
    }

    public function test_session_scoped_endpoints_reject_another_sessions_id(): void
    {
        $this->asA()->getJson("/api/demo/sessions/{$this->b['session']->id}/inbox")->assertNotFound();
        $this->asA()->getJson("/api/demo/sessions/{$this->b['session']->id}/events")->assertNotFound();
        $this->asA()->postJson("/api/demo/sessions/{$this->b['session']->id}/fixtures/showcase-rfq/inject")->assertNotFound();
        $this->assertSame(0, TriageCase::where('workspace_id', $this->b['workspace']->id)->count());
    }

    public function test_inbox_only_lists_my_cases(): void
    {
        $mine = $this->ingest($this->a['workspace'], 'showcase-rfq');
        $this->bCase('showcase-complaint');
        $ids = collect($this->asA()->getJson("/api/demo/sessions/{$this->a['session']->id}/inbox")->assertOk()->json('cases'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_client_supplied_workspace_id_is_ignored(): void
    {
        $theirs = $this->bCase('showcase-complaint');
        $this->asA()->postJson("/api/triage-cases/{$theirs->id}/review/approve", ['expected_version' => $theirs->version, 'workspace_id' => $this->b['workspace']->id])->assertNotFound();
        $this->asA()->postJson("/api/demo/sessions/{$this->a['session']->id}/fixtures/showcase-rfq/inject", ['workspace_id' => $this->b['workspace']->id])->assertCreated();
        $this->assertSame(0, TriageCase::where('workspace_id', $this->b['workspace']->id)->count() - 1, 'only the case ingested for B by the test exists there');
    }

    public function test_requests_without_a_session_are_rejected(): void
    {
        $case = $this->bCase();
        $this->getJson("/api/triage-cases/{$case->id}")->assertUnauthorized();
        $this->getJson("/api/demo/sessions/{$this->b['session']->id}/inbox")->assertUnauthorized();
    }

    public function test_expired_or_revoked_sessions_lose_access(): void
    {
        $case = $this->ingest($this->a['workspace'], 'showcase-rfq');
        $this->asA()->getJson("/api/triage-cases/{$case->id}")->assertOk();
        $this->a['session']->update(['expires_at' => now()->subMinute()]);
        $this->asA()->getJson("/api/triage-cases/{$case->id}")->assertUnauthorized();
        $this->b['session']->update(['revoked_at' => now()]);
        $this->withSession(['demo_session_id' => $this->b['session']->id])->getJson('/api/demo/sessions/current')->assertUnauthorized();
    }

    public function test_cache_inputs_include_tenant_by_construction(): void
    {
        // classification/extraction runs are keyed by case (which belongs to one workspace) + input hash + versions
        $c = $this->ingest($this->a['workspace'], 'showcase-rfq');
        $d = $this->ingest($this->b['workspace'], 'showcase-rfq');
        $this->assertNotSame($c->id, $d->id);
        $this->assertSame(1, $c->classificationRuns()->count());
        $this->assertSame(1, $d->classificationRuns()->count());
        $this->assertSame($this->a['workspace']->id, $c->classificationRuns()->first()->workspace_id);
    }
}
