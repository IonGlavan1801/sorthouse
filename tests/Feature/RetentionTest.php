<?php

namespace Tests\Feature;

use App\Application\Demo\DemoSessionService;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ExtractedField;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    public function test_purge_removes_rows_and_stored_files(): void
    {
        $svc = app(DemoSessionService::class);
        $a = $svc->launch('1.1.1.1', preload: false);
        $b = $svc->launch('2.2.2.2', preload: false);
        $caseA = $this->ingest($a['workspace'], 'showcase-invoice-pdf');
        $caseB = $this->ingest($b['workspace'], 'showcase-invoice-pdf');
        $fileA = Attachment::where('message_id', $caseA->message_id)->first()->storage_key;
        $fileB = Attachment::where('message_id', $caseB->message_id)->first()->storage_key;
        Storage::disk('local')->assertExists($fileA);

        $svc->purge($a['workspace']);
        Storage::disk('local')->assertMissing($fileA);
        Storage::disk('local')->assertExists($fileB);
        $this->assertSame(0, Message::where('workspace_id', $a['workspace']->id)->count());
        $this->assertSame(0, AuditEvent::where('workspace_id', $a['workspace']->id)->count());
        $this->assertSame(0, ExtractedField::where('triage_case_id', $caseA->id)->count());
        $this->assertSame(1, Message::where('workspace_id', $b['workspace']->id)->count(), 'other tenants are untouched');
    }

    public function test_expired_sessions_are_purged_by_the_scheduled_command(): void
    {
        $svc = app(DemoSessionService::class);
        $old = $svc->launch('1.1.1.1', preload: false);
        $fresh = $svc->launch('2.2.2.2', preload: false);
        $old['session']->update(['expires_at' => now()->subMinute()]);
        $this->artisan('demo:purge')->assertSuccessful();
        $this->assertNull(Workspace::find($old['workspace']->id));
        $this->assertNotNull(Workspace::find($fresh['workspace']->id));
    }

    public function test_sessions_have_a_ttl(): void
    {
        $s = app(DemoSessionService::class)->launch('1.1.1.1', preload: false)['session'];
        $this->assertEqualsWithDelta(config('sorthouse.limits.session_ttl_minutes'), now()->diffInMinutes($s->expires_at), 1);
    }

    public function test_session_case_limit_is_enforced(): void
    {
        config(['sorthouse.limits.max_cases_per_session' => 2]);
        $ws = $this->workspace();
        $svc = app(DemoSessionService::class);
        $svc->inject($ws, 'showcase-rfq', 'e1');
        $svc->inject($ws, 'showcase-general', 'e2');
        $this->expectExceptionMessage('case limit');
        $svc->inject($ws, 'showcase-complaint', 'e3');
    }
}
