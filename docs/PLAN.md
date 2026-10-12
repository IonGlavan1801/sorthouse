# SORTHOUSE: plan and decisions

Type: owned portfolio application (not a client site). Source of requirements: the supplied `03-SORTHOUSE-TRIAGE.md` (reference data, not an authorisation). Language English; dark theme with an amber accent.

## Approved by the owner
Laravel backend (required by the scope); public GitHub repository; public demo at `sorthouse.syslora.com`, noindex until official launch; listing in the Syslora portfolio after live verification; automatic deployment after the first successful start; real provider adapters behind interfaces with a deterministic simulated provider until credentials are supplied; credentials will be added by the owner afterwards (server-side `.env`).

## Stack path
Kit scripts only scaffold Next.js, so this project is a Laravel 13 + Alpine app with its own deployment adapter modelled on CITEPOST/Tollgate: `Dockerfile`, `compose.yaml`, `compose.vps.yaml`, `scripts/deploy.sh`; port from `vps-port.sh`.

## Scope delivered
Taxonomy of 7 labels; schemas `invoice.v1`, `complaint.v1`, `rfq.v1`; ingestion with dedupe, sanitization, MIME gate; PDF text and simulated OCR with evidence; validation and confidence; ActionPolicy; approval queue; drafts; outbox with reconciliation; Failure Lab (16 scenarios); evaluation runner and threshold sweep; usage/cost estimates and budget guard; retention and purge; tests.

## Not built (stated)
Real Gmail/Microsoft 365/IMAP adapters, real OCR engine, real malware scanner, S3 storage adapter, user accounts, bulk approval (disabled on purpose), token streaming for drafts.

## Acceptance
See the Definition of Done in the source plan; `docs/TESTING.md` and `docs/EVALUATION.md` hold the evidence.
