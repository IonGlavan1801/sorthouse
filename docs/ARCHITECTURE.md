# Architecture

## Shape
Modular monolith. `app/Domain` (enums, schema catalog, normalizer), `app/Application` (use cases), `app/Infrastructure` (AI providers, documents, security, faults), `app/Http` (controllers, presenters, middleware). Entities are internal (`Message`, `Attachment`, `TriageCase`, `ExtractionRun`, `ReviewDecision`, `ActionRun`): provider ids are stored separately and the domain is not built around them.

## Contracts and the capability matrix
`LanguageModel`, `Classifier`, `StructuredExtractor`, `Drafter`, `DocumentExtractor`, `EvidenceResolver`, `MalwareScanner`, `ActionExecutor`. Implementations: `Heuristic*` (default, SIMULATED), `Llm*` over `OpenAiModel` / `AnthropicModel` / `GeminiModel`. `CapabilityMatrix` states what each adapter does (image input, structured-output mechanism, usage reporting) and whether it has been verified live. OpenAI uses `json_schema` response format, Anthropic a single forced tool call, Gemini `responseSchema`; the three are not equivalent and repair retries are counted, not hidden.

## State machine
`received -> sanitizing -> processing -> classified -> extracting -> validating -> awaiting_policy -> awaiting_review | approved -> queued_action -> executing -> executed`, with `failed_retryable`, `failed_terminal`, `needs_review`, `blocked`, `corrected`, `rejected`, `execution_failed`, `pending_reconciliation`. `CaseState::allowed()` is the single transition table; `CaseStateMachine::transition()` locks the row, checks the transition, bumps `version` and writes an `audit_event` with actor and version. A test proves every state is reachable and terminal states have no exits.

## Pipeline
`TriagePipeline` is resumable: every stage persists its result keyed by input hash (plus a reprocess epoch), so a retried job reuses it instead of duplicating it. Concurrent runs for one case are serialised by a Postgres advisory lock. Stages: sanitize and attachment gate (quarantine stops here, nothing reaches a parser or model), attachments (PDF text layer; simulated OCR for images and image-only PDFs), classify, extract (structure check inside the gateway so invalid output triggers a visible retry), normalize, resolve evidence, validate, confidence, policy, route.

Every model/OCR call goes through `ProviderGateway`: fault injection hook, bounded retries with exponential backoff and jitter, one `provider_calls` row per attempt. After retries the case moves to `needs_review` with a `FALLBACK` badge; it never disappears.

## Evidence
The model returns quotes plus a source id. `QuoteEvidenceResolver` accepts a source id only if it belongs to the case input and a quote only if it occurs in that source. Email: span; PDF: page + span; image: bounding box and OCR confidence. A field with a value but no resolvable evidence is `unsupported` (validation error), so it cannot be auto-processed.

## Validation and confidence
`ValidationEngine`: required fields, parse problems (`UNPARSEABLE_DATE`, `AMBIGUOUS_DATE`, ...), currency allow-list, e-mail format, evidence, JSON Schema (`opis/json-schema`, `additionalProperties: false`), and business rules (`subtotal + tax = total` only when both are present, `due >= issue`, PO format, vendor reference lookup with a timeout state, low OCR confidence). `conf.v1` keeps the signals separate per field (model, OCR, evidence, validation) and aggregates with the formula in `ConfidenceAggregator`; it is labelled a signal, not a probability.

## Policy
`ActionPolicy::evaluate()` is pure: it reads computed facts, never message text. BLOCKED: instruction-like content, quarantined attachment, action outside the allow-list or the category, recipient not server-approved, signal below the minimum. REVIEW_REQUIRED: high-impact category (invoice, complaint, order are approval-only), uncertain category, validation issues, missing fields, provider fallback, rejected attachment, signal below `auto_min`. AUTO_ELIGIBLE only for low-impact reversible actions. Policy versions are immutable rows; each decision stores the version used. Approval re-runs the policy.

## Actions
Approval writes `review_decision`, `action_request` (idempotency key = sha256(case, approved version, action type)) and `outbox_message` in one transaction. Workers execute from the outbox: duplicate delivery is a no-op, a timeout after send moves the case to `pending_reconciliation`, and `reconcile()` asks the (simulated) provider before any re-send. The executor writes to `simulated_effects`, which stands in for the third party and enforces idempotency by key. Recipients and mailbox are server-owned.

## Tenancy
Every row carries `workspace_id`. The workspace comes from the demo session in the server-side Laravel session, never from client input. Controllers look cases up with the workspace scope (another tenant's id is a 404); jobs receive internal ids and reload with scope; storage keys are namespaced `attachments/{workspace}/...`.

## Data model
See `database/migrations`. Deliberate reductions versus the long table list in the original plan: `demo_sessions` and `workspaces` cover sessions; `review_queue_items` is the `triage_cases` aggregate itself; classification candidates live in `classification_runs.alternatives`; `message_recipients` is a JSON column; `mailbox_connections` and `workspace_members` belong to the real-mailbox extension that is intentionally not built. Microsecond timestamps are used so "latest run" is well defined.

## Deployment
`Dockerfile` (assets, app, web targets), `compose.yaml` (db, redis, app, worker, scheduler, web), `compose.vps.yaml` (private DB/Redis, Traefik labels, unique aliases, memory limits), `scripts/deploy.sh` (lock, fast-forward only, build, pg_dump, migrate, least-privilege DB role, health check, rollback of images; migrations are not reversed).
