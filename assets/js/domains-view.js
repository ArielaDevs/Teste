/**
 * Domains — one domain's page (domains/view.php).
 */
(function () {
    'use strict';
    const { T, esc, api, fmtDate, grade, daysPill, status, purpose, renewal, tags, finding, money } = window.Dom;
    const ID = window.DOMAIN_ID;
    let D = null, data = null, L = null;

    document.addEventListener('DOMContentLoaded', async () => {
        wire();
        await Promise.all([load(), loadLookups()]);
        const tab = new URLSearchParams(location.search).get('tab');
        if (tab) switchTab(tab);
    });

    async function load() {
        try {
            data = await api('get.php?id=' + ID);
            D = data.domain;
            renderHero(); renderOverview(); renderSecurity(); renderCerts(); renderLookalikes(); renderHistory();
        } catch (e) {
            document.getElementById('hero').innerHTML = '<div class="dom-empty">' + esc(e.message) + '</div>';
        }
    }
    async function loadLookups() {
        try { L = (await api('lookups.php')).lookups; } catch (e) { L = null; }
    }

    // ---------------------------------------------------------------- hero
    function renderHero() {
        const dl = D.days_left;
        let cls = 'green', n = dl, l = T('page.days_left');
        if (dl === null) { n = '?'; cls = ''; l = T('page.expiry_unknown'); }
        else if (dl < 0) { cls = 'red'; n = -dl; l = T('page.days_since'); }
        else if (dl <= 30) cls = dl <= 7 ? 'red' : 'amber';
        document.getElementById('hero').innerHTML =
            '<div>' + grade(D.security_grade, true) + (D.security_score !== null ? '<div class="dom-sub" style="text-align:center">' + esc(D.security_score) + '/100</div>' : '') + '</div>'
            + '<div style="min-width:0"><h1>' + esc(D.display_name || D.domain_name) + '</h1>'
            + (D.display_name ? '<div class="dom-sub">' + esc(D.domain_name) + '</div>' : '')
            + '<div class="meta">' + status(D.status_name, D.status_colour)
            + '<span>' + esc(purpose(D.purpose)) + '</span>'
            + (data.multi_company && D.company_name ? '<span>· ' + esc(D.company_name) + '</span>' : '')
            + (D.owner_name ? '<span>· ' + esc(T('page.owned_by', { name: D.owner_name })) + '</span>' : '<span class="dom-pill amber">' + esc(T('page.no_owner')) + '</span>')
            + (D.monitoring_enabled ? '' : '<span class="dom-pill grey">' + esc(T('page.monitoring_off')) + '</span>')
            + '</div>' + (D.tags ? '<div style="margin-top:6px">' + tags(D.tags) + '</div>' : '') + '</div>'
            + '<div class="count-down"><div class="n ' + cls + '">' + esc(n) + '</div><div class="l">' + esc(l) + '</div>'
            + (D.expiry_date ? '<div class="dom-sub">' + esc(fmtDate(D.expiry_date)) + ' · ' + esc(renewal(D.renewal_mode)) + '</div>' : '') + '</div>';
        document.title = (D.display_name || D.domain_name) + ' - ' + T('title');

        const f = D.findings || [];
        const bad = f.filter(x => x.level === 'fail').length, warn = f.filter(x => x.level === 'warn').length;
        document.getElementById('secBadge').innerHTML = bad ? '<span class="dom-pill red">' + bad + '</span>' : (warn ? '<span class="dom-pill amber">' + warn + '</span>' : '');
        const newCt = (data.ct || []).filter(c => c.acknowledged == 0).length;
        document.getElementById('certBadge').innerHTML = newCt ? '<span class="dom-pill red">' + newCt + '</span>' : '';
        const la = (data.lookalikes || []).filter(x => x.dismissed == 0).length;
        document.getElementById('laBadge').innerHTML = la ? '<span class="dom-pill amber">' + la + '</span>' : '';
    }

    // ---------------------------------------------------------------- overview
    function row(k, v, mono) {
        if (v === null || v === undefined || v === '') v = '<span class="dom-sub">—</span>';
        return '<div class="k">' + esc(T(k)) + '</div><div class="v' + (mono ? ' mono' : '') + '">' + v + '</div>';
    }
    function yn(v) { return v === true ? '<span class="dom-pill green">' + esc(T('lock.on')) + '</span>' : (v === false ? '<span class="dom-pill red">' + esc(T('lock.off')) + '</span>' : '<span class="dom-sub">' + esc(T('lock.unknown')) + '</span>'); }

    function renderOverview() {
        const reg = '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.registration')) + '</h3>'
            + '<span class="dom-sub">' + esc(D.lookup_source ? T('source.' + D.lookup_source) : T('source.manual'))
            + (D.last_lookup_datetime ? ' · ' + esc(window.fmtDateTime ? fmtDateTime(D.last_lookup_datetime.replace(' ', 'T') + 'Z') : D.last_lookup_datetime) : '') + '</span></div>'
            + '<div class="dom-card-b">'
            + (D.last_lookup_error ? '<div class="dom-finding warn" style="margin-bottom:12px"><span class="ic">!</span><div><div class="t">' + esc(T('page.lookup_error')) + '</div><div class="a">' + esc(D.last_lookup_error) + '</div></div></div>' : '')
            + '<div class="dom-fields">'
            + row('field.registrar', esc(D.supplier_name || D.registrar_name || '') + (D.supplier_name && D.registrar_name && D.supplier_name !== D.registrar_name ? '<div class="dom-sub">' + esc(D.registrar_name) + '</div>' : ''))
            + row('field.registrar_account', D.account_name ? '<a href="accounts/?id=' + D.registrar_account_id + '">' + esc(D.account_name) + '</a>' : '')
            + row('field.registration_date', esc(fmtDate(D.registration_date)))
            + row('field.expiry_date', D.expiry_date ? esc(fmtDate(D.expiry_date)) + ' ' + daysPill(D.days_left) : '')
            + row('field.last_renewed_date', esc(fmtDate(D.last_renewed_date)))
            + row('field.renewal_mode', esc(renewal(D.renewal_mode)))
            + row('field.transfer_lock', yn(D.transfer_lock))
            + row('field.registry_lock', D.registry_lock ? yn(true) : '<span class="dom-sub">' + esc(T('page.none')) + '</span>')
            + row('field.dnssec', yn(D.dnssec))
            + row('field.registrant_name', esc(D.registrant_name || ''))
            + row('field.registry_statuses', esc(D.registry_statuses || ''))
            + row('field.auth_code', authCodeCell())
            + '</div></div></div>';

        const dns = '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.dns_web')) + '</h3></div><div class="dom-card-b"><div class="dom-fields">'
            + row('field.nameservers', esc(D.nameservers || ''), true)
            + row('field.dns_provider', esc(D.dns_provider || ''))
            + row('field.hosting_provider', esc(D.hosting_provider || ''))
            + row('field.ssl_expiry', D.ssl_expiry_date ? esc(fmtDate(D.ssl_expiry_date)) + ' ' + daysPill(D.ssl_days_left, 21) + (D.ssl_issuer ? '<div class="dom-sub">' + esc(D.ssl_issuer) + '</div>' : '') : '')
            + row('field.ssl_hosts', esc(D.ssl_hosts || ''))
            + row('field.dkim_selectors', esc(D.dkim_selectors || ''))
            + row('field.last_check', D.last_check_datetime ? esc(window.fmtDateTime ? fmtDateTime(D.last_check_datetime.replace(' ', 'T') + 'Z') : D.last_check_datetime) : '')
            + '</div></div></div>';

        const people = '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.people_money')) + '</h3></div><div class="dom-card-b"><div class="dom-fields">'
            + row('field.owner', esc(D.owner_name || ''))
            + row('field.tech_contact', esc(D.tech_contact_name || ''))
            + (data.multi_company ? row('field.company', esc(D.company_name || '')) : '')
            + row('field.cost', D.cost !== null ? esc(money(D.cost, D.currency)) + (D.billing_years > 1 ? ' <span class="dom-sub">/ ' + esc(T('page.years', { n: D.billing_years })) + '</span>' : '') : '')
            + row('field.cost_centre', esc(D.cost_centre || ''))
            + row('field.contract', D.contract_id ? '<a href="' + esc(window.DOM_API.replace('api/domains/', '') + 'contracts/view.php?id=' + D.contract_id) + '">' + esc(D.contract_label || ('#' + D.contract_id)) + '</a>' : '')
            + row('field.tags', tags(D.tags))
            + '</div>' + (D.notes ? '<div style="margin-top:14px;white-space:pre-wrap;font-size:13px">' + esc(D.notes) + '</div>' : '') + '</div></div>';

        // The three things most worth fixing, on the first tab, so nobody has to go looking.
        const top = (D.findings || []).filter(f => f.level === 'fail' || f.level === 'warn').sort((a, b) => b.weight - a.weight).slice(0, 3);
        const fix = top.length ? '<div class="dom-card" style="grid-column:1/-1"><div class="dom-card-h"><h3>' + esc(T('page.fix_first')) + '</h3>'
            + '<a href="#" data-goto="security" class="dom-sub">' + esc(T('page.see_all_checks')) + '</a></div><div class="dom-card-b"><div class="dom-findings">'
            + top.map(finding).join('') + '</div></div></div>' : '';

        document.getElementById('overview').innerHTML = fix + reg + dns + people;
    }

    function authCodeCell() {
        if (!data.can_auth_codes) return D.auth_code_set ? '<span class="dom-sub">' + esc(T('code.set_hidden')) + '</span>' : '';
        return (D.auth_code_set ? '<span id="codeVal" style="font-family:monospace">••••••••</span> <a href="#" id="codeReveal">' + esc(T('code.reveal')) + '</a> · ' : '')
            + '<a href="#" id="codeChange">' + esc(D.auth_code_set ? T('code.change') : T('code.add')) + '</a>';
    }

    // ---------------------------------------------------------------- security
    const AREAS = ['registration', 'dns', 'email', 'web'];
    function renderSecurity() {
        const f = D.findings || [];
        if (!f.length) {
            document.getElementById('security').innerHTML = '<div class="dom-empty"><h3>' + esc(T('page.not_checked')) + '</h3><p>' + esc(T('page.not_checked_body')) + '</p></div>';
            return;
        }
        const order = { fail: 0, warn: 1, info: 2, pass: 3 };
        const intro = '<div style="display:flex;gap:16px;align-items:center;margin-bottom:18px">' + grade(D.security_grade, true)
            + '<div><div style="font-weight:600">' + esc(T('page.grade_line', { score: D.security_score })) + '</div>'
            + '<div class="dom-sub">' + esc(T('page.grade_explain')) + (D.checked_at ? ' ' + esc(T('page.checked_at', { when: fmtDate(D.checked_at) })) : '') + '</div></div></div>';
        document.getElementById('security').innerHTML = intro + AREAS.map(a => {
            const items = f.filter(x => x.area === a).sort((x, y) => order[x.level] - order[y.level]);
            if (!items.length) return '';
            return '<div class="dom-area-h">' + esc(T('area.' + a)) + '</div><div class="dom-findings">' + items.map(finding).join('') + '</div>';
        }).join('');
    }

    // ---------------------------------------------------------------- certificates
    function renderCerts() {
        const live = D.certificates || [];
        const liveHtml = live.length ? '<table class="dom-table"><thead><tr><th class="nosort">' + esc(T('cert.host')) + '</th><th class="nosort">' + esc(T('cert.issuer')) + '</th><th class="nosort">' + esc(T('cert.expires')) + '</th><th class="nosort">' + esc(T('cert.names')) + '</th><th class="nosort">' + esc(T('cert.trusted')) + '</th></tr></thead><tbody>'
            + live.map(c => '<tr style="cursor:default"><td>' + esc(c.host) + '</td><td>' + esc(c.ok ? (c.issuer || '') : '—') + '</td><td>'
                + (c.ok ? esc(fmtDate(c.valid_to)) + ' ' + daysPill(c.days_left, 21) : '<span class="dom-sub">' + esc(c.error || '') + '</span>') + '</td><td class="dom-sub">'
                + esc((c.sans || []).slice(0, 6).join(', ')) + ((c.sans || []).length > 6 ? '…' : '') + '</td><td>'
                + (c.ok ? (c.hostname_match === false ? '<span class="dom-pill red">' + esc(T('cert.wrong_name')) + '</span>' : (c.chain_valid === false ? '<span class="dom-pill red">' + esc(T('cert.untrusted')) + '</span>' : '<span class="dom-pill green">' + esc(T('cert.ok')) + '</span>')) : '')
                + '</td></tr>').join('') + '</tbody></table>'
            : '<div class="dom-sub">' + esc(T('cert.none_live')) + '</div>';

        const ct = data.ct || [];
        const newOnes = ct.filter(c => c.acknowledged == 0).length;
        const last = (data.last_scans || {}).ct_scanned;
        const ctHtml = '<div style="display:flex;align-items:center;gap:8px;margin:22px 0 10px"><h3 style="margin:0;font-size:15px">' + esc(T('cert.ct_title')) + '</h3>'
            + '<span class="dom-sub">' + esc(last ? T('cert.last_scan', { date: fmtDate(last) }) : T('cert.never_scanned')) + '</span><span style="flex:1"></span>'
            + (newOnes ? '<button type="button" class="dom-btn" id="ctAck">' + esc(T('cert.ack_all')) + '</button>' : '')
            + '<button type="button" class="dom-btn" id="ctScan">' + esc(T('cert.scan_now')) + '</button></div>'
            + '<p class="dom-hint" style="font-size:12.5px">' + esc(T('cert.ct_explain')) + '</p>'
            + (ct.length ? '<table class="dom-table"><thead><tr><th class="nosort"></th><th class="nosort">' + esc(T('cert.names')) + '</th><th class="nosort">' + esc(T('cert.issuer')) + '</th><th class="nosort">' + esc(T('cert.issued')) + '</th><th class="nosort">' + esc(T('cert.expires')) + '</th></tr></thead><tbody>'
                + ct.map(c => '<tr style="cursor:default"><td>' + (c.acknowledged == 0 ? '<span class="dom-pill red">' + esc(T('cert.new')) + '</span>' : '') + '</td><td>'
                    + esc(String(c.name_value || c.common_name || '').split('\n').slice(0, 4).join(', ')) + '</td><td>' + esc(c.issuer) + '</td><td>' + esc(fmtDate(c.not_before)) + '</td><td>' + esc(fmtDate(c.not_after)) + '</td></tr>').join('')
                + '</tbody></table>' : '');
        document.getElementById('certs').innerHTML = '<h3 style="margin:0 0 10px;font-size:15px">' + esc(T('cert.live_title')) + '</h3>' + liveHtml + ctHtml;
    }

    // ---------------------------------------------------------------- look-alikes
    function renderLookalikes() {
        const la = data.lookalikes || [];
        const last = (data.last_scans || {}).lookalikes_scanned;
        let html = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><span class="dom-sub">'
            + esc(last ? T('la.last_scan', { date: fmtDate(last) }) : T('la.never_scanned')) + '</span><span style="flex:1"></span>'
            + '<button type="button" class="dom-btn" id="laScan">' + esc(T('la.scan_now')) + '</button></div>'
            + '<p class="dom-hint" style="font-size:12.5px">' + esc(T('la.explain')) + '</p>';
        if (!la.length) {
            html += '<div class="dom-empty">' + esc(last ? T('la.none_found') : T('la.not_scanned')) + '</div>';
        } else {
            html += '<table class="dom-table"><thead><tr><th class="nosort">' + esc(T('la.name')) + '</th><th class="nosort">' + esc(T('la.technique')) + '</th><th class="nosort">' + esc(T('la.web')) + '</th><th class="nosort">' + esc(T('la.mail')) + '</th><th class="nosort">' + esc(T('la.first_seen')) + '</th><th class="nosort"></th></tr></thead><tbody>'
                + la.map(x => '<tr style="cursor:default;' + (x.dismissed == 1 ? 'opacity:.5' : '') + '"><td><strong>' + esc(x.lookalike) + '</strong></td><td>' + esc(T('la.tech.' + x.technique)) + '</td>'
                    + '<td>' + (x.has_a == 1 ? '<span class="dom-pill amber">' + esc(window.t('common.yes')) + '</span>' : '') + '</td>'
                    + '<td>' + (x.has_mx == 1 ? '<span class="dom-pill red">' + esc(T('la.receives_mail')) + '</span>' : '') + '</td>'
                    + '<td class="dom-sub">' + esc(fmtDate(x.first_seen_datetime)) + '</td>'
                    + '<td><a href="#" data-la="' + x.id + '" data-dismissed="' + (x.dismissed == 1 ? 0 : 1) + '">' + esc(x.dismissed == 1 ? T('la.restore') : T('la.dismiss')) + '</a></td></tr>').join('')
                + '</tbody></table>';
        }
        document.getElementById('lookalikes').innerHTML = html;
    }

    // ---------------------------------------------------------------- history
    function renderHistory() {
        const h = data.history || [];
        if (!h.length) { document.getElementById('history').innerHTML = '<div class="dom-empty">' + esc(T('hist.none')) + '</div>'; return; }
        document.getElementById('history').innerHTML = h.map(x => {
            const who = x.analyst_name ? esc(x.analyst_name) : esc(T('source.' + x.source));
            const label = T('hist.f.' + x.field_name);
            const name = label === 'domains.hist.f.' + x.field_name ? x.field_name : label;
            let chg = '';
            if (x.field_name === 'domain_created') chg = esc(T('hist.created'));
            else if (x.field_name === 'auth_code_viewed') chg = esc(T('hist.viewed_code'));
            else if (x.old_value !== null || x.new_value !== null) {
                chg = (x.old_value !== null && x.old_value !== '' ? '<del>' + esc(x.old_value) + '</del> → ' : '') + '<ins>' + esc(x.new_value === null || x.new_value === '' ? T('hist.blank') : x.new_value) + '</ins>';
            }
            return '<div class="dom-tl"><div class="when">' + esc(window.fmtDateTime ? fmtDateTime(x.created_datetime.replace(' ', 'T') + 'Z') : x.created_datetime) + '</div>'
                + '<div class="what"><strong>' + esc(name) + '</strong><span class="src">' + who + '</span><div class="chg">' + chg + '</div></div></div>';
        }).join('');
    }

    // ---------------------------------------------------------------- actions
    function switchTab(tab) {
        document.querySelectorAll('#domTabs .tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tab));
    }

    async function busy(btn, fn) {
        const was = btn.innerHTML; btn.disabled = true; btn.innerHTML = esc(T('page.working'));
        try { await fn(); } catch (e) { showToast(e.message, 'error'); } finally { btn.disabled = false; btn.innerHTML = was; }
    }

    function wire() {
        document.getElementById('domTabs').addEventListener('click', e => { const b = e.target.closest('.tab'); if (b) switchTab(b.dataset.tab); });
        document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => window.Dom.closeModal(b.dataset.close)));
        document.getElementById('btnRefresh').addEventListener('click', e => busy(e.currentTarget, async () => {
            const d = await api('process.php', { ids: [ID], lookup: true, check: false });
            const r = (d.results || [])[0] || {};
            if (r.lookup_ok === false) showToast(r.lookup_error, 'warning'); else showToast(T('page.refreshed'), 'success');
            await load();
        }));
        document.getElementById('btnCheck').addEventListener('click', e => busy(e.currentTarget, async () => {
            await api('process.php', { ids: [ID], lookup: false, check: true });
            showToast(T('page.checked'), 'success');
            await load();
            switchTab('security');
        }));
        document.getElementById('btnEdit').addEventListener('click', openEdit);
        document.getElementById('eSave').addEventListener('click', saveEdit);
        document.getElementById('ePurpose').addEventListener('change', () => { document.getElementById('ePurposeHint').textContent = T('purpose_hint.' + document.getElementById('ePurpose').value); });
        document.getElementById('btnDelete').addEventListener('click', async () => {
            const ok = await showConfirm({ title: T('page.delete_title'), message: T('page.delete_body', { name: D.domain_name }), okLabel: T('bulk.delete_ok'), okClass: 'danger' });
            if (!ok) return;
            try { await api('delete.php', { id: ID }); location.href = './'; } catch (e) { showToast(e.message, 'error'); }
        });
        document.addEventListener('click', async e => {
            const go = e.target.closest('[data-goto]');
            if (go) { e.preventDefault(); switchTab(go.dataset.goto); return; }
            if (e.target.id === 'codeReveal') {
                e.preventDefault();
                try { const d = await api('auth_code.php', { id: ID, action: 'reveal' }); document.getElementById('codeVal').textContent = d.code || ''; e.target.remove(); }
                catch (err) { showToast(err.message, 'error'); }
                return;
            }
            if (e.target.id === 'codeChange') { e.preventDefault(); document.getElementById('cCode').value = ''; window.Dom.openModal('mCode'); return; }
            if (e.target.id === 'ctScan') { busy(e.target, async () => { const d = await api('watch.php', { id: ID, action: 'scan_ct' }); showToast(T('cert.scanned', { n: d.result.total, fresh: d.result.new }), 'success'); await load(); }); return; }
            if (e.target.id === 'ctAck') { try { await api('watch.php', { id: ID, action: 'ack_ct' }); await load(); } catch (err) { showToast(err.message, 'error'); } return; }
            if (e.target.id === 'laScan') { busy(e.target, async () => { const d = await api('watch.php', { id: ID, action: 'scan_lookalikes' }); showToast(T('la.scanned', { checked: d.result.checked, found: d.result.found }), 'success'); await load(); }); return; }
            const la = e.target.closest('[data-la]');
            if (la) { e.preventDefault(); try { await api('watch.php', { id: ID, action: 'dismiss', lookalike_id: la.dataset.la, dismissed: la.dataset.dismissed === '1' }); await load(); } catch (err) { showToast(err.message, 'error'); } }
        });
        document.getElementById('cSave').addEventListener('click', async () => {
            try { await api('auth_code.php', { id: ID, action: 'set', code: document.getElementById('cCode').value }); window.Dom.closeModal('mCode'); showToast(T('code.saved'), 'success'); await load(); }
            catch (e) { showToast(e.message, 'error'); }
        });
    }

    // ---------------------------------------------------------------- edit
    function openEdit() {
        if (!L) { showToast(T('err.generic'), 'error'); return; }
        const F = window.Dom.fillSelect;
        F('ePurpose', L.purposes.map(p => ({ id: p, name: purpose(p) })));
        F('eStatus', L.statuses, { blank: T('status.none') });
        F('eOwner', L.analysts, { blank: T('field.no_owner') });
        F('eRegistrar', L.suppliers, { blank: '—' });
        const acc = (L.accounts || []).filter(a => a.tenant_id === D.tenant_id || (!data.multi_company));
        F('eAccount', acc, { blank: '—' });
        F('eRenewal', L.renewal_modes.map(m => ({ id: m, name: renewal(m) })));
        F('eTLock', [{ id: '1', name: T('lock.on') }, { id: '0', name: T('lock.off') }], { blank: T('lock.unknown') });
        F('eContract', L.contracts || [], { blank: '—' });
        F('eTech', L.contacts || [], { blank: '—' });
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            let v = D[k];
            if (el.dataset.bool) { el.checked = !!v; return; }
            if (el.dataset.tri) { el.value = v === true ? '1' : (v === false ? '0' : ''); return; }
            el.value = v === null || v === undefined ? '' : v;
        });
        document.getElementById('ePurposeHint').textContent = T('purpose_hint.' + D.purpose);
        window.Dom.openModal('mEdit');
    }

    async function saveEdit() {
        const body = { id: ID };
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            body[k] = el.dataset.bool ? (el.checked ? 1 : 0) : el.value;
        });
        const btn = document.getElementById('eSave');
        btn.disabled = true;
        try {
            await api('save.php', body);
            window.Dom.closeModal('mEdit');
            showToast(T('page.saved'), 'success');
            await load();
        } catch (e) { showToast(e.message, 'error'); } finally { btn.disabled = false; }
    }
})();
