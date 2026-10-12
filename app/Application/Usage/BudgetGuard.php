<?php

namespace App\Application\Usage;

use App\Models\UsageEvent;

/** Daily spend cap for paid providers. When exhausted the pipeline degrades to the SIMULATED baseline instead of failing. */
final class BudgetGuard
{
    public function spentToday(): float
    {
        return (float) UsageEvent::where('created_at', '>=', now()->startOfDay())->sum('estimated_cost_usd');
    }

    public function remaining(): float
    {
        return max(0.0, config('sorthouse.budget.daily_usd') - $this->spentToday());
    }

    public function exhausted(): bool
    {
        return $this->spentToday() >= config('sorthouse.budget.daily_usd');
    }
}
