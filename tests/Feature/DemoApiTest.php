<?php

namespace Tests\Feature;

use App\Models\ActionRequest;
use App\Models\DemoSession;
use App\Models\TriageCase;
use App\Models\Workspace;
use Tests\TestCase;

class DemoApiTest extends TestCase
{
    private function launch(): string
    {
        $r = $this->postJson('/api/demo/sessions')->assertCreated();
        $r->assertJsonStructure(['session_id', 'expires_at', 'provider', 'faults']);

        return $r->json('session_id');
    }

    public function test_launch_preloads_the_synthetic_inbox_and_processes_it(): void
    {
        $id = $this->launch();
        $inbox = $this->getJson("/api/demo/sessions/{$id}/inbox")->assertOk();
        $this->assertCount(8, $inbox->json('cases'));
        $categories = collect($inbox->json('cases'))->pluck('category')->unique()->sort()->values()->all();
        $this->assertContains('invoice', $categories);
        $this->assertContains('customer_complaint', $categories);
        $this->assertContains('spam_or_phishing', $categories);
        $counters = $inbox->json('counters');
        $this->assertSame(8, $counters['total']);
        $this->assertGreaterThan(0, $counters['ready']);
        $this->assertGreaterThan(0, $counters['needs_review']);
        $this->assertGreaterThan(0, $counters['executed']);
        $this->assertSame(0, $counters['in_progress']);
    }

    public function test_full_review_flow_over_http(): void
    {
        $id = $this->launch();
        $cases = collect($this->getJson("/api/demo/sessions/{$id}/inbox")->json('cases'));
        $complaint = $cases->firstWhere('category', 'customer_complaint');
        $detail = $this->getJson("/api/triage-cases/{$complaint['id']}")->assertOk();
        $this->assertTrue($detail->json('can.approve'));
        $this->assertFalse($detail->json('draft.sent'));
        $this->assertNotEmpty($detail->json('draft.claims'));

        // stale version -> 409
        $this->postJson("/api/triage-cases/{$complaint['id']}/review/approve", ['expected_version' => 1])->assertStatus(409)->assertJsonPath('error', 'version_conflict');
        $ok = $this->postJson("/api/triage-cases/{$complaint['id']}/review/approve", ['expected_version' => $detail->json('version')])->assertOk();
        $this->assertSame('executed', $ok->json('state'));
        $this->assertSame('SIMULATED', $ok->json('actions.0.runs.0.mode'));

        $trace = $this->getJson("/api/triage-cases/{$complaint['id']}/trace")->assertOk();
        $this->assertNotEmpty($trace->json('stages'));
        $this->assertStringStartsWith('corr_', $trace->json('correlation_id'));
    }

    public function test_correct_and_reject_over_http(): void
    {
        $id = $this->launch();
        $cases = collect($this->getJson("/api/demo/sessions/{$id}/inbox")->json('cases'));
        $photo = $cases->first(fn ($c) => $c['category'] === 'invoice' && in_array('invoice_number', []) === false && $c['error_count'] === 0 && $c['issue_count'] > 0);
        $detail = $this->getJson("/api/triage-cases/{$photo['id']}")->json();
        $r = $this->postJson("/api/triage-cases/{$photo['id']}/review/correct", ['expected_version' => $detail['version'], 'fields' => ['invoice_number' => 'INV-40881'], 'reason' => 'OCR 8/B'])->assertOk();
        $this->assertSame('INV-40881', collect($r->json('case.extraction.fields'))->firstWhere('name', 'invoice_number')['effective']);
        $this->assertSame('INV-4OBBl', collect($r->json('case.extraction.fields'))->firstWhere('name', 'invoice_number')['predicted']);

        $mismatch = $cases->first(fn ($c) => $c['error_count'] > 0);
        $d = $this->getJson("/api/triage-cases/{$mismatch['id']}")->json();
        $this->postJson("/api/triage-cases/{$mismatch['id']}/review/reject", ['expected_version' => $d['version'], 'reason_code' => 'wrong_extraction'])->assertOk()->assertJsonPath('state', 'rejected');
        $this->postJson("/api/triage-cases/{$mismatch['id']}/review/reject", ['expected_version' => $d['version'], 'reason_code' => 'nonsense'])->assertStatus(422);
    }

