<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TriageCase extends Model
{
    protected function casts(): array
    {
        return [
            'overall_signal' => 'float',
            'flags' => 'array',
            'proposed_action_params' => 'array',
            'due_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function classificationRuns(): HasMany
    {
        return $this->hasMany(ClassificationRun::class);
    }

    public function extractionRuns(): HasMany
    {
        return $this->hasMany(ExtractionRun::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    public function policyDecisions(): HasMany
    {
        return $this->hasMany(PolicyDecision::class);
    }

    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class);
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(ActionRequest::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    public function stageTimings(): HasMany
    {
        return $this->hasMany(StageTiming::class);
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class);
    }
}
