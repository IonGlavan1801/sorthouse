# Security

## Threat model (summary)
Assets: workspace isolation, the integrity of approved actions, secrets, availability of the shared demo host. Adversaries: a visitor submitting hostile content, a visitor probing other sessions, automated abuse. Out of scope: a compromised host, malicious operators, real mailbox data (not used).

## Untrusted input
Emails, quoted replies, signatures, PDFs and images are data. Defence in depth:
1. Prompts delimit content in `<untrusted_content>` blocks and state that instructions inside are data; the model has no tool freedom.
2. Action types and parameter schemas are allow-listed by the application; recipients, mailbox and workspace are server-owned.
3. `InjectionScanner` flags instruction-like content (ignore rules, send to an address, mark approved, reveal secrets, bypass review) in every source, including text recovered from hidden PDF text, OCR and HTML hidden `div`s; a finding makes the case BLOCKED.
4. `ActionPolicy` blocks proposed actions outside the allow-list or the category and any recipient that is not server-approved; approval re-checks the policy.
5. 12 adversarial fixtures (email, quoted reply, signature, hidden HTML, hidden white PDF text, text in an image, RFQ, forward-all, reveal-key, policy-bypass) must produce zero action requests, outbox rows or effects (`tests/Security/PromptInjectionTest.php`).

This is not a claim that prompt injection is solved. The heuristic baseline deliberately *obeys* embedded instructions so the policy layer has real attempts to block; a real model may behave differently, and a clever payload could evade a pattern list. The structural protection is that nothing the model produces can create an effect on its own.

## Attachments
Real MIME is sniffed from bytes; extension/MIME mismatches, disallowed types, EICAR, executable content and PDFs with `/JavaScript`, `/Launch` or embedded files are quarantined before any parser or model. Size and count limits reject in a controlled way. PDFs are parsed for text only; nothing is rendered or executed. The scanner is a labelled simulation (no ClamAV in the demo stack). Downloads require the owning session, are served with `nosniff`, a restrictive CSP, `no-store`, and non-images as attachments; quarantined files are refused.

## HTML
Allow-list sanitizer (scripts, styles, iframes, forms, remote images, tracking pixels removed; links become text; attributes dropped). The UI renders message text with `x-text`, never `x-html`.

## Tenant isolation
Workspace derived from the server-side session; client `workspace_id` is ignored. Lookups are workspace-scoped (404 otherwise). Negative tests cover case, trace, attachment, evidence, approve/reject/correct/reprocess/reconcile, inbox/events/inject, expired and revoked sessions (`tests/Security/TenantIsolationTest.php`).

## Concurrency and idempotency
Optimistic version locking on review actions, row locks in transactions, unique idempotency keys on action requests and outbox rows, advisory locks around ingestion and pipeline runs. Tested with real forked processes.

## Web
CSRF via the Laravel session and `X-CSRF-TOKEN`; separate rate limits for launch, ingestion, reprocess, review and failure simulation; CSP (`default-src 'self'`, `object-src 'none'`, `frame-ancestors` limited to the Syslora portfolio origin); `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`; `X-Robots-Tag: noindex, nofollow` on every response. Known compromise: the Alpine build requires `'unsafe-eval'` in `script-src`; the CSP build of Alpine would remove it at the cost of restricting template expressions.

## Secrets
Provider keys live only in server-side environment variables, are never returned by an endpoint and never reach browser code. `.env` is not committed. Production DB uses a least-privilege role (`sorthouse_app`) distinct from the migration role.

## Reporting
Open an issue on the repository without including secrets or personal data.