    public function test_reject_requires_a_valid_reason(): void
    {
        $id = $this->launch();
        $c = collect($this->getJson("/api/demo/sessions/{$id}/inbox")->json('cases'))->firstWhere('category', 'customer_complaint');
        $v = $this->getJson("/api/triage-cases/{$c['id']}")->json('version');
        $this->postJson("/api/triage-cases/{$c['id']}/review/reject", ['expected_version' => $v, 'reason_code' => 'because'])->assertStatus(422);
    }

    public function test_scenarios_produce_the_documented_outcomes(): void
    {
        $this->launch();
        $this->postJson('/api/demo/scenarios/prompt_injection')->assertCreated();
        $this->assertSame('blocked', TriageCase::latest()->first()->state);

        $dup = $this->postJson('/api/demo/scenarios/duplicate_delivery')->assertCreated();
        $this->assertSame([false, true, true], array_column($dup->json('deliveries'), 'duplicate'));

        $conc = $this->postJson('/api/demo/scenarios/concurrent_reviewers')->assertCreated();
        $this->assertSame('approved', $conc->json('outcomes.reviewer-a'));
        $this->assertStringStartsWith('version_conflict', $conc->json('outcomes.reviewer-b'));
        $this->assertSame(1, ActionRequest::where('triage_case_id', $conc->json('case_id'))->count());

        $this->postJson('/api/demo/scenarios/action_timeout')->assertCreated();
        $this->assertSame('pending_reconciliation', TriageCase::latest()->first()->state);
        $r = $this->postJson('/api/triage-cases/'.TriageCase::latest()->first()->id.'/reconcile')->assertOk();
        $this->assertSame('reconciled_executed', $r->json('reconciliation'));

        $this->postJson('/api/demo/scenarios/llm_timeout')->assertCreated();
        $this->assertSame('needs_review', TriageCase::latest()->first()->state);
        $this->postJson('/api/demo/scenarios/reset')->assertOk()->assertJsonPath('faults', []);
        $this->postJson('/api/demo/scenarios/not_a_scenario')->assertNotFound();
    }

    public function test_attachment_is_served_inline_with_safe_headers_and_quarantined_files_are_refused(): void
    {
        $this->launch();
        $c = TriageCase::whereHas('message', fn ($q) => $q->where('fixture_key', 'showcase-invoice-pdf'))->first();
        $a = $c->message->attachments()->first();
        $r = $this->get("/api/triage-cases/{$c->id}/attachments/{$a->id}")->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $r->headers->get('Content-Security-Policy'));

        $this->postJson('/api/demo/scenarios/malware')->assertCreated();
        $bad = TriageCase::latest()->first();
        $this->get("/api/triage-cases/{$bad->id}/attachments/".$bad->message->attachments()->first()->id)->assertForbidden();
    }

    public function test_security_headers_and_noindex(): void
    {
        $r = $this->get('/api/health')->assertOk();
        $r->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('frame-ancestors', $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("object-src 'none'", $r->headers->get('Content-Security-Policy'));
    }

    public function test_capabilities_and_evaluation_endpoints(): void
    {
        $this->getJson('/api/capabilities')->assertOk()->assertJsonPath('matrix.heuristic.simulated', true)->assertJsonPath('matrix.openai.image_input', true);
        $this->getJson('/api/evaluation')->assertOk()->assertJsonStructure(['runs', 'note']);
        $this->getJson('/api/demo/scenarios')->assertOk()->assertJsonCount(16, 'scenarios');
    }

    public function test_delete_now_disables_immediately_and_purges_asynchronously(): void
    {
        $id = $this->launch();
        $ws = DemoSession::find($id)->workspace_id;
        $this->deleteJson('/api/demo/sessions/current')->assertOk()->assertJsonPath('status', 'disabled')->assertJsonPath('purge', 'queued');
        $this->getJson("/api/demo/sessions/{$id}/inbox")->assertUnauthorized();
        $this->assertNull(Workspace::find($ws), 'sync queue runs the purge job (delayed jobs run immediately in the sync driver)');
    }
}
