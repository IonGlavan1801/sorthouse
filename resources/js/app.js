import Alpine from 'alpinejs';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function api(method, url, body, reviewer = 'a') {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Reviewer': reviewer, 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let data = null;
    try { data = await res.json(); } catch { /* empty body */ }
    if (!res.ok) {
        const err = new Error(data?.message || `HTTP ${res.status}`);
        err.status = res.status; err.data = data;
        throw err;
    }
    return data;
}

window.sorthouseLaunch = async function () {
    const res = await api('POST', '/api/demo/sessions');
    window.location.href = '/app';
    return res;
};

const STAGES = [
    ['received', 'received'], ['sanitize', 'sanitized'], ['classify', 'classified'], ['attachments', 'attachments'],
    ['extract', 'extracted'], ['validate', 'validated'], ['policy', 'policy checked'], ['review', 'review / ready'],
];
const IN_FLIGHT = ['received', 'sanitizing', 'processing', 'classified', 'extracting', 'validating', 'awaiting_policy', 'failed_retryable', 'approved', 'queued_action', 'executing'];
const RISK_ORDER = { blocked: 0, high: 1, medium: 2, low: 3 };

Alpine.data('sorthouse', () => ({
    // ---- state
    ready: false, noSession: false, session: null, cases: [], counters: {}, selectedId: null, detail: null, trace: null,
    tab: 'inbox', pane: 'list', caseTab: 'fields', sort: 'risk', filter: 'all', reviewer: 'a', busy: false, toasts: [],
    modal: null, form: { fields: {}, category: '', reason: '', errorCategory: 'extraction_wrong', draft: '', rejectReason: 'not_actionable', note: '' }, lastChange: null,
    activeField: null, activeEvidence: null, scenarios: [], faults: {}, labLog: [], evaluation: null, capabilities: null, usage: null, events: [], timer: null, now: Date.now(),
    rejectReasons: ['not_actionable', 'wrong_category', 'wrong_extraction', 'duplicate', 'suspected_fraud', 'policy_violation'],
    errorCategories: ['classification_wrong', 'extraction_wrong', 'normalization_wrong', 'validation_rule_wrong', 'evidence_wrong', 'policy_too_strict', 'policy_too_permissive'],
    categories: ['invoice', 'customer_complaint', 'request_for_quote', 'order_or_purchase_request', 'general_question', 'spam_or_phishing', 'other_or_uncertain'],

    // ---- boot
    async init() {
        try {
            this.session = await this.call('GET', '/api/demo/sessions/current');
            await Promise.all([this.refresh(), this.loadScenarios()]);
            this.ready = true;
            this.selectFirst();
        } catch (e) {
            if (e.status === 401) { this.noSession = true; this.ready = true; return; }
            this.toast('Could not load the demo: ' + e.message, 'err');
            this.ready = true;
        }
        this.schedulePoll();
        setInterval(() => { this.now = Date.now(); }, 1000);
    },

    async call(method, url, body) { return api(method, url, body, this.reviewer); },

    schedulePoll() {
        clearTimeout(this.timer);
        const busy = this.cases.some((c) => IN_FLIGHT.includes(c.state));
        this.timer = setTimeout(async () => { await this.refresh(true); this.schedulePoll(); }, busy ? 1500 : 6000);
    },

    async refresh(quiet = false) {
        if (!this.session) return;
        try {
            const inbox = await this.call('GET', `/api/demo/sessions/${this.session.session_id}/inbox`);
            this.cases = inbox.cases; this.counters = inbox.counters;
            const ev = await this.call('GET', `/api/demo/sessions/${this.session.session_id}/events`);
            this.faults = ev.faults; this.events = ev.events;
            if (this.selectedId) await this.loadDetail(this.selectedId, true);
        } catch (e) {
            if (e.status === 401) { this.noSession = true; return; }
            if (!quiet) this.toast(e.message, 'err');
        }
    },

    selectFirst() {
        if (this.selectedId || !this.cases.length) return;
        const first = this.sorted(this.cases)[0];
        if (first && window.innerWidth > 760) this.select(first.id);
    },

    async select(id) {
        this.selectedId = id; this.activeField = null; this.activeEvidence = null; this.caseTab = 'fields'; this.pane = 'case';
        await this.loadDetail(id);
    },

    async loadDetail(id, quiet = false) {
        try {
            const [detail, trace] = await Promise.all([this.call('GET', `/api/triage-cases/${id}`), this.call('GET', `/api/triage-cases/${id}/trace`)]);
            if (this.selectedId !== id) return;
            this.detail = detail; this.trace = trace;
        } catch (e) { if (!quiet) this.toast(e.message, 'err'); }
    },

    // ---- derived
    sorted(list) {
        const key = this.sort;
        let rows = list.filter((c) => this.matches(c));
        const by = {
            risk: (a, b) => (RISK_ORDER[a.risk_band] ?? 9) - (RISK_ORDER[b.risk_band] ?? 9) || (a.overall_signal ?? 1) - (b.overall_signal ?? 1),
            confidence: (a, b) => (a.overall_signal ?? 1) - (b.overall_signal ?? 1),
            category: (a, b) => String(a.category).localeCompare(String(b.category)),
            received: (a, b) => new Date(b.received_at) - new Date(a.received_at),
            sla: (a, b) => new Date(a.due_at) - new Date(b.due_at),
        }[key];
        return rows.sort(by);
    },
    matches(c) {
        switch (this.filter) {
            case 'ready': return c.state === 'awaiting_review' && c.issue_count === 0;
            case 'needs_review': return (c.state === 'awaiting_review' && c.issue_count > 0) || c.state === 'needs_review';
            case 'blocked': return c.state === 'blocked';
            case 'executed': return c.state === 'executed';
            default: return true;
        }
    },
    queue() { return this.sorted(this.cases.filter((c) => ['awaiting_review', 'needs_review', 'blocked', 'executed', 'pending_reconciliation', 'queued_action', 'execution_failed'].includes(c.state) || c.auto_processed)); },
    setFilter(f) { this.filter = this.filter === f ? 'all' : f; },
    pct(v) { return v === null || v === undefined ? '–' : Math.round(v * 100) + '%'; },
    level(v) { return v >= 0.85 ? 'high' : v >= 0.6 ? 'mid' : 'low'; },
    human(s) { return s ? String(s).replaceAll('_', ' ') : '–'; },
    time(iso) { return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''; },
    ttl() { if (!this.session?.expires_at) return ''; const s = Math.max(0, Math.round((new Date(this.session.expires_at) - this.now) / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; },
    sla(c) { const m = Math.round((new Date(c.due_at) - this.now) / 60000); return m < 0 ? `overdue ${-m}m` : `${m}m left`; },
    stateLabel(c) { return c.auto_processed && c.state === 'executed' ? 'auto-processed' : this.human(c.state); },
    isBusyState(c) { return c && IN_FLIGHT.includes(c.state); },
    stageStatus(key) {
        if (!this.trace) return '';
        const rows = this.trace.stages.filter((s) => s.stage === key);
        if (key === 'received') return 'done';
        if (key === 'review') return ['awaiting_review', 'approved', 'queued_action', 'executing', 'executed', 'rejected', 'needs_review', 'blocked'].includes(this.detail?.state) ? (this.detail.state === 'blocked' ? 'fail' : this.detail.state === 'needs_review' ? 'warn' : 'done') : '';
        if (!rows.length) return '';
        const last = rows[rows.length - 1];
        return last.status === 'failed' ? 'fail' : (last.status === 'fallback' ? 'warn' : 'done');
    },
    stageMs(key) { const r = this.trace?.stages.filter((s) => s.stage === key) ?? []; return r.length ? r.reduce((a, s) => a + s.duration_ms, 0) + ' ms' : ''; },
    get stages() { return STAGES; },
    fieldByName(n) { return this.detail?.extraction?.fields.find((f) => f.name === n); },
    reasons() { return this.detail?.policy?.reasons ?? []; },
    whyTitle() {
        const r = this.detail?.route;
        return r === 'BLOCKED' ? 'Why blocked?' : r === 'AUTO_ELIGIBLE' ? 'Why automated?' : 'Why review?';
    },

    // ---- evidence viewer
    attachment() { return this.detail?.message.attachments.find((a) => a.scan_status === 'clean') ?? this.detail?.message.attachments[0]; },
    imageSrc(a) { return `/api/triage-cases/${this.detail.id}/attachments/${a.id}`; },
    sourceText(sourceId) {
        if (sourceId === 'msg:body') return this.detail.message.text ?? '';
        for (const a of this.detail.message.attachments) { const s = a.sources.find((x) => x.id === sourceId); if (s) return s.text; }
        return '';
    },
    openEvidence(field, ev) {
        this.activeField = field.name; this.activeEvidence = ev;
        if (ev.source_id === 'msg:body') this.caseTab = 'message';
    },
    pick(field) { this.activeField = field.name; this.activeEvidence = field.evidence[0] ?? null; },
    parts(sourceId, span) {
        const text = this.sourceText(sourceId);
        if (!span || span[0] === null) return [text, '', ''];
        const chars = Array.from(text);
        return [chars.slice(0, span[0]).join(''), chars.slice(span[0], span[1]).join(''), chars.slice(span[1]).join('')];
    },
    bboxStyle(ev) { const [x, y, w, h] = ev.bbox; const p = 0.006; return `left:${(x - p) * 100}%;top:${(y - p) * 100}%;width:${(w + 2 * p) * 100}%;height:${(h + 2 * p) * 100}%`; },
    evidenceAttachment() {
        const m = this.activeEvidence?.source_id?.match(/^att:([0-9a-f-]{36}):/);
        return m ? this.detail.message.attachments.find((a) => a.id === m[1]) : null;
    },
    signalBadge(f) { return f.signal_source === 'model' ? 'model signal' : f.signal_source; },

    // ---- review actions
    async guarded(fn) {
        if (this.busy) return; this.busy = true;
        try { await fn(); } catch (e) {
            if (e.status === 409 && e.data?.error === 'version_conflict') { this.toast(e.message, 'warn'); await this.loadDetail(this.selectedId); await this.refresh(true); }
            else if (e.status === 401) { this.noSession = true; }
            else this.toast((e.data?.reasons?.length ? e.data.reasons.join(', ') + ': ' : '') + e.message, 'err');
        } finally { this.busy = false; }
    },
    approve(id = this.selectedId) {
        return this.guarded(async () => {
            const v = id === this.selectedId ? this.detail.version : (await this.call('GET', `/api/triage-cases/${id}`)).version;
            const out = await this.call('POST', `/api/triage-cases/${id}/review/approve`, { expected_version: v });
            if (id === this.selectedId) { this.detail = out; await this.loadDetail(id, true); this.caseTab = 'actions'; }
            this.toast(out.state === 'executed' ? 'Approved → queued → executed (SIMULATED)' : 'Approved: ' + this.human(out.state), 'ok');
            await this.refresh(true);
        });
    },
    async openCorrect(id = this.selectedId) {
        if (id !== this.selectedId) await this.select(id);
        const d = this.detail;
        this.form = { fields: {}, category: d.category, reason: '', errorCategory: 'extraction_wrong', draft: d.draft?.edited_body ?? d.draft?.body ?? '', rejectReason: 'not_actionable', note: '' };
        (d.extraction?.fields ?? []).forEach((f) => { this.form.fields[f.name] = f.effective ?? ''; });
        this.lastChange = null; this.modal = 'correct';
    },
    submitCorrect() {
        return this.guarded(async () => {
            const d = this.detail; const fields = {};
            (d.extraction?.fields ?? []).forEach((f) => { if ((f.effective ?? '') !== (this.form.fields[f.name] ?? '')) fields[f.name] = this.form.fields[f.name]; });
            const payload = { expected_version: d.version, reason: this.form.reason || undefined, error_category: this.form.errorCategory };
            if (Object.keys(fields).length) payload.fields = fields;
            if (this.form.category !== d.category) payload.category = this.form.category;
            const original = d.draft?.edited_body ?? d.draft?.body ?? '';
            if (d.draft && this.form.draft !== original) payload.draft_body = this.form.draft;
            if (!payload.fields && !payload.category && !payload.draft_body) { this.toast('Nothing changed.', 'warn'); return; }
            const out = await this.call('POST', `/api/triage-cases/${d.id}/review/correct`, payload);
            this.lastChange = { ...out.change, fields: Object.keys(fields) };
            this.detail = out.case; this.modal = null; this.caseTab = 'fields';
            await this.loadDetail(d.id, true);
            this.toast(out.change.category_changed ? 'Category corrected; the pipeline continues with your label.' : 'Corrected. The original prediction stays in the audit; validation and policy ran again.', 'ok');
            await this.refresh(true);
        });
    },
    openReject(id = this.selectedId) {
        if (id !== this.selectedId) this.select(id);
        this.form.rejectReason = 'not_actionable'; this.form.note = ''; this.modal = 'reject';
    },
    submitReject() {
        return this.guarded(async () => {
            const out = await this.call('POST', `/api/triage-cases/${this.selectedId}/review/reject`, { expected_version: this.detail.version, reason_code: this.form.rejectReason, note: this.form.note || undefined });
            this.detail = out; this.modal = null; this.toast('Rejected. No external action will run.', 'ok'); await this.refresh(true);
        });
    },
    reprocess() {
        return this.guarded(async () => {
            await this.call('POST', `/api/triage-cases/${this.selectedId}/reprocess`);
            this.toast('Reprocessing; earlier runs stay in history.', 'ok'); await this.refresh(true);
        });
    },
    reconcile() {
        return this.guarded(async () => {
            const out = await this.call('POST', `/api/triage-cases/${this.selectedId}/reconcile`);
            this.toast(out.reconciliation === 'reconciled_executed' ? 'Provider already applied it: closed without re-sending.' : 'Provider had no record: safe re-send queued.', 'ok');
            await this.refresh(true);
        });
    },

    // ---- failure lab
    async loadScenarios() { this.scenarios = (await this.call('GET', '/api/demo/scenarios')).scenarios; },
    runScenario(s) {
        return this.guarded(async () => {
            const out = await this.call('POST', `/api/demo/scenarios/${s.key}`);
            this.labLog.unshift({ at: new Date().toLocaleTimeString(), label: s.label, out });
            this.toast(`Scenario "${s.label}" started.`, 'ok');
            await this.refresh(true);
            if (out.case_id) { this.tab = 'inbox'; await this.select(out.case_id); }
        });
    },
    resetLab() { return this.guarded(async () => { await this.call('POST', '/api/demo/scenarios/reset'); this.toast('Failure Lab reset: all armed faults cleared.', 'ok'); await this.refresh(true); }); },
    async inject(key) { return this.guarded(async () => { const out = await this.call('POST', `/api/demo/sessions/${this.session.session_id}/fixtures/${key}/inject`); await this.refresh(true); await this.select(out.case_id); }); },

    // ---- other tabs
    async openTab(t) {
        this.tab = t;
        if (t === 'eval' && !this.evaluation) this.evaluation = await this.call('GET', '/api/evaluation');
        if (t === 'caps' && !this.capabilities) this.capabilities = await this.call('GET', '/api/capabilities');
        if (t === 'queue' || t === 'inbox') this.refresh(true);
        if (t === 'usage') this.usage = await this.call('GET', '/api/usage');
    },
    async deleteNow() {
        if (!confirm('Disable this session now and purge its data? Disabling is immediate; the purge runs asynchronously.')) return;
        await this.call('DELETE', '/api/demo/sessions/current');
        this.noSession = true;
    },
    async relaunch() { await window.sorthouseLaunch(); },

    // ---- toasts
    toast(message, kind = 'info') {
        const t = { id: Math.random(), message, kind }; this.toasts.push(t);
        setTimeout(() => { this.toasts = this.toasts.filter((x) => x.id !== t.id); }, 6000);
    },

    // ---- charts / eval helpers
    run(ds) { return this.evaluation?.runs.find((r) => r.dataset === ds); },
    sweepPath(run, key) {
        const pts = run.metrics.sweep; const w = 520; const h = 190; const pad = 34;
        const x = (t) => pad + ((t - 0.5) / 0.49) * (w - pad - 10);
        const max = key === 'coverage' ? Math.max(0.2, ...pts.map((p) => p.coverage)) : Math.max(3, ...pts.map((p) => p.false_auto));
        const y = (v) => h - pad - (v / max) * (h - pad - 12);
        return pts.map((p, i) => `${i ? 'L' : 'M'}${x(p.threshold).toFixed(1)},${y(key === 'coverage' ? p.coverage : p.false_auto).toFixed(1)}`).join(' ');
    },
}));

window.Alpine = Alpine;
Alpine.start();
