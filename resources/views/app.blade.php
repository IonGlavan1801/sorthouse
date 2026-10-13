@extends('layout')
@section('title', 'Sorthouse - Demo')
@section('body')
<div x-data="sorthouse()" x-init="init()" x-cloak>

    {{-- ===== no session ===== --}}
    <template x-if="ready && noSession">
        <div class="page" style="text-align:center;padding-top:90px">
            <h2>Your demo session ended</h2>
            <p class="muted">Sessions are isolated and expire automatically. Start a new one to continue.</p>
            <button class="btn btn-primary btn-lg" @click="relaunch()">Launch demo</button>
        </div>
    </template>

    <template x-if="!ready"><div class="empty">Loading your isolated inbox…</div></template>

    <div x-show="ready && !noSession">
        <header class="topbar">
            <a class="brand" href="/">@include('partials.logo') SORTHOUSE</a>
            <span class="badge" :class="session?.provider.live ? 'badge-LIVE' : 'badge-SIMULATED'" x-text="session?.provider.live ? 'LIVE · ' + session.provider.name : 'SIMULATED · heuristic baseline'" title="Which engine classified and extracted"></span>
            <template x-for="(n, k) in faults" :key="k"><span class="badge badge-FALLBACK plain" x-text="'armed: ' + human(k) + ' ×' + n"></span></template>
            <span class="spacer"></span>
            <a href="https://github.com/IonGlavan1801/sorthouse" class="quiet-link">Source ↗</a>
            <label class="muted" style="display:flex;gap:6px;align-items:center;font-size:12.5px">Reviewer
                <select x-model="reviewer" aria-label="Acting reviewer"><option value="a">A</option><option value="b">B</option></select>
            </label>
            <span class="muted mono" title="Session time left">⏱ <span x-text="ttl()"></span></span>
            <button class="btn btn-sm btn-ghost" @click="deleteNow()">Delete demo data now</button>
        </header>
        <div class="demo-banner">
            <strong>DEMO</strong> The public demo runs a deterministic baseline. LLM adapters are pluggable; reported baseline metrics are not LLM results. Synthetic inbox. Classifier, OCR, malware scan and actions are <b>SIMULATED</b> unless a badge says LIVE. Scores are model signals, not probabilities.
        </div>

        <nav class="tabs" role="tablist" aria-label="Sections">
            <button class="tab" role="tab" :aria-selected="tab === 'inbox'" @click="openTab('inbox')">Inbox</button>
            <button class="tab" role="tab" :aria-selected="tab === 'queue'" @click="openTab('queue')">Approval Queue <span class="count" x-text="(counters.ready ?? 0) + (counters.needs_review ?? 0)"></span></button>
            <button class="tab" role="tab" :aria-selected="tab === 'lab'" @click="openTab('lab')">Failure Lab</button>
            <button class="tab" role="tab" :aria-selected="tab === 'eval'" @click="openTab('eval')">Evaluation</button>
            <button class="tab" role="tab" :aria-selected="tab === 'caps'" @click="openTab('caps')">Providers &amp; data</button>
        </nav>

        {{-- ===== INBOX WORKBENCH ===== --}}
        <section x-show="tab === 'inbox'">
            <div class="mobile-switch">
                <button class="btn btn-sm" :class="pane === 'list' && 'btn-primary'" @click="pane = 'list'">Inbox</button>
                <button class="btn btn-sm" :class="pane === 'case' && 'btn-primary'" @click="pane = 'case'">Case</button>
                <button class="btn btn-sm" :class="pane === 'trace' && 'btn-primary'" @click="pane = 'trace'">Trace</button>
            </div>
            <div class="workbench">
                {{-- list --}}
                <div class="pane" :data-mobile="pane === 'list' ? 'show' : 'hidden'">
                    <div class="pane-head"><span class="pane-title">Inbox · <span x-text="cases.length"></span></span>
                        <select x-model="sort" aria-label="Sort"><option value="risk">Risk</option><option value="confidence">Confidence</option><option value="category">Category</option><option value="received">Received</option><option value="sla">SLA</option></select></div>
                    <template x-for="c in sorted(cases)" :key="c.id">
                        <button class="msg" :aria-current="c.id === selectedId" @click="select(c.id)">
                            <div class="row"><span class="badge badge-cat" x-text="human(c.category)"></span>
                                <span class="badge" :class="'badge-' + c.state" x-text="stateLabel(c)"></span></div>
                            <div class="subject" x-text="c.subject"></div>
                            <div class="sender" x-text="(c.sender.name ?? '') + ' <' + c.sender.email + '>'"></div>
                            <div class="meta">
                                <template x-for="b in c.badges" :key="b"><span class="badge" :class="'badge-' + b" x-text="b"></span></template>
                                <span class="badge" :class="'badge-' + (c.confidence_band ?? 'medium')" x-show="c.overall_signal !== null" x-text="'signal ' + pct(c.overall_signal)"></span>
                                <span class="badge plain" x-show="c.duplicates > 0" x-text="c.duplicates + ' duplicate' + (c.duplicates > 1 ? 's' : '') + ' suppressed'"></span>
                                <span class="badge badge-warning plain" x-show="c.issue_count > 0" x-text="c.issue_count + ' issue' + (c.issue_count > 1 ? 's' : '')"></span>
                            </div>
                        </button>
                    </template>
                    <div class="empty" x-show="!cases.length">No messages yet.</div>
                </div>

                {{-- case --}}
                <div class="pane case-pane" :data-mobile="pane === 'case' ? 'show' : 'hidden'">
                    <template x-if="!detail"><div class="empty">Select a message to inspect the case.</div></template>
                    <template x-if="detail">
                        <div style="flex:1;display:flex;flex-direction:column;min-height:0">
                            <div class="case-head">
                                <h2 x-text="detail.subject"></h2>
                                <div class="muted" x-text="(detail.sender.name ?? '') + ' <' + detail.sender.email + '> · ' + time(detail.received_at)"></div>
                                <div class="chips">
                                    <span class="badge badge-cat" x-text="human(detail.category)"></span>
                                    <span class="badge" :class="'badge-' + detail.state" x-text="stateLabel(detail)"></span>
                                    <span class="badge" :class="'badge-' + detail.route" x-show="detail.route" x-text="detail.route"></span>
                                    <span class="badge" :class="'badge-' + (detail.confidence_band ?? 'medium')" x-show="detail.overall_signal !== null" x-text="'signal ' + pct(detail.overall_signal) + ' · ' + (detail.confidence_band ?? '')" title="Model/validation signal. Not a calibrated probability."></span>
                                    <template x-for="b in detail.badges" :key="b"><span class="badge" :class="'badge-' + b" x-text="b"></span></template>
                                    <span class="badge badge-FALLBACK plain" x-show="isBusyState(detail)">processing…</span>
                                </div>
                                <div class="stepper" aria-label="Pipeline">
                                    <template x-for="s in stages" :key="s[0]"><div class="step" :class="stageStatus(s[0])"><span x-text="s[1]"></span><span class="ms" x-text="stageMs(s[0])"></span></div></template>
                                </div>
                            </div>

                            <div class="why" :class="detail.route === 'BLOCKED' ? 'blocked' : (detail.route === 'AUTO_ELIGIBLE' ? 'ok' : '')" x-show="reasons().length" style="margin:8px 18px">
                                <h4 x-text="whyTitle()"></h4>
                                <ul><template x-for="r in reasons()" :key="r.code"><li><code x-text="r.code"></code> <span x-text="r.message"></span></li></template></ul>
                                <div class="faint" style="margin-top:6px;font-size:12px" x-show="detail.policy">Decision by <span x-text="detail.policy?.version"></span>, evaluated outside the model.</div>
                            </div>

                            <div class="subtabs" role="tablist">
                                <button class="subtab" role="tab" :aria-selected="caseTab === 'fields'" @click="caseTab = 'fields'" x-show="detail.extraction">Fields</button>
                                <button class="subtab" role="tab" :aria-selected="caseTab === 'message'" @click="caseTab = 'message'">Message</button>
                                <button class="subtab" role="tab" :aria-selected="caseTab === 'attachment'" @click="caseTab = 'attachment'" x-show="detail.message.attachments.length">Attachments <span class="muted" x-text="'(' + detail.message.attachments.length + ')'"></span></button>
                                <button class="subtab" role="tab" :aria-selected="caseTab === 'draft'" @click="caseTab = 'draft'" x-show="detail.draft">Draft reply</button>
                                <button class="subtab" role="tab" :aria-selected="caseTab === 'actions'" @click="caseTab = 'actions'">Action &amp; audit</button>
                            </div>

                            <div class="case-body" style="flex:1;overflow:auto">
                                {{-- FIELD INSPECTOR --}}
                                <div x-show="caseTab === 'fields' && detail.extraction">
                                    <div class="muted" style="font-size:12.5px;margin-bottom:8px" x-show="detail.extraction">
                                        <span x-text="detail.extraction?.schema_version"></span> · <span x-text="detail.extraction?.provider"></span> / <span x-text="detail.extraction?.model"></span> ·
                                        validation <span x-text="detail.validation?.rules_version"></span> · schema <b x-text="detail.validation?.schema_valid ? 'valid' : 'INVALID'"></b>
                                        <span x-show="detail.extraction?.retries > 0" x-text="' · ' + detail.extraction?.retries + ' retry'"></span>
                                    </div>
                                    <div style="overflow-x:auto"><table class="fields">
                                        <thead><tr><th>Field</th><th>Value</th><th>Signal</th><th>Validation</th><th>Evidence</th></tr></thead>
                                        <tbody>
                                        <template x-for="f in detail.extraction?.fields ?? []" :key="f.name">
                                            <tr class="clickable" :class="activeField === f.name && 'active'" @click="pick(f)">
                                                <td><span x-text="f.name"></span><span class="crit" x-show="f.critical" title="Critical field">★</span></td>
                                                <td class="val">
                                                    <template x-if="f.corrected"><span><s x-text="f.predicted ?? '∅'"></s> → <span class="corrected" x-text="f.effective ?? '∅'"></span></span></template>
                                                    <template x-if="!f.corrected"><span x-text="f.predicted ?? '∅ (not found)'"></span></template>
                                                    <div class="faint" x-show="f.predicted_normalized && f.predicted_normalized !== f.predicted && !f.corrected" x-text="'normalized: ' + f.predicted_normalized"></div>
                                                    <div class="faint" x-show="f.corrected && f.effective_normalized" x-text="'normalized: ' + f.effective_normalized"></div>
                                                </td>
                                                <td style="min-width:90px">
                                                    <div class="bar" :class="level(f.corrected ? 1 : f.signal)"><i :style="'width:' + Math.round((f.corrected ? 1 : f.signal) * 100) + '%'"></i></div>
                                                    <div class="faint" style="font-size:11px" x-text="f.corrected ? 'human' : (signalBadge(f) + ' ' + pct(f.signal))"></div>
                                                    <div class="faint" style="font-size:11px" x-show="f.ocr_confidence !== null" x-text="'OCR ' + pct(f.ocr_confidence)"></div>
                                                </td>
                                                <td><span class="badge" :class="'badge-' + f.status" x-text="human(f.status)"></span></td>
                                                <td><template x-for="(ev, i) in f.evidence" :key="ev.id"><button class="btn btn-sm" @click.stop="activeField = f.name; openEvidence(f, ev)" x-text="ev.locator === 'bbox' ? 'region' : (ev.locator === 'span' ? 'quote' : 'p' + (ev.page ?? '·'))"></button></template><span class="faint" x-show="!f.evidence.length" x-text="f.corrected ? 'human input' : '–'"></span></td>
                                            </tr>
                                        </template>
                                        </tbody>
                                    </table></div>
                                    <div class="callout" x-show="detail.validation?.issues.length">
                                        <b>Validation issues</b>
                                        <ul style="margin:6px 0 0;padding-left:18px">
                                            <template x-for="i in detail.validation?.issues ?? []" :key="i.code + i.field"><li><code class="mono" x-text="i.code"></code> <span class="badge plain" :class="'badge-' + (i.severity === 'error' ? 'invalid' : 'warning')" x-text="i.severity"></span> <span x-text="i.message"></span></li></template>
                                        </ul>
                                    </div>
                                    <div class="callout" x-show="lastChange && lastChange.issues_after" style="border-color:rgba(61,214,176,.4)">
                                        <b>Last correction</b>: validation ran again.
                                        Issues before: <span class="mono" x-text="(lastChange?.issues_before ?? []).map(i => i.code).join(', ') || 'none'"></span>;
                                        after: <span class="mono" x-text="(lastChange?.issues_after ?? []).map(i => i.code).join(', ') || 'none'"></span>.
                                    </div>

                                    {{-- evidence viewer --}}
                                    <div class="evidence-viewer" x-show="activeEvidence">
                                        <header>
                                            <strong>Evidence for <span x-text="activeField"></span></strong>
                                            <span class="mono faint" x-text="activeEvidence?.source_id"></span>
                                            <span class="badge plain badge-warning" x-show="activeEvidence?.ocr_confidence !== null && activeEvidence?.ocr_confidence < 0.8" x-text="'OCR ' + pct(activeEvidence?.ocr_confidence)"></span>
                                        </header>
                                        <template x-if="activeEvidence && activeEvidence.bbox && evidenceAttachment()?.is_image">
                                            <div class="imgwrap"><img :src="imageSrc(evidenceAttachment())" alt="Invoice photo with the evidence region highlighted"><div class="bbox" :style="bboxStyle(activeEvidence)"></div></div>
                                        </template>
                                        <template x-if="activeEvidence && !(activeEvidence.bbox && evidenceAttachment()?.is_image)">
                                            <div class="note" style="padding:12px">
                                                <pre class="body" style="max-height:260px;overflow:auto"><span x-text="parts(activeEvidence.source_id, activeEvidence.span)[0]"></span><mark class="ev" x-text="parts(activeEvidence.source_id, activeEvidence.span)[1]"></mark><span x-text="parts(activeEvidence.source_id, activeEvidence.span)[2]"></span></pre>
                                            </div>
                                        </template>
                                        <div class="note" x-show="activeEvidence?.locator !== 'bbox' && evidenceAttachment()">No stable bounding box for this source: evidence is shown at page / snippet level (stated limitation).</div>
                                        <div class="note" x-show="activeEvidence?.provenance === 'ocr'">Boxes come from the SIMULATED OCR adapter (see Providers &amp; data).</div>
                                    </div>

                                    <details style="margin-top:14px"><summary class="muted" style="cursor:pointer">Validated JSON (effective values)</summary>
                                        <pre class="json mono" x-text="JSON.stringify(detail.extraction?.object, null, 2)"></pre></details>
                                </div>

                                {{-- MESSAGE --}}
                                <div x-show="caseTab === 'message'">
                                    <pre class="body"><template x-if="activeEvidence?.source_id === 'msg:body'"><span><span x-text="parts('msg:body', activeEvidence.span)[0]"></span><mark class="ev" x-text="parts('msg:body', activeEvidence.span)[1]"></mark><span x-text="parts('msg:body', activeEvidence.span)[2]"></span></span></template><template x-if="activeEvidence?.source_id !== 'msg:body'"><span x-text="detail.message.text"></span></template></pre>
                                    <div class="callout" x-show="detail.message.sanitization">
                                        Rendered as plain text. HTML bodies are sanitised: <span class="mono" x-text="JSON.stringify(detail.message.sanitization)"></span>. Remote images, scripts and links are never loaded.
                                    </div>
                                    <div class="callout" style="border-color:rgba(255,107,107,.45)" x-show="detail.policy?.context?.injection?.length">
                                        <b>Instruction-like content detected</b> and treated as data:
                                        <ul style="margin:6px 0 0;padding-left:18px"><template x-for="i in (detail.policy?.context?.injection ?? [])" :key="i.code + i.source_id"><li><code class="mono" x-text="i.code"></code> in <span class="mono" x-text="i.source_id"></span>: “<span x-text="i.quote"></span>”</li></template></ul>
                                    </div>
                                </div>

                                {{-- ATTACHMENTS --}}
                                <div x-show="caseTab === 'attachment'">
                                    <template x-for="a in detail.message.attachments" :key="a.id">
                                        <div class="card" style="margin-bottom:12px">
                                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><strong x-text="a.filename"></strong>
                                                <span class="badge" :class="'badge-' + a.scan_status" x-text="a.scan_status + ' · ' + a.scan_mode"></span>
                                                <span class="badge plain" x-text="a.mime + ' · ' + Math.round(a.size / 1024) + ' KB'"></span>
                                                <span class="badge plain badge-SIMULATED" x-show="a.ocr_simulated">simulated OCR</span></div>
                                            <div class="muted mono" x-text="'sha256 ' + a.sha256 + '… · declared ' + (a.declared_mime ?? '?') + ' · extraction ' + a.extraction_status"></div>
                                            <div class="muted" x-show="a.scan_reason" x-text="'Reason: ' + a.scan_reason + '. This file never reached a parser or a model.'" style="color:var(--danger)"></div>
                                            <template x-if="a.is_image && a.scan_status === 'clean'"><div class="imgwrap"><img :src="imageSrc(a)" :alt="'Attachment ' + a.filename"></div></template>
                                            <template x-if="a.scan_status === 'clean' && !a.is_image"><a class="btn btn-sm" :href="imageSrc(a)" target="_blank" rel="noopener">Download original</a></template>
                                            <template x-for="s in a.sources" :key="s.id"><details><summary class="muted" style="cursor:pointer" x-text="'Extracted text · ' + s.type + (s.page ? ' · page ' + s.page : '')"></summary><pre class="body" style="max-height:220px;overflow:auto" x-text="s.text"></pre></details></template>
                                        </div>
                                    </template>
                                </div>

                                {{-- DRAFT --}}
                                <div x-show="caseTab === 'draft' && detail.draft">
                                    <div class="chips"><span class="badge badge-warning">not sent</span><span class="badge plain" x-text="'template ' + detail.draft?.template_version"></span><span class="badge plain badge-SIMULATED" x-text="detail.draft?.provider"></span><span class="badge plain" x-text="'status ' + detail.draft?.status"></span></div>
                                    <div class="muted" x-text="'Subject: ' + detail.draft?.subject"></div>
                                    <pre class="body" style="margin-top:8px" x-text="detail.draft?.edited_body ?? detail.draft?.body"></pre>
                                    <div class="callout" x-show="detail.draft?.edited_body"><b>Edited by a reviewer.</b> The original draft is kept for feedback.</div>
                                    <h4 style="margin:14px 0 6px" class="muted">Factual claims and their sources</h4>
                                    <table class="simple"><tbody><template x-for="(c, i) in detail.draft?.claims ?? []" :key="i"><tr><td x-text="c.text"></td><td><span class="badge" :class="c.verified ? 'badge-valid' : 'badge-unsupported'" x-text="c.verified ? 'sourced' : 'unverified'"></span> <span class="faint" x-text="(c.evidence_refs[0]?.source_id ?? '')"></span></td></tr></template></tbody></table>
                                    <p class="faint" style="font-size:12.5px">Replies are drafts only: the recipient is always the original sender, set by the server.</p>
                                </div>

                                {{-- ACTIONS & AUDIT --}}
                                <div x-show="caseTab === 'actions'">
                                    <h4 class="muted" style="margin-bottom:6px">Prepared action</h4>
                                    <div class="callout" x-show="detail.proposed"><span class="chip" x-text="detail.proposed?.type"></span> <span class="mono muted" x-text="JSON.stringify(detail.proposed?.params ?? {}).slice(0, 260)"></span></div>
                                    <div class="callout" x-show="!detail.proposed">No action is prepared for this case.</div>
                                    <template x-for="a in detail.actions" :key="a.id">
                                        <div class="card" style="margin:10px 0">
                                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><strong x-text="a.type"></strong><span class="badge" :class="'badge-' + a.status" x-text="a.status"></span><span class="badge plain badge-SIMULATED" x-text="a.runs[0]?.mode ?? 'SIMULATED'"></span><span class="mono faint" x-text="'idem ' + a.idempotency_key"></span></div>
                                            <div class="muted" x-text="'outbox: ' + (a.outbox?.status ?? '–') + ' · attempts ' + (a.outbox?.attempts ?? 0) + ' · by ' + a.requested_by"></div>
                                            <ol class="tl"><template x-for="r in a.runs" :key="r.attempt + r.outcome"><li><span><span x-text="'attempt ' + r.attempt + ': ' + r.outcome"></span><small x-text="JSON.stringify(r.detail ?? {})"></small></span><span class="ms" x-text="r.latency_ms + ' ms'"></span></li></template></ol>
                                            <button class="btn btn-sm" x-show="detail.can.reconcile" @click="reconcile()">Reconcile now (ask the provider before any re-send)</button>
                                        </div>
                                    </template>
                                    <h4 class="muted" style="margin:16px 0 6px">Review decisions &amp; corrections (append-only)</h4>
                                    <table class="simple"><tbody>
                                        <template x-for="r in detail.reviews" :key="r.at + r.decision"><tr><td x-text="time(r.at)"></td><td><span class="badge" :class="r.decision === 'rejected' ? 'badge-rejected' : 'badge-executed'" x-text="r.decision"></span></td><td x-text="r.actor + (r.reason ? ' · ' + r.reason : '')"></td><td class="faint" x-text="'v' + r.case_version"></td></tr></template>
                                        <template x-for="c in detail.corrections" :key="c.at + c.field"><tr><td x-text="time(c.at)"></td><td><span class="badge badge-medium">corrected</span></td><td class="val"><span x-text="c.field"></span>: <s x-text="c.original ?? '∅'"></s> → <span class="corrected" x-text="c.corrected ?? '∅'"></span></td><td class="faint" x-text="c.actor + ' · ' + (c.category ?? '')"></td></tr></template>
                                    </tbody></table>
                                    <h4 class="muted" style="margin:16px 0 6px">Audit trail</h4>
                                    <ol class="tl"><template x-for="a in detail.audit" :key="a.at + a.event + a.to"><li><span><span x-text="a.event"></span> <span class="mono" x-show="a.to" x-text="(a.from ?? '·') + ' → ' + a.to"></span><small x-text="a.actor + ' · v' + a.version + ' · ' + time(a.at)"></small></span></li></template></ol>
                                </div>
                            </div>

                            <div class="actionbar">
                                <button class="btn btn-ok" :disabled="busy || !detail.can.approve" @click="approve()">Approve</button>
                                <button class="btn" :disabled="busy || !detail.can.correct" @click="openCorrect()">Correct</button>
                                <button class="btn btn-danger" :disabled="busy || !detail.can.reject" @click="openReject()">Reject</button>
                                <button class="btn btn-sm btn-ghost" :disabled="busy || !detail.can.reprocess" @click="reprocess()">Reprocess</button>
                                <span class="muted" style="font-size:12.5px" x-show="!detail.can.approve && detail.state === 'blocked'">Blocked by policy: approval is unavailable.</span>
                                <span class="muted" style="font-size:12.5px" x-show="detail.state === 'executed'">Done · <span x-text="detail.auto_processed ? 'auto-processed by policy' : 'approved by a reviewer'"></span> · SIMULATED</span>
                                <span class="muted mono" style="margin-left:auto;font-size:12px" x-text="'v' + detail.version"></span>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- trace --}}
                <div class="pane trace" :data-mobile="pane === 'trace' ? 'show' : 'hidden'">
                    <div class="pane-head"><span class="pane-title">Decision Trace</span><span class="badge plain" x-show="trace" x-text="trace?.total_ms + ' ms total'"></span></div>
                    <template x-if="trace && detail">
                        <div class="pane-body">
                            <div class="trace-section"><h4>Identity</h4>
                                <dl class="kv"><dt>correlation</dt><dd class="mono" x-text="trace.correlation_id"></dd><dt>case</dt><dd class="mono" x-text="trace.case_id"></dd><dt>job attempts</dt><dd x-text="trace.attempts + 1"></dd><dt>duplicates</dt><dd x-text="trace.duplicates + ' suppressed'"></dd></dl></div>
                            <div class="trace-section"><h4>Models &amp; versions</h4>
                                <dl class="kv">
                                    <dt>classifier</dt><dd><span x-text="trace.providers.classification ? trace.providers.classification.provider + ' / ' + trace.providers.classification.model : '–'"></span> <span class="badge plain badge-SIMULATED" x-show="trace.providers.classification?.provider === 'heuristic'">simulated</span></dd>
                                    <dt>extractor</dt><dd x-text="trace.providers.extraction ? trace.providers.extraction.provider + ' / ' + trace.providers.extraction.model : '–'"></dd>
                                    <dt>taxonomy</dt><dd x-text="trace.versions.taxonomy ?? '–'"></dd><dt>prompts</dt><dd x-text="(trace.versions.classify_prompt ?? '–') + ' · ' + (trace.versions.extract_prompt ?? '–')"></dd>
                                    <dt>schema</dt><dd x-text="trace.versions.schema ?? '–'"></dd><dt>confidence</dt><dd x-text="trace.versions.confidence_formula"></dd>
                                    <dt>validation</dt><dd x-text="trace.versions.validation_rules"></dd><dt>policy</dt><dd x-text="trace.versions.policy ?? '–'"></dd>
                                </dl></div>
                            <div class="trace-section"><h4>Stages</h4>
                                <ol class="tl"><template x-for="s in trace.stages" :key="s.at + s.stage + s.duration_ms"><li><span><span x-text="s.stage"></span> <span class="badge plain" :class="'badge-' + (s.status === 'ok' ? 'ok' : 'FALLBACK')" x-show="s.status !== 'ok'" x-text="s.status"></span><span class="badge plain" x-show="s.retries > 0" x-text="s.retries + ' retries'"></span><small x-show="s.detail && (s.detail.reasons || s.detail.issues || s.detail.error || s.detail.label)" x-text="(s.detail?.label ? 'label ' + s.detail.label + ' ' : '') + ((s.detail?.reasons ?? s.detail?.issues ?? []).join(', ')) + (s.detail?.error ?? '')"></small></span><span class="ms" x-text="s.duration_ms + ' ms'"></span></li></template></ol></div>
                            <div class="trace-section" x-show="trace.provider_calls.length"><h4>Provider calls</h4>
                                <ol class="tl"><template x-for="(c, i) in trace.provider_calls" :key="i"><li><span><span x-text="c.stage + ' #' + c.attempt"></span> <span class="badge plain" :class="'badge-' + (c.status === 'ok' ? 'ok' : 'FALLBACK')" x-text="c.status"></span><small x-text="c.provider + ' / ' + c.model + (c.error ? ' · ' + c.error : '')"></small></span><span class="ms" x-text="c.latency_ms + ' ms'"></span></li></template></ol></div>
                            <div class="trace-section" x-show="detail.confidence"><h4>Confidence signals <span class="faint" style="text-transform:none">(<span x-text="detail.confidence?.formula"></span>)</span></h4>
                                <dl class="kv"><dt>classification</dt><dd x-text="pct(detail.confidence?.signals.classification) + ' model signal'"></dd><dt>extraction</dt><dd x-text="detail.confidence?.signals.extraction !== undefined ? pct(detail.confidence?.signals.extraction) + ' (min of critical fields + mean)' : 'n/a (no schema)'"></dd><dt>overall</dt><dd><b x-text="pct(detail.confidence?.overall)"></b> · <span x-text="detail.confidence?.band"></span></dd></dl>
                                <p class="faint" style="font-size:12px;margin:6px 0 0">Signals from the model, OCR and validation are kept separate; they are not comparable probabilities and the thresholds are tuned on the dev set, not calibrated.</p></div>
                            <div class="trace-section"><h4>Policy</h4>
                                <ol class="tl"><template x-for="(p, i) in detail.policy_history" :key="i"><li><span><span class="badge plain" :class="'badge-' + (p.decision === 'AUTO_PROCESS' ? 'AUTO_ELIGIBLE' : p.decision)" x-text="p.decision"></span> <span x-text="p.policy"></span><small x-text="p.codes.join(', ') + ' · case v' + p.case_version"></small></span></li></template></ol></div>
                            <div class="trace-section"><h4>Usage &amp; cost</h4>
                                <dl class="kv"><dt>tokens</dt><dd x-text="trace.usage.input_tokens + ' in / ' + trace.usage.output_tokens + ' out'"></dd><dt>cost</dt><dd x-text="'$' + Number(trace.usage.estimated_cost_usd).toFixed(4) + ' estimated'"></dd></dl>
                                <p class="faint" style="font-size:12px;margin:6px 0 0" x-text="trace.usage.cost_label"></p></div>
                            <div class="trace-section" x-show="trace.ingestion.length > 1"><h4>Deliveries</h4>
                                <ol class="tl"><template x-for="(i, k) in trace.ingestion" :key="k"><li><span><span class="badge plain" :class="i.outcome === 'duplicate' ? 'badge-FALLBACK' : 'badge-ok'" x-text="i.outcome"></span><small x-text="JSON.stringify(i.detail)"></small></span></li></template></ol></div>
                        </div>
                    </template>
                    <div class="empty" x-show="!trace">The trace for the selected case appears here.</div>
                </div>
            </div>
        </section>

        {{-- ===== APPROVAL QUEUE ===== --}}
        <section x-show="tab === 'queue'" class="page">
            <h2>Approval Queue</h2>
            <p class="muted">Items wait here until a person decides. Financial and customer-facing actions are approval-only; low-risk, reversible ones can be processed by policy.</p>
            <div class="kpis" style="margin-top:14px">
                <button class="kpi ready" :aria-pressed="filter === 'ready'" @click="setFilter('ready')"><div class="n" x-text="counters.ready ?? 0"></div><div class="l">Ready for approval</div></button>
                <button class="kpi review" :aria-pressed="filter === 'needs_review'" @click="setFilter('needs_review')"><div class="n" x-text="counters.needs_review ?? 0"></div><div class="l">Needs review</div></button>
                <button class="kpi blocked" :aria-pressed="filter === 'blocked'" @click="setFilter('blocked')"><div class="n" x-text="counters.blocked ?? 0"></div><div class="l">Blocked</div></button>
                <button class="kpi executed" :aria-pressed="filter === 'executed'" @click="setFilter('executed')"><div class="n" x-text="counters.executed ?? 0"></div><div class="l">Executed (SIMULATED)</div></button>
            </div>
            <div class="queue-tools"><label class="muted">Sort <select x-model="sort"><option value="risk">Risk</option><option value="confidence">Confidence</option><option value="category">Category</option><option value="received">Received</option><option value="sla">SLA</option></select></label>
                <span class="muted" x-text="queue().length + ' items'"></span>
                <span class="faint" style="font-size:12.5px">Bulk approval is disabled in this demo on purpose.</span></div>
            <template x-for="c in queue()" :key="c.id">
                <article class="qcard">
                    <div>
                        <div class="chips" style="margin:0"><span class="badge badge-cat" x-text="human(c.category)"></span><span class="badge" :class="'badge-' + c.state" x-text="stateLabel(c)"></span>
                            <span class="badge" :class="'badge-' + (c.confidence_band ?? 'medium')" x-show="c.overall_signal !== null" x-text="'signal ' + pct(c.overall_signal)"></span>
                            <template x-for="b in c.badges" :key="b"><span class="badge" :class="'badge-' + b" x-text="b"></span></template>
                            <span class="badge plain" x-text="'risk ' + c.risk_band"></span><span class="faint" x-show="c.state === 'awaiting_review'" x-text="'SLA ' + sla(c)"></span></div>
                        <h3 x-text="c.subject"></h3>
                        <div class="muted" x-text="(c.sender.name ?? '') + ' <' + c.sender.email + '>'"></div>
                        <div class="summary"><span x-show="c.issue_count">Validation issues: <b x-text="c.issue_count"></b></span><span x-show="c.reasons.length">Why: <b x-text="c.reasons.filter(r => r !== 'AUTO_POLICY_PASSED').map(human).join(', ') || '–'"></b></span><span x-show="c.attachments">Attachments: <b x-text="c.attachments"></b></span></div>
                        <div class="proposed" x-show="c.proposed_action">Proposed action: <b x-text="human(c.proposed_action)"></b> <span class="faint">(simulated)</span></div>
                    </div>
                    <div class="acts">
                        <button class="btn btn-sm" @click="tab = 'inbox'; select(c.id)">Inspect</button>
                        <button class="btn btn-sm btn-ok" :disabled="busy || c.state !== 'awaiting_review' || c.state === 'blocked'" @click="approve(c.id)">Approve</button>
                        <button class="btn btn-sm" :disabled="busy || !['awaiting_review','needs_review'].includes(c.state)" @click="tab = 'inbox'; openCorrect(c.id)">Correct</button>
                        <button class="btn btn-sm btn-danger" :disabled="busy || !['awaiting_review','needs_review','blocked'].includes(c.state)" @click="tab = 'inbox'; openReject(c.id)">Reject</button>
                    </div>
                </article>
            </template>
            <div class="empty" x-show="!queue().length">Nothing matches. Try another filter, or run a scenario in the Failure Lab.</div>
        </section>

        {{-- ===== FAILURE LAB ===== --}}
        <section x-show="tab === 'lab'" class="page">
            <h2>Failure Lab</h2>
            <p class="muted">Each scenario arms the same deterministic fault adapter the automated tests use, then injects a message. The result is visible in the Decision Trace, and everything can be reset.</p>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
                <button class="btn btn-sm" @click="resetLab()">Reset all faults</button>
                <template x-for="(n, k) in faults" :key="k"><span class="chip" x-text="human(k) + ' ×' + n"></span></template>
                <span class="faint" x-show="!Object.keys(faults).length">No faults armed.</span>
            </div>
            <div class="grid-cards">
                <template x-for="s in scenarios" :key="s.key">
                    <div class="card"><h3 x-text="s.label"></h3><p x-text="s.description"></p><div><button class="btn btn-sm btn-primary" :disabled="busy" @click="runScenario(s)">Run</button></div></div>
                </template>
            </div>
            <h3 style="margin:26px 0 8px">Run log</h3>
            <table class="simple"><tbody><template x-for="(l, i) in labLog" :key="i"><tr><td class="mono" x-text="l.at"></td><td x-text="l.label"></td><td class="mono muted" x-text="JSON.stringify(l.out.armed ?? l.out.outcomes ?? l.out.deliveries ?? '').slice(0, 160)"></td></tr></template></tbody></table>
            <div class="empty" x-show="!labLog.length">Nothing run yet.</div>
        </section>

        {{-- ===== EVALUATION ===== --}}
        <section x-show="tab === 'eval'" class="page">
            <h2>Evaluation</h2>
            <template x-if="evaluation && !evaluation.runs.length"><div class="callout">No evaluation run is stored on this deployment. Run <span class="mono">php artisan eval:run holdout</span>.</div></template>
            <template x-for="r in (evaluation?.runs ?? []).filter(x => x.dataset === 'holdout').slice(0, 1).concat((evaluation?.runs ?? []).filter(x => x.dataset === 'dev').slice(0, 1))" :key="r.id">
                <div style="margin-bottom:40px">
                    <h3 style="margin-top:22px"><span x-text="r.dataset === 'holdout' ? 'Held-out set' : 'Dev (tuning) set'"></span> · <span x-text="r.cases + ' cases'"></span> <span class="badge" :class="r.simulated ? 'badge-SIMULATED' : 'badge-LIVE'" x-text="r.simulated ? 'SIMULATED heuristic baseline' : 'LIVE ' + r.provider"></span></h3>
                    <div class="faint mono" x-text="'policy ' + r.policy + ' · auto_min ' + r.config.auto_min + ' · ' + JSON.stringify(r.config.composition)"></div>
                    <div class="metric-grid">
                        <div class="metric"><div class="n" x-text="pct(r.metrics.classification.macro_f1)"></div><div class="l">macro F1 (classification)</div></div>
                        <div class="metric"><div class="n" x-text="pct(r.metrics.extraction.critical_field_accuracy)"></div><div class="l">critical field accuracy</div></div>
                        <div class="metric"><div class="n" x-text="pct(r.metrics.extraction.document_exactness)"></div><div class="l">documents with all critical fields right</div></div>
                        <div class="metric"><div class="n" x-text="pct(r.metrics.policy.automation_coverage)"></div><div class="l">automation coverage</div></div>
                        <div class="metric"><div class="n" x-text="r.metrics.policy.auto_precision === null ? '–' : pct(r.metrics.policy.auto_precision)"></div><div class="l">precision of auto-processed</div></div>
                        <div class="metric"><div class="n" :style="r.metrics.policy.false_auto_actions > 0 ? 'color:var(--danger)' : ''" x-text="r.metrics.policy.false_auto_actions"></div><div class="l">false auto actions</div></div>
                        <div class="metric"><div class="n" x-text="pct(r.metrics.policy.review_rate)"></div><div class="l">review rate</div></div>
                        <div class="metric"><div class="n" x-text="r.metrics.policy.unsafe_blocked + '/' + r.metrics.policy.unsafe_total"></div><div class="l">unsafe cases blocked</div></div>
                        <div class="metric"><div class="n" x-text="r.metrics.system.latency_ms_p50 + ' / ' + r.metrics.system.latency_ms_p95 + ' ms'"></div><div class="l">p50 / p95 pipeline latency</div></div>
                        <div class="metric"><div class="n" x-text="'$' + Number(r.metrics.system.cost_usd_median ?? 0).toFixed(4)"></div><div class="l">median est. cost / case</div></div>
                    </div>
                    <div class="callout" x-show="r.metrics.policy.false_auto_actions > 0"><b>False auto actions are not hidden:</b> <span class="mono" x-text="r.metrics.policy.false_auto_keys.join(', ')"></span>. Raise the threshold or restrict automatic actions.</div>
                    <div class="callout" x-text="r.metrics.system.note"></div>
                    <h4 class="muted">Per-class results</h4>
                    <table class="simple"><thead><tr><th>Class</th><th>Precision</th><th>Recall</th><th>F1</th><th>Support</th></tr></thead><tbody>
                        <template x-for="(v, k) in r.metrics.classification.per_class" :key="k"><tr><td x-text="human(k)"></td><td x-text="pct(v.precision)"></td><td x-text="pct(v.recall)"></td><td x-text="pct(v.f1)"></td><td x-text="v.support"></td></tr></template></tbody></table>
                    <h4 class="muted" style="margin-top:18px">Field accuracy (exact match on normalized values)</h4>
                    <table class="simple"><tbody><template x-for="(v, k) in r.metrics.extraction.by_field" :key="k"><tr><td class="mono" x-text="k"></td><td style="width:40%"><div class="bar" :class="level(v.accuracy)"><i :style="'width:' + (v.accuracy * 100) + '%'"></i></div></td><td x-text="pct(v.accuracy) + ' (n=' + v.n + ')'"></td></tr></template></tbody></table>
                    <p class="muted" style="font-size:12.5px" x-text="'Invoice documents by medium (all critical fields right): PDF ' + pct(r.metrics.extraction.invoice_critical_by_medium.pdf) + ' · photo ' + pct(r.metrics.extraction.invoice_critical_by_medium.photo) + ' · scan ' + pct(r.metrics.extraction.invoice_critical_by_medium.scan) + '. Photo and scan misreads are deliberate and are what the review step exists for.'"></p>
                    <p class="muted" style="font-size:12.5px" x-text="'Evidence coverage ' + pct(r.metrics.extraction.evidence_coverage) + '. ' + r.metrics.extraction.evidence_validity_note"></p>
                    <h4 class="muted" style="margin-top:18px">Threshold trade-off (coverage vs. false auto actions)</h4>
                    <svg class="chart" viewBox="0 0 540 210" role="img" aria-label="Automation coverage and false auto actions by threshold">
                        <g><line class="grid" x1="34" y1="176" x2="530" y2="176"/><line class="grid" x1="34" y1="12" x2="34" y2="176"/></g>
                        <path :d="sweepPath(r, 'coverage')" fill="none" stroke="var(--info)" stroke-width="2"/>
                        <path :d="sweepPath(r, 'false_auto')" fill="none" stroke="var(--danger)" stroke-width="2" stroke-dasharray="5 4"/>
                        <text x="36" y="196">0.50</text><text x="470" y="196">0.99 threshold</text>
                        <text x="44" y="24" fill="var(--info)" style="fill:var(--info)">— coverage</text><text x="130" y="24" style="fill:var(--danger)">- - false auto actions</text>
                    </svg>
                    <table class="simple"><thead><tr><th>Threshold</th><th>Coverage</th><th>Auto-processed</th><th>Precision</th><th>False autos</th></tr></thead><tbody>
                        <template x-for="p in r.metrics.sweep.filter(p => [0.5,0.6,0.7,0.75,0.8,0.85,0.9,0.95].includes(p.threshold))" :key="p.threshold"><tr><td x-text="p.threshold"></td><td x-text="pct(p.coverage)"></td><td x-text="p.auto_count"></td><td x-text="p.precision === null ? '–' : pct(p.precision)"></td><td x-text="p.false_auto"></td></tr></template></tbody></table>
                </div>
            </template>
            <div class="callout"><b>How to read this.</b> The corpus is synthetic and the engine is a deterministic baseline, so these numbers describe this lab, not any real inbox. Dev results were used to pick the automation threshold; the held-out set was run afterwards. When a real model is configured, a separate run is stored with its own provider label.</div>
        </section>

        {{-- ===== PROVIDERS & DATA ===== --}}
        <section x-show="tab === 'caps'" class="page">
            <h2>Providers &amp; data</h2>
            <template x-if="capabilities">
                <div>
                    <p class="muted">Configured provider: <span class="chip" x-text="capabilities.configured_provider"></span>. The matrix describes each adapter as implemented. “Verified” says whether it has run against the real API.</p>
                    <table class="simple"><thead><tr><th>Provider</th><th>Image input</th><th>Structured output</th><th>Usage reporting</th><th>Verified</th></tr></thead><tbody>
                        <template x-for="(p, k) in capabilities.matrix" :key="k"><tr><td><b x-text="p.label"></b> <span class="badge plain badge-SIMULATED" x-show="p.simulated">simulated</span></td><td x-text="p.image_input ? 'yes' : 'no'"></td><td x-text="p.structured_output"></td><td x-text="p.usage_reporting"></td><td x-text="p.verified"></td></tr></template></tbody></table>
                    <h3 style="margin:22px 0 6px">Badges</h3>
                    <table class="simple"><tbody><template x-for="(d, k) in capabilities.labels" :key="k"><tr><td><span class="badge" :class="'badge-' + k" x-text="k"></span></td><td x-text="d"></td></tr></template></tbody></table>
                </div>
            </template>
            <h3 style="margin:22px 0 6px">What data goes where</h3>
            <div class="callout">By default nothing leaves this server: the heuristic baseline runs in-process and OCR is simulated. If a real provider is configured by the operator, only the sanitised subject, body text and extracted attachment text (and images for vision) of synthetic messages are sent to that provider, with the retention terms of the operator’s API account. Uploaded data is deleted when the session expires or when you press “Delete demo data now”.</div>
            <button class="btn btn-sm" @click="openTab('usage')">Show this session's estimated usage</button>
            <template x-if="usage"><dl class="kv" style="margin-top:10px;max-width:520px"><dt>calls</dt><dd x-text="usage.session.calls"></dd><dt>tokens</dt><dd x-text="usage.session.input_tokens + ' in / ' + usage.session.output_tokens + ' out'"></dd><dt>cost</dt><dd x-text="'$' + usage.session.estimated_cost_usd + ' (estimated)'"></dd><dt>daily budget</dt><dd x-text="'$' + usage.daily_budget_usd + ' · remaining $' + usage.budget_remaining_usd"></dd><dt>pricing</dt><dd x-text="usage.pricing_version"></dd></dl></template>
        </section>

        {{-- ===== MODALS ===== --}}
        <div class="overlay" x-show="modal === 'correct'" x-cloak @keydown.escape.window="modal = null" role="dialog" aria-modal="true" aria-labelledby="ctitle">
            <div class="modal" @click.outside="modal = null">
                <h3 id="ctitle">Correct this case</h3>
                <p class="muted" style="margin:0 0 6px">Your corrections change the <b>approved</b> value only. The original prediction stays in the audit; validation and policy run again.</p>
                <div class="field" x-show="detail?.category === 'other_or_uncertain' || form.category !== detail?.category"><label>Category</label>
                    <select x-model="form.category"><template x-for="c in categories" :key="c"><option :value="c" x-text="human(c)"></option></template></select>
                    <div class="faint" style="font-size:12px;margin-top:4px">Choosing a different category continues the pipeline with your label.</div></div>
                <template x-for="f in detail?.extraction?.fields ?? []" :key="f.name">
                    <div class="field"><label :for="'f-' + f.name" x-text="f.name + (f.critical ? ' ★' : '') + (f.type === 'list' ? ' (one item per line)' : '')"></label>
                        <template x-if="f.type !== 'list'"><input type="text" :id="'f-' + f.name" x-model="form.fields[f.name]"></template>
                        <template x-if="f.type === 'list'"><textarea :id="'f-' + f.name" x-model="form.fields[f.name]"></textarea></template>
                        <div class="faint mono" style="font-size:11.5px" x-show="f.predicted !== (form.fields[f.name] ?? '')" x-text="'predicted: ' + (f.predicted ?? '∅')"></div></div>
                </template>
                <div class="field" x-show="detail?.draft"><label>Draft reply (the original is kept as feedback)</label><textarea x-model="form.draft" style="min-height:140px"></textarea></div>
                <div class="field"><label>Error category</label><select x-model="form.errorCategory"><template x-for="c in errorCategories" :key="c"><option :value="c" x-text="human(c)"></option></template></select></div>
                <div class="field"><label>Reason (optional)</label><input type="text" x-model="form.reason" maxlength="400"></div>
                <div class="foot"><button class="btn" @click="modal = null">Cancel</button><button class="btn btn-primary" :disabled="busy" @click="submitCorrect()">Save correction</button></div>
            </div>
        </div>
        <div class="overlay" x-show="modal === 'reject'" x-cloak @keydown.escape.window="modal = null" role="dialog" aria-modal="true" aria-labelledby="rtitle">
            <div class="modal" @click.outside="modal = null">
                <h3 id="rtitle">Reject this case</h3>
                <p class="muted">No prepared action can run after a rejection.</p>
                <div class="field"><label>Reason (required)</label><select x-model="form.rejectReason"><template x-for="r in rejectReasons" :key="r"><option :value="r" x-text="human(r)"></option></template></select></div>
                <div class="field"><label>Note (optional)</label><input type="text" x-model="form.note" maxlength="400"></div>
                <div class="foot"><button class="btn" @click="modal = null">Cancel</button><button class="btn btn-danger" :disabled="busy" @click="submitReject()">Reject</button></div>
            </div>
        </div>
    </div>

    <div class="toasts" aria-live="polite"><template x-for="t in toasts" :key="t.id"><div class="toast" :class="t.kind" x-text="t.message"></div></template></div>
</div>
@endsection
