<?php

namespace App\Domain\Triage;

enum CaseState: string
{
    case Received = 'received';
    case Sanitizing = 'sanitizing';
    case Processing = 'processing';
    case Classified = 'classified';
    case Extracting = 'extracting';
    case Validating = 'validating';
    case AwaitingPolicy = 'awaiting_policy';
    case AwaitingReview = 'awaiting_review';
    case NeedsReview = 'needs_review';
    case Blocked = 'blocked';
    case Approved = 'approved';
    case Corrected = 'corrected';
    case Rejected = 'rejected';
    case QueuedAction = 'queued_action';
    case Executing = 'executing';
    case Executed = 'executed';
    case ExecutionFailed = 'execution_failed';
    case PendingReconciliation = 'pending_reconciliation';
    case FailedRetryable = 'failed_retryable';
    case FailedTerminal = 'failed_terminal';

    /** @return list<self> */
    public function allowed(): array
    {
        return match ($this) {
            self::Received => [self::Sanitizing],
            self::Sanitizing => [self::Processing, self::Blocked, self::FailedTerminal],
            self::Processing => [self::Classified, self::FailedRetryable, self::FailedTerminal, self::NeedsReview, self::Blocked],
            self::FailedRetryable => [self::Processing, self::NeedsReview],
            self::Classified => [self::Extracting, self::AwaitingPolicy, self::Blocked],
            self::Extracting => [self::Validating, self::NeedsReview, self::FailedRetryable],
            self::Validating => [self::AwaitingPolicy, self::NeedsReview],
            self::NeedsReview => [self::AwaitingPolicy, self::Corrected, self::Rejected, self::Processing],
            self::AwaitingPolicy => [self::AwaitingReview, self::Approved, self::Blocked],
            self::AwaitingReview => [self::Approved, self::Corrected, self::Rejected, self::Processing],
            self::Corrected => [self::AwaitingPolicy],
            self::Blocked => [self::Rejected, self::Processing],
            self::Approved => [self::QueuedAction],
            self::QueuedAction => [self::Executing],
            self::Executing => [self::Executed, self::ExecutionFailed, self::PendingReconciliation],
            self::PendingReconciliation => [self::Executed, self::QueuedAction],
            self::ExecutionFailed => [self::QueuedAction],
            self::Executed, self::Rejected, self::FailedTerminal => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowed(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowed() === [];
    }

    /** States where a human may still decide. */
    public function isReviewable(): bool
    {
        return in_array($this, [self::AwaitingReview, self::NeedsReview], true);
    }
}
