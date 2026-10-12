<?php

namespace Tests\Unit;

use App\Domain\Triage\CaseState;
use PHPUnit\Framework\TestCase;

class CaseStateTest extends TestCase
{
    public function test_no_arbitrary_jumps(): void
    {
        $this->assertFalse(CaseState::Received->canTransitionTo(CaseState::Executed));
        $this->assertFalse(CaseState::AwaitingReview->canTransitionTo(CaseState::QueuedAction));
        $this->assertFalse(CaseState::Rejected->canTransitionTo(CaseState::Approved));
        $this->assertFalse(CaseState::Executed->canTransitionTo(CaseState::QueuedAction));
        $this->assertTrue(CaseState::AwaitingReview->canTransitionTo(CaseState::Approved));
        $this->assertTrue(CaseState::PendingReconciliation->canTransitionTo(CaseState::Executed));
    }

    public function test_terminal_states_have_no_exits(): void
    {
        foreach ([CaseState::Executed, CaseState::Rejected, CaseState::FailedTerminal] as $s) {
            $this->assertTrue($s->isTerminal());
        }
    }

    public function test_every_state_is_reachable_from_received(): void
    {
        $seen = [CaseState::Received->value => true];
        $queue = [CaseState::Received];
        while ($queue) {
            foreach (array_shift($queue)->allowed() as $next) {
                if (! isset($seen[$next->value])) {
                    $seen[$next->value] = true;
                    $queue[] = $next;
                }
            }
        }
        $this->assertCount(count(CaseState::cases()), $seen);
    }
}
