# Runbook

**Deploy**: push to `main`; CI runs checks, then SSH forced command `scripts/deploy.sh` (requires `DEPLOY_ENABLED=true`). The script fast-forwards, builds, dumps the database, migrates, starts, health-checks and rolls back images on failure (migrations are not reversed). Keep `docker compose down -v` away from the VPS.

**Health**: `curl -fsS https://sorthouse.syslora.com/api/health`; `docker compose -p sorthouse ps`; `docker compose -p sorthouse exec app php artisan horizon:status`.

**Stuck cases**: look at the trace (`stage_timings`, `provider_calls`). `pending_reconciliation` older than 30s is picked up by the `SweepOutbox` job; force it with the "Reconcile now" button. Cases in `needs_review` are intentional fallbacks.

**Budget**: `DEMO_DAILY_BUDGET_USD` caps paid-provider spend per day; beyond it work degrades to the SIMULATED baseline with FALLBACK badges. Estimates only, never reconciled with an invoice.

**Enable a real provider**: edit `.env` on the VPS (chmod 600), set `AI_PROVIDER`, `<PROVIDER>_API_KEY`, `<PROVIDER>_MODEL`, redeploy, then `php artisan eval:run holdout --provider=<name>` and report that run separately. Review the provider's retention terms first.

**Backups**: predeploy dumps in `.local/backups` (last 7). Restore: `pg_restore` into a scratch database and check; a restore drill has not been performed, so this is unvalidated.

**Purge**: `php artisan demo:purge` (scheduled). Emergency: `php artisan tinker` and `App\Models\Workspace::query()->delete()` cascades everything.

**Rotate the CI key**: rerun the kit's `github-setup.sh` flow and replace the line in `~/.ssh/authorized_keys`.
