@extends('layout')
@section('title', 'Sorthouse - AI triage that knows when to stop')
@section('body')
<header class="topbar">
    <a class="brand" href="/">@include('partials.logo') SORTHOUSE</a>
    <span class="spacer"></span>
    <span class="badge badge-SIMULATED">Demo · synthetic data</span>
</header>
<main>
<section class="hero">
    <h1>AI triage that knows when to <em>stop and ask a human.</em></h1>
    <p class="lead">Sorthouse classifies email and documents, extracts structured fields with evidence, validates them, and lets a person approve, correct or reject before anything happens. Nothing runs twice, and every decision leaves an audit trail.</p>
    <div class="cta" x-data="{ busy: false, err: '' }">
        <button class="btn btn-primary btn-lg" :disabled="busy" @click="busy = true; err=''; sorthouseLaunch().catch(e => { busy = false; err = e.message })">Launch demo</button>
        <a class="btn btn-lg" href="#how">How it works</a>
        <span class="muted" x-show="busy" x-cloak>Preparing an isolated inbox…</span>
        <span style="color:var(--danger)" x-text="err"></span>
    </div>
    <p class="faint" style="margin-top:12px;font-size:12.5px">Starts an isolated session with a synthetic inbox that is deleted automatically. No account, no real mail.</p>
    <div class="pipeline" aria-label="Pipeline">
        @foreach (['received', 'sanitized', 'classified', 'attachments processed', 'extracted', 'validated', 'policy checked', 'review / ready'] as $s)
            <span class="{{ $loop->last ? 'hot' : '' }}">{{ $s }}</span>
        @endforeach
    </div>
    <div class="pillars">
        <div class="pillar"><h3>Evidence for every field</h3><p>Each extracted value links to the exact span, page or image region it came from. A value without verifiable evidence is marked unsupported, never accepted quietly.</p></div>
        <div class="pillar"><h3>Policy outside the model</h3><p>An ActionPolicy decides AUTO, REVIEW or BLOCKED from deterministic checks. Model confidence is only one signal and a valid JSON is not proof of correctness.</p></div>
        <div class="pillar"><h3>Safe, idempotent actions</h3><p>Approval writes a transactional outbox. Retries, duplicate deliveries and timeouts after send are reconciled instead of repeated.</p></div>
    </div>
</section>

<section class="section" id="how">
    <h2>What you can try in two minutes</h2>
    <p class="muted">Open the approval queue, inspect a text PDF invoice, then a photographed one where the OCR confuses <span class="mono">8</span> and <span class="mono">B</span>. Correct the field, approve a complaint reply, then break things on purpose in the Failure Lab: prompt injection, duplicate delivery, concurrent reviewers, a timeout after send.</p>
    <div class="metric-grid" x-data="{ run: null }" x-init="fetch('/api/evaluation').then(r => r.json()).then(d => run = d.runs.find(r => r.dataset === 'holdout') ?? null)">
        <template x-if="run">
            <div style="display:contents">
                <div class="metric"><div class="n" x-text="run.cases"></div><div class="l">cases in the published holdout run (synthetic)</div></div>
                <div class="metric"><div class="n" x-text="(run.metrics.classification.macro_f1 * 100).toFixed(1) + '%'"></div><div class="l">macro F1, <span x-text="run.simulated ? 'SIMULATED heuristic baseline' : run.provider"></span></div></div>
                <div class="metric"><div class="n" x-text="run.metrics.policy.false_auto_actions"></div><div class="l">false auto actions in that run</div></div>
                <div class="metric"><div class="n" x-text="run.metrics.policy.unsafe_blocked + '/' + run.metrics.policy.unsafe_total"></div><div class="l">adversarial and unsafe cases blocked</div></div>
            </div>
        </template>
        <template x-if="!run"><div class="callout" style="grid-column:1/-1">No evaluation run has been published on this deployment yet.</div></template>
    </div>
</section>

<section class="section">
    <h2>Live vs simulated, stated plainly</h2>
    <table class="simple">
        <thead><tr><th>Part</th><th>Status</th><th>Detail</th></tr></thead>
        <tbody>
        <tr><td>Inbox, messages, invoices, photos</td><td><span class="badge badge-SIMULATED">Simulated</span></td><td>Synthetic corpus generated for this lab. No real customer data or PII.</td></tr>
        <tr><td>Classifier and extractor</td><td><span class="badge badge-SIMULATED">Simulated</span> / <span class="badge badge-LIVE">Live</span></td><td>A deterministic heuristic baseline by default. OpenAI, Anthropic and Gemini adapters exist behind the same interfaces and are enabled only by server-side configuration.</td></tr>
        <tr><td>OCR</td><td><span class="badge badge-SIMULATED">Simulated</span></td><td>Photographed invoices ship the OCR words, boxes and confidences an engine would return, including deliberate misreads.</td></tr>
        <tr><td>Malware scan, mail provider, actions</td><td><span class="badge badge-SIMULATED">Simulated</span></td><td>Gates and idempotency behave as in production; effects stay inside the lab. Nothing is sent to third parties.</td></tr>
        <tr><td>Queue, outbox, locking, audit, policy, validation</td><td><span class="badge badge-LIVE">Live</span></td><td>Real PostgreSQL state machine, Redis/Horizon jobs, optimistic locking and a real test suite, including parallel-process tests.</td></tr>
        </tbody>
    </table>
</section>
</main>
<footer class="footer">
    Sorthouse is a portfolio lab, not a production product. Model signals are not calibrated probabilities, and metrics describe the included synthetic corpus only. Prompt injection is mitigated in layers, not solved.
</footer>
@endsection
