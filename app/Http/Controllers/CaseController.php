<?php

namespace App\Http\Controllers;

use App\Application\ActionExecution\OutboxProcessor;
use App\Application\Review\ReviewService;
use App\Http\Presenters\CasePresenter;
use App\Jobs\RunTriagePipeline;
use App\Models\Attachment;
use App\Models\FieldEvidence;
use App\Models\OutboxMessage;
use App\Models\TriageCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseController extends Controller
{
    public function __construct(private CasePresenter $presenter, private ReviewService $review) {}

    /** Every lookup is scoped to the session's workspace; another tenant's id is indistinguishable from a missing one (404). */
    private function find(Request $request, string $id): TriageCase
    {
        return TriageCase::where('workspace_id', $request->attributes->get('workspace')->id)->with('message')->findOrFail($id);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->presenter->detail($this->find($request, $id)));
    }

    public function trace(Request $request, string $id): JsonResponse
    {
        return response()->json($this->presenter->trace($this->find($request, $id)));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'note' => 'nullable|string|max:500']);
        $case = $this->review->approve($this->find($request, $id), $data['expected_version'], $request->attributes->get('actor'), $data['note'] ?? null);

        return response()->json($this->presenter->detail($case->fresh('message')));
    }

    public function correct(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => 'required|integer|min:1', 'category' => 'nullable|string|in:'.implode(',', array_map(fn ($c) => $c->value, \App\Domain\Triage\Category::cases())),
            'fields' => 'nullable|array|max:20', 'fields.*' => 'nullable|string|max:2000', 'draft_body' => 'nullable|string|max:5000',
            'reason' => 'nullable|string|max:500', 'error_category' => 'nullable|string|in:'.implode(',', array_map(fn ($c) => $c->value, \App\Domain\Triage\ErrorCategory::cases())),
        ]);
        $result = $this->review->correct($this->find($request, $id), $data['expected_version'], $request->attributes->get('actor'), $data);

        return response()->json(['change' => ['category_changed' => $result['category_changed'], 'issues_before' => $result['issues_before'], 'issues_after' => $result['issues_after']], 'case' => $this->presenter->detail($result['case']->fresh('message'))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'reason_code' => 'required|string', 'note' => 'nullable|string|max:500']);
        $case = $this->review->reject($this->find($request, $id), $data['expected_version'], $request->attributes->get('actor'), $data['reason_code'], $data['note'] ?? null);

        return response()->json($this->presenter->detail($case->fresh('message')));
    }

    public function reprocess(Request $request, string $id): JsonResponse
    {
        $case = $this->review->reprocess($this->find($request, $id), $request->attributes->get('actor'));

        return response()->json($this->presenter->detail($case->fresh('message')), 202);
    }

    public function reconcile(Request $request, string $id, OutboxProcessor $processor): JsonResponse
    {
        $case = $this->find($request, $id);
        $outbox = OutboxMessage::whereIn('action_request_id', $case->actionRequests()->pluck('id'))->where('status', 'pending_reconciliation')->first();
        abort_unless($outbox, 409, 'Nothing to reconcile');
        $result = $processor->reconcile($outbox->id);
        if ($result === 'reconciled_missing') {
            \App\Jobs\ExecuteOutboxMessage::dispatch($outbox->id)->afterCommit();
        }

        return response()->json(['reconciliation' => $result] + ['case' => $this->presenter->detail($case->fresh('message'))]);
    }

    public function attachment(Request $request, string $id, string $attachment): StreamedResponse
    {
        $case = $this->find($request, $id);
        $file = Attachment::where('workspace_id', $case->workspace_id)->where('message_id', $case->message_id)->findOrFail($attachment);
        abort_if($file->scan_status !== 'clean' || ! $file->storage_key, 403, 'Attachment is quarantined or unavailable');

        return Storage::disk('local')->response($file->storage_key, $file->filename, [
            'Content-Type' => $file->detected_mime, 'Content-Disposition' => 'inline; filename="'.addslashes($file->filename).'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'", 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function evidence(Request $request, string $id, string $evidence): JsonResponse
    {
        $case = $this->find($request, $id);
        $ev = FieldEvidence::where('workspace_id', $case->workspace_id)->where('triage_case_id', $case->id)->with('field')->findOrFail($evidence);
        $attachmentId = preg_match('/^att:([0-9a-f-]{36}):/', $ev->source_id, $m) ? $m[1] : null;

        return response()->json([
            'id' => $ev->id, 'field' => $ev->field->field_name, 'source_id' => $ev->source_id, 'locator' => $ev->locator_type, 'page' => $ev->page, 'bbox' => $ev->bbox,
            'span' => [$ev->span_start, $ev->span_end], 'snippet' => $ev->snippet, 'provenance' => $ev->provenance, 'ocr_confidence' => $ev->ocr_confidence,
            'attachment_id' => $attachmentId,
            'limitation' => $ev->locator_type === 'bbox' ? null : ($attachmentId ? 'No stable bounding box for this source: evidence is shown at page/snippet level.' : null),
        ]);
    }
}
