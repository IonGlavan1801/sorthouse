<?php

namespace Tests\Feature;

use App\Application\Demo\DemoSessionService;
use App\Models\Attachment;
use App\Models\IngestionRun;
use App\Models\Message;
use App\Models\TriageCase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    public function test_duplicate_delivery_x100_creates_one_logical_case(): void
    {
        $ws = $this->workspace();
        $demo = app(DemoSessionService::class);
        $first = $demo->inject($ws, 'showcase-rfq', 'ext-1');
        for ($i = 0; $i < 99; $i++) {
            // half re-delivered with the same id, half with a fresh id but identical content
            $r = $demo->inject($ws, 'showcase-rfq', $i % 2 ? 'ext-1' : 'ext-redelivery-'.$i);
            $this->assertTrue($r->duplicate);
            $this->assertSame($first->case->id, $r->case->id);
        }
        $this->assertSame(1, Message::where('workspace_id', $ws->id)->count());
        $this->assertSame(1, TriageCase::where('workspace_id', $ws->id)->count());
        $this->assertSame(99, Message::first()->duplicate_count);
        $this->assertSame(99, IngestionRun::where('outcome', 'duplicate')->count());
        $this->assertSame(1, $first->case->refresh()->classificationRuns()->count(), 'the pipeline must not run again for duplicates');
    }

    public function test_attachment_with_mime_extension_mismatch_is_quarantined_and_never_parsed(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'fail-mime-mismatch');
        $a = Attachment::where('message_id', $case->message_id)->first();
        $this->assertSame('quarantined', $a->scan_status);
        $this->assertSame('MIME_EXTENSION_MISMATCH', $a->scan_reason);
        $this->assertStringStartsWith('quarantine/', $a->storage_key);
        $this->assertSame('blocked', $case->state);
        $this->assertSame(0, $case->classificationRuns()->count(), 'quarantined content must not reach a model');
        $this->assertNull($a->metadata['sources'] ?? null);
    }

    public function test_executable_pdf_malware_and_eicar_are_quarantined(): void
    {
        $ws = $this->workspace();
        foreach (['fail-malware-attachment' => 'MIME_NOT_ALLOWED', 'fail-eicar' => 'MALWARE_SIGNATURE', 'fail-pdf-javascript' => 'PDF_ACTIVE_CONTENT'] as $fx => $reason) {
            $case = $this->ingest($ws, $fx);
            $this->assertSame('blocked', $case->state, $fx);
            $this->assertSame($reason, Attachment::where('message_id', $case->message_id)->first()->scan_reason, $fx);
            $this->assertContains('ATTACHMENT_QUARANTINED', $this->codes($case));
        }
    }

    public function test_oversized_and_surplus_attachments_are_rejected_in_a_controlled_way(): void
    {
        $ws = $this->workspace();
        $big = $this->ingest($ws, 'fail-oversized');
        $a = Attachment::where('message_id', $big->message_id)->first();
        $this->assertSame('rejected', $a->scan_status);
        $this->assertSame('ATTACHMENT_TOO_LARGE', $a->scan_reason);
        $this->assertNull($a->storage_key);
        $this->assertSame('awaiting_review', $big->state);
        $this->assertContains('ATTACHMENT_REJECTED', $this->codes($big));

        $many = $this->ingest($ws, 'fail-too-many-attachments');
        $this->assertSame(2, Attachment::where('message_id', $many->message_id)->where('scan_status', 'rejected')->count());
    }

    public function test_message_is_persisted_before_ai_processing(): void
    {
        $ws = $this->workspace();
        config(['queue.default' => 'database']); // pipeline job is queued, not run
        $r = app(DemoSessionService::class)->inject($ws, 'showcase-complaint');
        $this->assertSame('received', $r->case->refresh()->state);
        $this->assertNotNull(Message::find($r->message->id)->body);
        $this->assertSame(0, $r->case->classificationRuns()->count());
    }

    public function test_stored_files_live_under_the_workspace_namespace(): void
    {
        $ws = $this->workspace();
        $case = $this->ingest($ws, 'showcase-invoice-pdf');
        $a = Attachment::where('message_id', $case->message_id)->first();
        $this->assertStringStartsWith("attachments/{$ws->id}/", $a->storage_key);
        Storage::disk('local')->assertExists($a->storage_key);
    }
}
