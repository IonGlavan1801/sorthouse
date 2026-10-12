<?php

namespace App\Application\Demo;

use App\Application\Pipeline\TriagePipeline;
use App\Application\Pipeline\VersionConflict;
use App\Application\Review\ReviewService;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\TriageCase;
use App\Models\Workspace;

/**
 * Failure Lab. Every scenario is a named, resettable configuration of the same fault adapter the tests use
 * (no ad-hoc failure code in controllers). Scenarios either arm faults for the next work or inject a fixture.
 */
final class ScenarioRunner
{
    public const SCENARIOS = [
        'low_confidence' => ['label' => 'Low-confidence case', 'description' => 'Valid schema, weak signals: must land in the human queue.'],
        'prompt_injection' => ['label' => 'Prompt injection', 'description' => 'Message tries to redirect output; the policy blocks the action.'],
        'prompt_injection_pdf' => ['label' => 'Injection hidden in a PDF', 'description' => 'White text inside an attachment tells the model to leak data.'],
        'duplicate_delivery' => ['label' => 'Duplicate delivery x3', 'description' => 'Same mail delivered three times: one logical case.'],
        'llm_timeout' => ['label' => 'LLM timeout (retries exhausted)', 'description' => 'Backoff, then the item falls back to human review.'],
        'llm_timeout_recover' => ['label' => 'LLM timeout (recovers)', 'description' => 'One timeout, retry succeeds.'],
        'ocr_timeout' => ['label' => 'OCR timeout', 'description' => 'Photo invoice: OCR fails after retries, item stays in the queue.'],
        'llm_invalid_json' => ['label' => 'LLM invalid JSON (repair retry)', 'description' => 'First output is not valid; a counted retry fixes it.'],
        'llm_invalid_json_exhausted' => ['label' => 'LLM invalid JSON (exhausted)', 'description' => 'Output stays invalid: never enters the workflow as valid.'],
        'rate_limit' => ['label' => 'Rate limit / 429', 'description' => 'Two 429s, backoff with jitter, then success.'],
        'worker_crash' => ['label' => 'Worker crash after persist', 'description' => 'Job dies after saving the extraction run; the retry reuses it.'],
        'action_timeout' => ['label' => 'Action timeout after send', 'description' => 'Outcome unknown: reconcile before any re-send.'],
        'malware' => ['label' => 'Malicious attachment', 'description' => '.pdf extension with executable content: quarantined, never parsed.'],
        'concurrent_reviewers' => ['label' => 'Concurrent reviewers', 'description' => 'Two reviewers approve the same version: one wins, one gets a conflict.'],
        'budget_exhausted' => ['label' => 'Budget exhausted', 'description' => 'Daily budget gone: AI work degrades to the simulated baseline.'],
        'vendor_lookup_timeout' => ['label' => 'Vendor lookup timeout', 'description' => 'Reference lookup times out: the vendor stays unvalidated.'],
    ];

    public function __construct(private DemoSessionService $demo, private FaultInjector $faults, private ReviewService $review, private TriagePipeline $pipeline) {}

    public function run(Workspace $workspace, string $scenario): array
    {
        abort_unless(isset(self::SCENARIOS[$scenario]), 404, 'Unknown scenario');
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $inject = fn (string $fixture, ?string $externalId = null) => $this->demo->inject($workspace, $fixture, $externalId ?? "{$fixture}-{$suffix}", 1);

        $result = match ($scenario) {
            'low_confidence' => ['case' => $inject('showcase-low-confidence')->case],
            'prompt_injection' => ['case' => $inject('adv-injection-email')->case],
            'prompt_injection_pdf' => ['case' => $inject('adv-injection-pdf')->case],
            'duplicate_delivery' => $this->duplicates($workspace, $suffix),
            'llm_timeout' => $this->armThenInject($workspace, 'llm_timeout', 9, 'showcase-complaint', $suffix),
            'llm_timeout_recover' => $this->armThenInject($workspace, 'llm_timeout', 1, 'showcase-complaint', $suffix),
            'ocr_timeout' => $this->armThenInject($workspace, 'ocr_timeout', 9, 'showcase-invoice-photo', $suffix),
            'llm_invalid_json' => $this->armThenInject($workspace, 'llm_invalid_json', 1, 'showcase-invoice-pdf', $suffix),
            'llm_invalid_json_exhausted' => $this->armThenInject($workspace, 'llm_invalid_json', 9, 'showcase-invoice-pdf', $suffix),
            'rate_limit' => $this->armThenInject($workspace, 'rate_limit', 2, 'showcase-complaint', $suffix),
            'worker_crash' => $this->armThenInject($workspace, 'worker_crash_after_persist', 1, 'showcase-invoice-pdf', $suffix),
            'action_timeout' => $this->armThenInject($workspace, 'action_timeout', 1, 'showcase-rfq', $suffix),
            'malware' => ['case' => $inject('fail-malware-attachment')->case],
            'concurrent_reviewers' => $this->concurrent($workspace, $suffix),
            'budget_exhausted' => $this->armThenInject($workspace, 'budget_exhausted', 1, 'showcase-complaint', $suffix),
            'vendor_lookup_timeout' => $this->armThenInject($workspace, 'validation_lookup_timeout', 1, 'showcase-invoice-pdf', $suffix),
        };

        return ['scenario' => $scenario] + $result;
    }

    public function reset(Workspace $workspace): void
    {
        $this->faults->clear($workspace->id);
    }

    private function armThenInject(Workspace $workspace, string $fault, int $times, string $fixture, string $suffix): array
    {
        $this->faults->set($workspace->id, $fault, $times);

        return ['case' => $this->demo->inject($workspace, $fixture, "{$fixture}-{$suffix}", 1)->case, 'armed' => [$fault => $times]];
    }

    private function duplicates(Workspace $workspace, string $suffix): array
    {
        $ext = "showcase-rfq-dup-{$suffix}";
        $first = $this->demo->inject($workspace, 'showcase-rfq', $ext, 1);
        $second = $this->demo->inject($workspace, 'showcase-rfq', $ext, 1);                   // same external id
        $third = $this->demo->inject($workspace, 'showcase-rfq', $ext.'-redelivery', 1);       // different id, identical content

        return ['case' => $first->case, 'deliveries' => [['external_id' => $ext, 'duplicate' => $first->duplicate], ['external_id' => $ext, 'duplicate' => $second->duplicate], ['external_id' => $ext.'-redelivery', 'duplicate' => $third->duplicate]]];
    }

    private function concurrent(Workspace $workspace, string $suffix): array
    {
        // processed inline so both reviewers see the same awaiting_review version
        $case = $this->demo->inject($workspace, 'showcase-complaint', "showcase-complaint-{$suffix}", 1, dispatch: false)->case;
        $this->pipeline->run($case);
        $case->refresh();
        $version = $case->version;
        $outcomes = [];
        foreach (['reviewer-a', 'reviewer-b'] as $reviewer) {
            try {
                $this->review->approve($case, $version, "demo:{$reviewer}");
                $outcomes[$reviewer] = 'approved';
            } catch (VersionConflict $e) {
                $outcomes[$reviewer] = 'version_conflict: '.$e->getMessage();
            } catch (\Throwable $e) {
                $outcomes[$reviewer] = 'rejected: '.$e->getMessage();
            }
        }

        return ['case' => TriageCase::find($case->id), 'outcomes' => $outcomes, 'expected_version' => $version];
    }
}
