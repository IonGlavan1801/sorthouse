<?php

namespace App\Application\Policy;

use App\Domain\Triage\ActionType;
use App\Domain\Triage\Category;
use App\Domain\Triage\Route;
use App\Models\AutomationPolicy;
use App\Models\PolicyDecision;
use App\Models\PolicyVersion;
use App\Models\TriageCase;

/**
 * The mandatory boundary between AI interpretation and any external effect. Pure rules over already-computed facts;
 * nothing in this class reads free text from the message.
 *
 * ctx keys: category(Category), overall(float), classification_signal(float), errors(list<string>), warnings(list<string>), missing_required(list<string>),
 * injection(list), quarantined(bool), provider_fallback(bool), proposed_actions(list<array{type,params,recipient?}>), allowed_recipients(list<string>),
 * planned_action(?array), has_schema(bool), classification_margin(float)
 */
final class ActionPolicy
{
    public function activeVersion(?string $override = null): PolicyVersion
    {
        $policy = AutomationPolicy::where('key', 'default')->firstOrFail();

        return PolicyVersion::findOrFail($override ?? $policy->active_version_id);
    }

    public function thresholds(PolicyVersion $v): array
    {
        return ['auto_min' => (float) $v->rules['auto_min'], 'block_min' => (float) $v->rules['block_min']];
    }

    public function evaluate(array $ctx, ?PolicyVersion $version = null): PolicyOutcome
    {
        $version ??= $this->activeVersion();
        $rules = $version->rules;
        /** @var Category $category */
        $category = $ctx['category'];
        $blocked = [];
        $review = [];
        $add = fn (array &$bucket, string $code, string $message) => $bucket[] = ['code' => $code, 'message' => $message];

        // --- hard blocks: security and policy
        if (! empty($ctx['injection'])) {
            $add($blocked, 'PROMPT_INJECTION_DETECTED', 'The content contains instructions aimed at the system ('.implode(', ', array_unique(array_column($ctx['injection'], 'code'))).'). It is treated as data; no action may run.');
        }
        if (! empty($ctx['quarantined'])) {
            $add($blocked, 'ATTACHMENT_QUARANTINED', 'An attachment failed the security gate and was never sent to a parser or model.');
        }
        foreach ($ctx['proposed_actions'] ?? [] as $p) {
            $type = ActionType::tryFrom($p['type']);
            if ($type === null) {
                $add($blocked, 'ACTION_NOT_ALLOWED', "Proposed action \"{$p['type']}\" is not on the application allow-list.");

                continue;
            }
            if (! in_array($type, $category->allowedActions(), true)) {
                $add($blocked, 'ACTION_NOT_ALLOWED_FOR_CATEGORY', "Action {$type->value} is not allowed for category {$category->value}.");
            }
            if (isset($p['recipient']) && ! in_array(strtolower($p['recipient']), array_map('strtolower', $ctx['allowed_recipients'] ?? []), true)) {
                $add($blocked, 'RECIPIENT_NOT_ALLOWED', 'The content asked for output to be sent to '.$p['recipient'].', which is not a server-approved recipient.');
            }
        }
        if (($ctx['overall'] ?? 0) < $rules['block_min'] && $category !== Category::OtherOrUncertain) {
            $add($blocked, 'SIGNAL_BELOW_MINIMUM', sprintf('Confidence signal %.2f is below the policy minimum %.2f.', $ctx['overall'], $rules['block_min']));
        }
        if ($blocked) {
            return new PolicyOutcome(Route::Blocked, $blocked, $version);
        }

        // --- review reasons
        $auto = $rules['auto_actions'][$category->value] ?? null;
        if ($category === Category::OtherOrUncertain) {
            $add($review, 'UNCERTAIN_CATEGORY', 'The message does not clearly fit a category; a person must choose.');
        }
        if ($auto === null && $category !== Category::OtherOrUncertain) {
            $add($review, 'HIGH_IMPACT_ACTION', ucfirst(str_replace('_', ' ', $category->value)).' actions are approval-only in this demo.');
        }
        if (! empty($ctx['rejected_attachments'])) {
            $add($review, 'ATTACHMENT_REJECTED', 'An attachment was rejected (too large or over the per-message limit) and was not processed.');
        }
        if ($ctx['provider_fallback'] ?? false) {
            $add($review, 'PROVIDER_FALLBACK', 'The configured provider was unavailable or over budget; a fallback produced this result.');
        }
        foreach ($ctx['missing_required'] ?? [] as $f) {
            $add($review, 'MISSING_REQUIRED_FIELD', "Required field {$f} is missing.");
        }
        foreach ($ctx['errors'] ?? [] as $code) {
            $add($review, $code, 'Validation error: '.$code);
        }
        foreach ($ctx['warnings'] ?? [] as $code) {
            $add($review, $code, 'Validation warning: '.$code);
        }
        if (! empty($ctx['has_schema']) && empty($ctx['planned_action'])) {
            $add($review, 'ACTION_PARAMS_INVALID', 'The prepared action does not satisfy its own parameter schema.');
        }
        if (($ctx['overall'] ?? 0) < $rules['auto_min'] && $category !== Category::OtherOrUncertain) {
            $add($review, ($ctx['classification_margin'] ?? 9) < 1.5 ? 'AMBIGUOUS_CATEGORY' : 'LOW_CONFIDENCE', sprintf('Confidence signal %.2f is below the automation threshold %.2f.', $ctx['overall'], $rules['auto_min']));
        }

        if ($review) {
            return new PolicyOutcome(Route::ReviewRequired, $review, $version);
        }

        return new PolicyOutcome(Route::AutoEligible, [['code' => 'AUTO_POLICY_PASSED', 'message' => 'Signals and checks pass; the action is reversible and simulated.']], $version);
    }

    public function record(TriageCase $case, PolicyOutcome $outcome, array $context = []): PolicyDecision
    {
        return PolicyDecision::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'policy_version_id' => $outcome->version->id,
            'case_version' => $case->version, 'decision' => $outcome->route->decision(), 'reasons' => $outcome->reasons, 'context' => $context ?: null,
        ]);
    }
}
