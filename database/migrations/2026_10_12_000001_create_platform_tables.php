<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('workspaces', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('kind')->default('demo');
            $t->timestamps();
        });

        Schema::create('demo_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('ip_hash', 64)->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->json('fault_flags')->nullable();
            $t->timestamps();
        });

        Schema::create('mailboxes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('provider')->default('demo');
            $t->string('address');
            $t->json('allowed_recipients')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('mailbox_id')->constrained()->cascadeOnDelete();
            $t->string('provider')->default('demo');
            $t->string('external_id');
            $t->string('thread_id')->nullable();
            $t->string('sender_name')->nullable();
            $t->string('sender_email');
            $t->json('recipients');
            $t->text('subject');
            $t->timestamp('received_at');
            $t->string('status')->default('received');
            $t->string('content_hash', 64);
            $t->string('fixture_key')->nullable();
            $t->unsignedInteger('duplicate_count')->default(0);
            $t->json('sanitization')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'provider', 'external_id']);
            $t->index(['workspace_id', 'content_hash']);
        });

        Schema::create('message_bodies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $t->text('text_plain');
            $t->text('html_sanitized')->nullable();
            $t->timestamps();
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $t->string('filename');
            $t->string('declared_mime')->nullable();
            $t->string('detected_mime')->nullable();
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('storage_key')->nullable();
            $t->string('scan_status')->default('pending'); // pending|clean|quarantined|rejected
            $t->string('scan_reason')->nullable();
            $t->string('extraction_status')->default('pending');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('ingestion_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('message_id')->nullable()->constrained()->nullOnDelete();
            $t->string('correlation_id')->index();
            $t->string('outcome'); // created|duplicate|rejected
            $t->string('dedupe_key');
            $t->json('detail')->nullable();
            $t->timestamps();
        });

        Schema::create('triage_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $t->string('correlation_id')->index();
            $t->string('state')->index();
            $t->string('route')->nullable()->index(); // AUTO_ELIGIBLE|REVIEW_REQUIRED|BLOCKED
            $t->string('category')->nullable();
            $t->string('priority')->default('normal');
            $t->string('risk_band')->default('low');
            $t->decimal('overall_signal', 5, 4)->nullable();
            $t->string('confidence_band')->nullable();
            $t->string('proposed_action')->nullable();
            $t->json('proposed_action_params')->nullable();
            $t->string('assigned_reviewer')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('attempts')->default(0);
            $t->json('flags')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'message_id']);
        });

        Schema::create('classification_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('input_hash', 64);
            $t->string('provider');
            $t->string('model');
            $t->string('prompt_version');
            $t->string('taxonomy_version');
            $t->string('label');
            $t->json('alternatives');
            $t->json('signals');
            $t->json('reason_codes');
            $t->json('evidence_refs');
            $t->json('raw_redacted')->nullable();
            $t->unsignedInteger('latency_ms')->default(0);
            $t->string('status')->default('ok');
            $t->boolean('cached')->default(false);
            $t->timestamps();
            $t->unique(['triage_case_id', 'input_hash', 'taxonomy_version', 'provider']);
        });

        Schema::create('extraction_schemas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key')->unique(); // invoice, complaint, rfq
            $t->string('title');
            $t->timestamps();
        });

        Schema::create('schema_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('extraction_schema_id')->constrained()->cascadeOnDelete();
            $t->string('version'); // invoice.v1
            $t->json('definition');
            $t->string('definition_hash', 64);
            $t->timestamps();
            $t->unique(['extraction_schema_id', 'version']);
        });

        Schema::create('extraction_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('schema_version');
            $t->string('input_hash', 64);
            $t->string('provider');
            $t->string('model');
            $t->string('prompt_version');
            $t->string('status'); // ok|schema_invalid|failed
            $t->json('input_refs');
            $t->json('raw_output')->nullable();
            $t->json('warnings')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->decimal('cost_usd', 10, 6)->default(0);
            $t->unsignedInteger('latency_ms')->default(0);
            $t->unsignedInteger('retries')->default(0);
            $t->boolean('cached')->default(false);
            $t->boolean('is_current')->default(true);
            $t->timestamps();
            $t->unique(['triage_case_id', 'input_hash', 'schema_version', 'provider']);
        });

        Schema::create('extracted_fields', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('extraction_run_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('field_name');
            $t->text('predicted_value')->nullable();
            $t->text('normalized_value')->nullable();
            $t->string('data_type');
            $t->boolean('is_critical')->default(false);
            $t->decimal('confidence_signal', 5, 4)->nullable();
            $t->string('signal_source')->nullable(); // model|ocr|rule
            $t->string('validation_status')->default('pending'); // valid|invalid|unsupported|needs_review|pending|missing
            $t->string('source_type')->nullable(); // email_body|pdf_text|image_ocr
            $t->timestamps();
            $t->unique(['extraction_run_id', 'field_name']);
        });

        Schema::create('field_evidence', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('extracted_field_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('source_id'); // msg:body | att:{uuid}:p{n}
            $t->string('locator_type'); // span|page_snippet|bbox
            $t->unsignedInteger('span_start')->nullable();
            $t->unsignedInteger('span_end')->nullable();
            $t->unsignedInteger('page')->nullable();
            $t->json('bbox')->nullable(); // [x,y,w,h] relative 0..1
            $t->decimal('ocr_confidence', 5, 4)->nullable();
            $t->text('snippet');
            $t->string('snippet_hash', 64);
            $t->string('provenance'); // resolver|ocr
            $t->timestamps();
        });

        Schema::create('validation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('extraction_run_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('case_version');
            $t->boolean('schema_valid');
            $t->boolean('with_corrections')->default(false);
            $t->string('rules_version');
            $t->unsignedInteger('issue_count')->default(0);
            $t->timestamps();
        });

        Schema::create('validation_issues', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('validation_run_id')->constrained()->cascadeOnDelete();
            $t->string('code');
            $t->string('severity'); // error|warning
            $t->string('field_name')->nullable();
            $t->text('message');
            $t->timestamps();
        });

        Schema::create('confidence_assessments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('case_version');
            $t->string('formula_version');
            $t->json('signals');
            $t->decimal('overall', 5, 4);
            $t->string('band');
            $t->boolean('calibrated')->default(false);
            $t->timestamps();
        });

        Schema::create('automation_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key')->unique();
            $t->foreignUuid('active_version_id')->nullable();
            $t->timestamps();
        });

        Schema::create('policy_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('automation_policy_id')->constrained()->cascadeOnDelete();
            $t->string('version');
            $t->json('rules');
            $t->timestamps();
            $t->unique(['automation_policy_id', 'version']);
        });

        Schema::create('policy_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('policy_version_id')->constrained('policy_versions');
            $t->unsignedInteger('case_version');
            $t->string('decision'); // AUTO_PROCESS|REVIEW_REQUIRED|BLOCKED
            $t->json('reasons');
            $t->json('context')->nullable();
            $t->timestamps();
        });

        Schema::create('review_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('decision'); // approved|corrected|rejected
            $t->string('actor');
            $t->string('reason_code')->nullable();
            $t->text('note')->nullable();
            $t->unsignedInteger('case_version');
            $t->json('snapshot');
            $t->timestamps();
        });

        Schema::create('field_corrections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('extracted_field_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('review_decision_id')->nullable()->constrained()->nullOnDelete();
            $t->string('field_name');
            $t->text('original_value')->nullable();
            $t->text('corrected_value')->nullable();
            $t->text('normalized_value')->nullable();
            $t->string('error_category')->nullable();
            $t->text('reason')->nullable();
            $t->string('actor');
            $t->string('dataset')->default('production_demo'); // never evaluation_holdout
            $t->timestamps();
        });

        Schema::create('drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('template_version');
            $t->string('prompt_version');
            $t->string('provider');
            $t->string('subject');
            $t->text('body');
            $t->text('edited_body')->nullable();
            $t->json('claims');
            $t->string('status')->default('draft'); // draft|approved|queued|discarded
            $t->timestamps();
        });

        Schema::create('action_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('action_type');
            $t->json('params');
            $t->string('idempotency_key', 64)->unique();
            $t->unsignedInteger('case_version');
            $t->string('status')->default('queued'); // queued|executing|executed|execution_failed|pending_reconciliation|cancelled
            $t->string('requested_by');
            $t->timestamps();
        });

        Schema::create('outbox_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('action_request_id')->constrained()->cascadeOnDelete();
            $t->string('idempotency_key', 64)->unique();
            $t->string('status')->default('pending'); // pending|processing|done|pending_reconciliation|failed
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });

        Schema::create('action_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('action_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('attempt');
            $t->string('mode'); // SIMULATED|SANDBOX|LIVE
            $t->string('outcome'); // executed|failed|unknown_timeout|reconciled_executed|reconciled_missing
            $t->json('detail')->nullable();
            $t->unsignedInteger('latency_ms')->default(0);
            $t->timestamps();
        });

        // Stand-in for the third party that receives simulated effects: lets reconciliation prove no double execution.
        Schema::create('simulated_effects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('idempotency_key', 64)->unique();
            $t->string('action_type');
            $t->json('payload');
            $t->timestamps();
        });

        Schema::create('provider_calls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('correlation_id')->nullable()->index();
            $t->string('stage');
            $t->string('provider');
            $t->string('model');
            $t->string('status'); // ok|timeout|rate_limited|invalid_output|error
            $t->unsignedInteger('attempt')->default(1);
            $t->unsignedInteger('latency_ms')->default(0);
            $t->boolean('cached')->default(false);
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::create('usage_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('stage');
            $t->string('provider');
            $t->string('model');
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('image_units')->default(0);
            $t->unsignedInteger('page_units')->default(0);
            $t->unsignedInteger('latency_ms')->default(0);
            $t->decimal('estimated_cost_usd', 10, 6)->default(0);
            $t->string('pricing_version');
            $t->timestamps();
            $t->index('created_at');
        });

        Schema::create('stage_timings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->constrained()->cascadeOnDelete();
            $t->string('correlation_id')->index();
            $t->string('stage');
            $t->string('status')->default('ok');
            $t->unsignedInteger('duration_ms')->default(0);
            $t->unsignedInteger('retries')->default(0);
            $t->json('detail')->nullable();
            $t->timestamp('started_at');
            $t->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('triage_case_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('correlation_id')->nullable()->index();
            $t->string('event'); // case.transition, review.approved, ...
            $t->string('actor');
            $t->string('from_state')->nullable();
            $t->string('to_state')->nullable();
            $t->unsignedInteger('case_version')->nullable();
            $t->json('detail')->nullable();
            $t->timestamps();
            $t->index(['triage_case_id', 'created_at']);
        });

        Schema::create('evaluation_datasets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key')->unique(); // dev|holdout
            $t->string('description');
            $t->timestamps();
        });

        Schema::create('evaluation_cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('evaluation_dataset_id')->constrained()->cascadeOnDelete();
            $t->string('fixture_key')->unique();
            $t->json('ground_truth');
            $t->timestamps();
        });

        Schema::create('evaluation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('evaluation_dataset_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('model');
            $t->string('policy_version');
            $t->string('prompt_version');
            $t->boolean('simulated')->default(true);
            $t->json('config');
            $t->json('metrics');
            $t->unsignedInteger('case_count');
            $t->timestamps();
        });

        Schema::create('evaluation_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('evaluation_run_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('evaluation_case_id')->constrained()->cascadeOnDelete();
            $t->json('result');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['evaluation_results', 'evaluation_runs', 'evaluation_cases', 'evaluation_datasets', 'audit_events', 'stage_timings', 'usage_events', 'provider_calls', 'simulated_effects', 'action_runs', 'outbox_messages', 'action_requests', 'drafts', 'field_corrections', 'review_decisions', 'policy_decisions', 'policy_versions', 'automation_policies', 'confidence_assessments', 'validation_issues', 'validation_runs', 'field_evidence', 'extracted_fields', 'extraction_runs', 'schema_versions', 'extraction_schemas', 'classification_runs', 'triage_cases', 'ingestion_runs', 'attachments', 'message_bodies', 'messages', 'mailboxes', 'demo_sessions', 'workspaces', 'sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
