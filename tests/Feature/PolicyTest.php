<?php

namespace Tests\Feature;

use App\Application\Policy\ActionPolicy;
use App\Domain\Triage\Category;
use App\Domain\Triage\Route;
use App\Models\PolicyVersion;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    private function ctx(array $over = []): array
    {
        return $over + [
            'category' => Category::SpamOrPhishing, 'overall' => 0.95, 'classification_margin' => 5, 'errors' => [], 'warnings' => [], 'missing_required' => [],
            'injection' => [], 'quarantined' => false, 'provider_fallback' => false, 'proposed_actions' => [], 'allowed_recipients' => ['a@b.test'],
            'planned_action' => ['type' => 'move_to_demo_folder', 'params' => ['folder' => 'spam']], 'has_schema' => false, 'rejected_attachments' => false,
        ];
    }

    private function route(array $over = []): array
    {
        $o = app(ActionPolicy::class)->evaluate($this->ctx($over));

        return [$o->route, $o->codes()];
    }

    public function test_low_risk_category_above_threshold_is_auto_eligible(): void
    {
        $this->assertSame([Route::AutoEligible, ['AUTO_POLICY_PASSED']], $this->route());
    }

    public function test_high_impact_categories_never_auto_process_whatever_the_confidence(): void
    {
        foreach ([Category::Invoice, Category::CustomerComplaint, Category::OrderOrPurchaseRequest] as $c) {
            [$route, $codes] = $this->route(['category' => $c, 'overall' => 0.999, 'has_schema' => false]);
            $this->assertSame(Route::ReviewRequired, $route, $c->value);
            $this->assertContains('HIGH_IMPACT_ACTION', $codes);
        }
    }

    public function test_below_threshold_goes_to_review_and_below_minimum_is_blocked(): void
    {
        $this->assertSame(Route::ReviewRequired, $this->route(['overall' => 0.70])[0]);
        $this->assertSame([Route::Blocked, ['SIGNAL_BELOW_MINIMUM']], $this->route(['overall' => 0.10]));
    }

    public function test_validation_issues_force_review_even_with_a_perfect_signal(): void
    {
        $this->assertSame(Route::ReviewRequired, $this->route(['overall' => 1.0, 'warnings' => ['AMBIGUOUS_DATE']])[0]);
        $this->assertSame(Route::ReviewRequired, $this->route(['overall' => 1.0, 'errors' => ['TOTAL_MISMATCH']])[0]);
        $this->assertSame(Route::ReviewRequired, $this->route(['overall' => 1.0, 'missing_required' => ['order_number']])[0]);
    }

    public function test_security_findings_block(): void
    {
        $this->assertSame(Route::Blocked, $this->route(['injection' => [['code' => 'IGNORE_INSTRUCTIONS']]])[0]);
        $this->assertSame(Route::Blocked, $this->route(['quarantined' => true])[0]);
    }

    public function test_action_allow_list_and_recipient_checks(): void
    {
        [$route, $codes] = $this->route(['proposed_actions' => [['type' => 'delete_everything', 'params' => []]]]);
        $this->assertSame([Route::Blocked, ['ACTION_NOT_ALLOWED']], [$route, $codes]);
        [$route, $codes] = $this->route(['proposed_actions' => [['type' => 'move_to_demo_folder', 'params' => []], ['type' => 'create_demo_task', 'params' => []]]]);
        $this->assertContains('ACTION_NOT_ALLOWED_FOR_CATEGORY', $codes);
        [$route, $codes] = $this->route(['category' => Category::CustomerComplaint, 'proposed_actions' => [['type' => 'send_reply', 'params' => ['to' => 'x@evil.test'], 'recipient' => 'x@evil.test']]]);
        $this->assertSame(Route::Blocked, $route);
        $this->assertContains('RECIPIENT_NOT_ALLOWED', $codes);
        $this->assertSame(Route::ReviewRequired, $this->route(['category' => Category::CustomerComplaint, 'proposed_actions' => [['type' => 'send_reply', 'params' => [], 'recipient' => 'a@b.test']]])[0], 'the server-known sender is an allowed recipient');
    }

    public function test_provider_fallback_and_rejected_attachments_need_a_human(): void
    {
        $this->assertSame(Route::ReviewRequired, $this->route(['provider_fallback' => true])[0]);
        $this->assertSame(Route::ReviewRequired, $this->route(['rejected_attachments' => true])[0]);
    }

    public function test_uncertain_category_is_always_review(): void
    {
        [$route, $codes] = $this->route(['category' => Category::OtherOrUncertain, 'overall' => 0.4]);
        $this->assertSame(Route::ReviewRequired, $route);
        $this->assertContains('UNCERTAIN_CATEGORY', $codes);
    }

    public function test_policy_simulation_changes_coverage_not_safety(): void
    {
        // v2 candidate lowers auto_min: a 0.80 signal flips from review to auto, but injection stays blocked
        $policy = app(ActionPolicy::class);
        $v2 = PolicyVersion::where('version', 'policy.v2-candidate')->first();
        $this->assertSame(Route::ReviewRequired, $policy->evaluate($this->ctx(['overall' => 0.80]))->route);
        $this->assertSame(Route::AutoEligible, $policy->evaluate($this->ctx(['overall' => 0.80]), $v2)->route);
        $this->assertSame(Route::Blocked, $policy->evaluate($this->ctx(['overall' => 0.99, 'injection' => [['code' => 'X']]]), $v2)->route);
    }
}
