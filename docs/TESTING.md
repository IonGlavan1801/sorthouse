# Testing

| Layer | Where | What |
|---|---|---|
| Unit | `tests/Unit` | normalisation (money, dates, currency, lists), evidence resolver, state-transition table, HTML sanitizer |
| Feature | `tests/Feature` | ingestion (duplicate x100, quarantine, limits), pipeline and failure injection, review (approve/correct/reject, conflicts, replay), outbox and reconciliation, policy matrix, demo API, retention/purge |
| Security | `tests/Security` | 12 adversarial fixtures with zero escalation, cross-tenant 404s, expired/revoked sessions |
| Integration | `tests/Integration` | provider adapters with HTTP fakes (request shape, 429/5xx/timeouts, invalid output, budget fallback, live-provider pipeline), real parallel processes (`pcntl_fork`): two reviewers, replayed approvals, two pipeline jobs, parallel duplicate ingestion |
| E2E | `tests/E2E` (Playwright) | launch, evidence region, correction, approve, reject, Failure Lab, evaluation; desktop, mobile (390px) and reduced-motion projects; checks for horizontal overflow |
| Evaluation | `php artisan eval:run` | measured metrics over the labelled corpus |

Run: `composer test` (PHPUnit against PostgreSQL `sorthouse_test`), `npx playwright test` (needs the stack on `E2E_URL`, default http://127.0.0.1:8139, and `DEMO_LAUNCH_PER_MINUTE` raised), `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`. CI order: lint, static analysis, tests, evaluation smoke, audit, Docker build, deploy.

Current result: 129 PHPUnit tests (about 800 assertions) and 12 Playwright tests pass.

Static analysis is level 4 with a generated baseline (`phpstan-baseline.neon`) that mostly records Eloquent typing noise (model properties from `casts()`); it is debt, not a claim of full typing.

Not covered: real provider calls, real OCR, backup restore, load testing.
