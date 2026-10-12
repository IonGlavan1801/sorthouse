<?php

namespace App\Application\Ingestion;

use App\Application\Pipeline\AuditLogger;
use App\Domain\Triage\CaseState;
use App\Jobs\RunTriagePipeline;
use App\Models\Attachment;
use App\Models\IngestionRun;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\MessageBody;
use App\Models\TriageCase;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class IngestMessage
{
    public function __construct(private HtmlSanitizer $sanitizer, private AttachmentInspector $inspector, private AuditLogger $audit) {}

    public function handle(Workspace $workspace, Mailbox $mailbox, IncomingMessage $in, bool $dispatch = true): IngestResult
    {
        $result = DB::transaction(function () use ($workspace, $mailbox, $in) {
            // Serialise ingestion per workspace so parallel duplicate deliveries collapse into one logical case.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ingest:'.$workspace->id]);

            $hash = $in->contentHash();
            $existing = Message::where('workspace_id', $workspace->id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('provider', $in->provider)->where('external_id', $in->externalId))->orWhere('content_hash', $hash))
                ->first();

            if ($existing) {
                $existing->increment('duplicate_count');
                $case = $existing->triageCase()->firstOrFail();
                IngestionRun::create([
                    'workspace_id' => $workspace->id, 'message_id' => $existing->id, 'correlation_id' => $case->correlation_id,
                    'outcome' => 'duplicate', 'dedupe_key' => $in->provider.':'.$in->externalId,
                    'detail' => ['matched_by' => $existing->external_id === $in->externalId ? 'external_id' : 'content_hash', 'delivery_external_id' => $in->externalId],
                ]);
                $this->audit->record($case, 'ingest.duplicate_suppressed', 'system:ingestion', detail: ['duplicate_count' => $existing->duplicate_count, 'delivery_external_id' => $in->externalId]);

                return new IngestResult($existing, $case, true);
            }

            if (TriageCase::where('workspace_id', $workspace->id)->count() >= config('sorthouse.limits.max_cases_per_session')) {
                throw new RuntimeException('Demo session case limit reached. Delete demo data or start a new session.');
            }

            $html = $in->htmlBody !== null ? $this->sanitizer->sanitize($in->htmlBody) : null;
            $message = Message::create([
                'workspace_id' => $workspace->id,
                'mailbox_id' => $mailbox->id,
                'provider' => $in->provider,
                'external_id' => $in->externalId,
                'thread_id' => $in->threadId,
                'sender_name' => $in->senderName,
                'sender_email' => strtolower($in->senderEmail),
                'recipients' => $in->recipients,
                'subject' => $in->subject,
                'received_at' => $in->receivedAt,
                'status' => 'persisted',
                'content_hash' => $hash,
                'fixture_key' => $in->fixtureKey,
                'sanitization' => $html['report'] ?? ['removed_elements' => 0],
            ]);
            MessageBody::create([
                'message_id' => $message->id,
                'text_plain' => $in->textBody !== '' ? $in->textBody : ($html['text'] ?? ''),
                'html_sanitized' => $html['html'] ?? null,
            ]);

            foreach (array_slice($in->attachments, 0, 12) as $i => $file) {
                $info = $this->inspector->inspect($file['filename'], $file['declared_mime'] ?? null, $file['bytes']);
                $over = $i >= config('sorthouse.limits.attachments_per_message');
                $id = (string) Str::uuid();
                $status = $over ? 'rejected' : $info['scan_status'];
                $key = null;
                if ($status !== 'rejected') {
                    $key = ($status === 'quarantined' ? 'quarantine/' : 'attachments/')."{$workspace->id}/{$message->id}/{$id}";
                    Storage::disk('local')->put($key, $file['bytes']);
                }
                Attachment::create([
                    'id' => $id, 'workspace_id' => $workspace->id, 'message_id' => $message->id,
                    'filename' => basename($file['filename']), 'declared_mime' => $file['declared_mime'] ?? null,
                    'detected_mime' => $info['detected_mime'], 'size_bytes' => $info['size'], 'sha256' => $info['sha256'],
                    'storage_key' => $key, 'scan_status' => $status,
                    'scan_reason' => $over ? 'TOO_MANY_ATTACHMENTS' : $info['scan_reason'],
                    'metadata' => ($file['metadata'] ?? []) + ['scan_mode' => $info['scan_mode']],
                ]);
            }

            $case = TriageCase::create([
                'workspace_id' => $workspace->id, 'message_id' => $message->id,
                'correlation_id' => 'corr_'.Str::lower(Str::random(16)),
                'state' => CaseState::Received->value, 'due_at' => now()->addMinutes(30),
            ]);
            IngestionRun::create([
                'workspace_id' => $workspace->id, 'message_id' => $message->id, 'correlation_id' => $case->correlation_id,
                'outcome' => 'created', 'dedupe_key' => $in->provider.':'.$in->externalId,
                'detail' => ['attachments' => $message->attachments()->count()],
            ]);
            $this->audit->record($case, 'ingest.created', 'system:ingestion', detail: ['external_id' => $in->externalId]);

            return new IngestResult($message, $case, false);
        });

        if ($dispatch && ! $result->duplicate) {
            RunTriagePipeline::dispatch($result->case->id, $workspace->id)->afterCommit();
        }

        return $result;
    }
}
