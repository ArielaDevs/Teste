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
        // ?edit=1 - from the register's right-click Edit: straight into the dialog.
        if (new URLSearchParams(location.search).get('edit') === '1' && D) openEdit();
    });

    async function load() {
        try {
            data = await api('get.php?id=' + ID);
            D = data.domain;
            renderHero(); renderOverview(); renderSecurity(); renderCerts(); renderLookalikes(); renderHistory();
            loadConnections();
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
            + row('field.tech_contact', techCell())
            + row('field.customer', customerCell())
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


    // ---------------------------------------------------------------- connections (3.0.0)
    // What this domain is part of elsewhere: CIs, Service Status services,
    // tickets, runbooks and its contract. One card per kind the analyst can open
    // (the server leaves out the rest); link by searching, unlink with the ✕.
    // Every rule is server-side in includes/domains/links.php.
    const KINDS = [
        { kind: 'cmdb',    icon: '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>' },
        { kind: 'service', icon: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>' },
        { kind: 'ticket',  icon: '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h6"/>' },
        { kind: 'article', icon: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' },
    ];
    let C = null;

    async function loadConnections() {
        try { C = await api('links.php?domain_id=' + ID); }
        catch (e) { C = { error: e.message }; }
        renderConnections();
    }

    function connIcon(paths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    }

    function riskHtml() {
        const s = C.status || {};
        if (!s.ready || !(s.problems || []).length || !s.at_risk || s.mode === undefined) return '';
        const why = s.problems.map(p => esc(p.kind === 'expired' ? T('links.risk_expired', { date: fmtDate(p.date) })
            : T(p.date < new Date().toISOString().slice(0, 10) ? 'links.risk_cert_gone' : 'links.risk_cert', { date: fmtDate(p.date) }))).join(' ');
        let act = '';
        if (s.incident) {
            act = '<div class="dom-conn-risk-act">' + esc(T('links.incident_open', { title: s.incident.title }))
                + ' <a class="dom-btn" href="' + esc(window.DOM_BASE + 'service-status/') + '">' + esc(T('links.incident_view')) + '</a></div>';
        } else if (s.mode === 'off') {
            act = '<div class="dom-conn-risk-act dom-sub">' + esc(T('links.mode_off')) + '</div>';
        } else if (!s.can_raise) {
            act = '<div class="dom-conn-risk-act dom-sub">' + esc(T('links.cant_raise')) + '</div>';
        } else {
            act = '<div class="dom-conn-risk-act">' + (s.mode === 'auto' ? '<span class="dom-sub">' + esc(T('links.mode_auto')) + '</span> ' : '')
                + '<button type="button" class="dom-btn danger" id="connRaise">' + esc(T('links.raise')) + '</button></div>';
        }
        return '<div class="dom-conn-risk" role="alert"><strong>' + esc(T('links.risk_title')) + '</strong> ' + why
            + ' ' + esc(T('links.risk_body', { n: s.at_risk })) + act + '</div>';
    }

    function contractHtml() {
        const has = D && D.contract_id;
        const label = (data && data.domain && data.domain.contract_label) || '';
        return '<div class="dom-card dom-conn-card"><div class="dom-card-h"><h3>' + connIcon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>')
            + esc(T('links.contract_title')) + '</h3></div><div class="dom-card-b"><p class="dom-hint">' + esc(T('links.contract_hint')) + '</p>'
            + (has ? '<a class="dom-conn-row-link" href="' + esc(window.DOM_BASE + 'contracts/view.php?id=' + D.contract_id) + '">' + esc(label || ('#' + D.contract_id)) + '</a>'
                   : '<div class="dom-sub">' + esc(T('links.contract_none')) + '</div>')
            + ' <button type="button" class="dom-btn" data-goto-edit="1">' + esc(T('links.contract_set')) + '</button></div></div>';
    }

    function rowHtml(kind, r) {
        const extra = kind === 'ticket' && r.status
            ? ' <span class="dom-pill" style="background:' + esc(r.status_colour || '#888') + '22;color:' + esc(r.status_colour || '#666') + '">' + esc(r.status) + '</span>'
            : (r.inactive ? ' <span class="dom-pill grey">' + esc(T('links.inactive')) + '</span>' : '');
        return '<li class="dom-conn-row"><div class="dom-conn-row-main"><a class="dom-conn-row-link" href="' + esc(window.DOM_BASE + r.url) + '">' + esc(r.label) + '</a>' + extra
            + (r.sub ? '<div class="dom-sub">' + esc(r.sub) + '</div>' : '') + '</div>'
            + '<button type="button" class="dom-conn-x" data-unlink="' + kind + '" data-id="' + r.id + '" title="' + esc(T('links.remove')) + '" aria-label="' + esc(T('links.remove')) + ': ' + esc(r.label) + '">&times;</button></li>';
    }

    function renderConnections() {
        const host = document.getElementById('connections');
        if (!host) return;
        if (C.error) { host.innerHTML = '<div class="dom-empty">' + esc(C.error) + '</div>'; return; }
        if (C.ready === false) { host.innerHTML = '<div class="dom-card"><div class="dom-card-b dom-sub">' + esc(T('links.not_ready')) + '</div></div>'; return; }
        const kinds = KINDS.filter(k => C.links && C.links[k.kind] !== undefined);
        let total = 0;
        const cards = kinds.map(k => {
            const rows = C.links[k.kind];
            total += rows.length;
            return '<div class="dom-card dom-conn-card" data-kind="' + k.kind + '"><div class="dom-card-h"><h3>' + connIcon(k.icon) + esc(T('links.' + k.kind + '_title'))
                + ' <span class="dom-pill grey">' + rows.length + '</span></h3></div><div class="dom-card-b">'
                + '<p class="dom-hint">' + esc(T('links.' + k.kind + '_hint')) + '</p>'
                + (rows.length ? '<ul class="dom-conn-list">' + rows.map(r => rowHtml(k.kind, r)).join('') + '</ul>' : '<div class="dom-sub dom-conn-none">' + esc(T('links.none')) + '</div>')
                + '<div class="dom-person dom-conn-search"><input type="text" data-search="' + k.kind + '" autocomplete="off" placeholder="' + esc(T('links.search_ph')) + '" aria-label="' + esc(T('links.' + k.kind + '_title')) + ': ' + esc(T('links.search_ph')) + '">'
                + '<ul class="dom-person-results" data-results="' + k.kind + '" role="listbox" hidden></ul></div>'
                + '</div></div>';
        }).join('');
        host.innerHTML = riskHtml() + '<p class="dom-hint dom-conn-intro">' + esc(T('links.intro')) + '</p>'
            + '<div class="dom-conn-grid">' + contractHtml() + (cards || '') + '</div>'
            + (kinds.length ? '' : '<div class="dom-sub">' + esc(T('links.nothing_allowed')) + '</div>');
        const badge = document.getElementById('connBadge');
        if (badge) badge.innerHTML = total ? '<span class="dom-pill grey">' + total + '</span>' : '';
        const risk = C.status && C.status.at_risk && (C.status.problems || []).length && !C.status.incident;
        if (badge && risk) badge.innerHTML = '<span class="dom-pill red">' + total + '</span>';
    }

    let connTimer = null;
    async function connSearch(input) {
        const kind = input.dataset.search;
        const ul = document.querySelector('[data-results="' + kind + '"]');
        try {
            const d = await api('links.php?domain_id=' + ID + '&search=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(input.value.trim()));
            const res = d.results || [];
            ul.innerHTML = res.length
                ? res.map(r => '<li role="option" tabindex="-1" data-add="' + kind + '" data-id="' + r.id + '">' + esc(r.label) + (r.sub ? '<small>' + esc(r.sub) + '</small>' : '') + '</li>').join('')
                : '<li class="dom-sub" aria-disabled="true">' + esc(T('links.no_match')) + '</li>';
            ul.hidden = false;
        } catch (e) { showToast(e.message, 'error'); }
    }

    function wireConnections() {
        const host = document.getElementById('connections');
        if (!host) return;
        host.addEventListener('input', e => {
            const inp = e.target.closest('[data-search]');
            if (!inp) return;
            clearTimeout(connTimer);
            connTimer = setTimeout(() => connSearch(inp), 220);
        });
        host.addEventListener('focusin', e => { const inp = e.target.closest('[data-search]'); if (inp) connSearch(inp); });
        host.addEventListener('focusout', e => {
            const box = e.target.closest('.dom-conn-search');
            if (box) setTimeout(() => { if (!box.contains(document.activeElement)) box.querySelector('.dom-person-results').hidden = true; }, 150);
        });
        // mousedown, not click: the input's blur would hide the list first.
        host.addEventListener('mousedown', async e => {
            const li = e.target.closest('[data-add]');
            if (!li) return;
            e.preventDefault();
            try {
                await api('links.php', { action: 'add', domain_id: ID, kind: li.dataset.add, target_id: Number(li.dataset.id) });
                showToast(T('links.linked'), 'success');
                await loadConnections();
            } catch (err) { showToast(err.message, 'error'); }
        });
        host.addEventListener('click', async e => {
            const x = e.target.closest('[data-unlink]');
            if (x) {
                try {
                    await api('links.php', { action: 'remove', domain_id: ID, kind: x.dataset.unlink, target_id: Number(x.dataset.id) });
                    showToast(T('links.unlinked'), 'success');
                    await loadConnections();
                } catch (err) { showToast(err.message, 'error'); }
                return;
            }
            if (e.target.closest('[data-goto-edit]')) { openEdit(); return; }
            if (e.target.id === 'connRaise') {
                busy(e.target, async () => {
                    await api('links.php', { action: 'raise_incident', domain_id: ID });
                    showToast(T('links.raised'), 'success');
                    await loadConnections();
                });
            }
        });
        host.addEventListener('keydown', e => {
            const li = e.target.closest('[data-add]');
            if (li && e.key === 'Enter') { e.preventDefault(); li.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); }
            if (e.key === 'Escape') { const ul = e.target.closest('.dom-conn-search')?.querySelector('.dom-person-results'); if (ul) ul.hidden = true; }
            if (e.key === 'ArrowDown' && e.target.matches('[data-search]')) {
                const first = e.target.closest('.dom-conn-search').querySelector('[data-add]');
                if (first) { e.preventDefault(); first.focus(); }
            }
        });
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
        wireConnections();
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

    // ------------------------------------- who: tech contact + customer (#162)
    // The technical contact is a supplier contact OR one of your analysts; the
    // customer is a person OR a supplier, optionally with one of its contacts.
    function peopleLink(page, id, name) {
        const label = esc(name || ('#' + id));
        const ok = page === 'person.php' ? window.DOM_PEOPLE : (window.DOM_PEOPLE && window.DOM_PEOPLE_SUPPLIERS);
        return ok ? '<a href="' + esc(window.DOM_PEOPLE + page + '?id=' + id) + '">' + label + '</a>' : label;
    }
    function techCell() {
        if (D.tech_analyst_id) return esc(D.tech_analyst_name || ('#' + D.tech_analyst_id)) + '<div class="dom-sub">' + esc(T('field.tech_is_analyst')) + '</div>';
        if (D.tech_contact_id) return peopleLink('contact.php', D.tech_contact_id, D.tech_contact_name);
        return '';
    }
    function customerCell() {
        if (D.customer_user_id) {
            return peopleLink('person.php', D.customer_user_id, D.customer_user_name)
                + (D.customer_user_email && D.customer_user_email !== D.customer_user_name ? '<div class="dom-sub">' + esc(D.customer_user_email) + '</div>' : '');
        }
        if (D.customer_supplier_id && D.customer_contact_id) {
            return peopleLink('supplier.php', D.customer_supplier_id, D.customer_supplier_name)
                + '<div class="dom-sub">' + peopleLink('contact.php', D.customer_contact_id, D.customer_contact_name) + '</div>';
        }
        if (D.customer_supplier_id) return peopleLink('supplier.php', D.customer_supplier_id, D.customer_supplier_name);
        if (D.customer_contact_id) return peopleLink('contact.php', D.customer_contact_id, D.customer_contact_name);
        return '';
    }
    function customerLabel() {
        if (D.customer_user_id) return D.customer_user_name || '';
        if (D.customer_contact_id) return (D.customer_contact_name || '') + (D.customer_supplier_name ? ' (' + D.customer_supplier_name + ')' : '');
        if (D.customer_supplier_id) return D.customer_supplier_name || '';
        return '';
    }

    // ---------------------------------------------------------------- edit
    let techAtOpen = '', custAtOpen = '';
    function fillTech() {
        const sel = document.getElementById('eTech');
        const opt = (v, label) => { const o = document.createElement('option'); o.value = v; o.textContent = label; return o; };
        const group = (label, items) => { const g = document.createElement('optgroup'); g.label = label; g.append(...items); return g; };
        sel.replaceChildren(opt('', '—'));
        if (L.parties_ready) {
            const analysts = (L.analysts || []).slice();
            // Somebody set earlier who has since left stays on the list, so the
            // dialog shows the truth; saving sends this field only if it changed.
            if (D.tech_analyst_id && !analysts.some(a => +a.id === D.tech_analyst_id)) analysts.unshift({ id: D.tech_analyst_id, name: D.tech_analyst_name || ('#' + D.tech_analyst_id) });
            sel.append(group(T('field.tech_group_analysts'), analysts.map(a => opt('a:' + a.id, a.name))));
        }
        // Contacts are listed only for analysts who can open Contracts; the one
        // already set is always kept, so opening and saving never clears it.
        const contacts = (L.contacts || []).slice();
        if (D.tech_contact_id && !contacts.some(c => +c.id === D.tech_contact_id)) contacts.unshift({ id: D.tech_contact_id, name: D.tech_contact_name || ('#' + D.tech_contact_id) });
        if (contacts.length) sel.append(group(T('field.tech_group_contacts'), contacts.map(c => opt('c:' + c.id, c.name))));
        sel.value = D.tech_analyst_id ? 'a:' + D.tech_analyst_id : (D.tech_contact_id ? 'c:' + D.tech_contact_id : '');
        techAtOpen = sel.value;
    }

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
        // A linked customer contract this analyst cannot see (#153) is not in the
        // list; keep it as an option so saving the form does not unlink it.
        const contracts = (L.contracts || []).slice();
        if (D.contract_id && !contracts.some(c => +c.id === D.contract_id)) contracts.unshift({ id: D.contract_id, name: T('field.contract_hidden') });
        F('eContract', contracts, { blank: '—' });
        fillTech();
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            let v = D[k];
            if (el.dataset.bool) { el.checked = !!v; return; }
            if (el.dataset.tri) { el.value = v === true ? '1' : (v === false ? '0' : ''); return; }
            el.value = v === null || v === undefined ? '' : v;
        });
        document.getElementById('ePurposeHint').textContent = T('purpose_hint.' + D.purpose);
        const ck = D.customer_user_id ? 'user:' + D.customer_user_id
                 : D.customer_contact_id ? 'contact:' + D.customer_contact_id
                 : D.customer_supplier_id ? 'supplier:' + D.customer_supplier_id : '';
        setCustomer(ck, customerLabel());
        custAtOpen = ck;
        window.Dom.openModal('mEdit');
    }

    // --------------------------------- customer: person (#153) or supplier (#162)
    // eCustomerId holds "user:5", "supplier:3" or "contact:9" - one kind only.
    function setCustomer(key, label) {
        document.getElementById('eCustomerId').value = key || '';
        document.getElementById('eCustomer').value = label || '';
        document.getElementById('eCustomerClear').hidden = !key;
        hideCustomers();
    }
    function hideCustomers() { const ul = document.getElementById('eCustomerResults'); ul.hidden = true; ul.replaceChildren(); }
    let custTimer = null, custSeq = 0;
    function searchCustomers() {
        document.getElementById('eCustomerId').value = '';   // typing again means choosing again
        document.getElementById('eCustomerClear').hidden = true;
        clearTimeout(custTimer);
        const q = document.getElementById('eCustomer').value.trim();
        if (q.length < 2) { hideCustomers(); return; }
        custTimer = setTimeout(async () => {
            const seq = ++custSeq;
            let people = [], suppliers = [];
            try {
                const res = await api('people.php?domain_id=' + ID + '&q=' + encodeURIComponent(q));
                people = res.people || []; suppliers = res.suppliers || [];
            } catch (e) { people = []; suppliers = []; }
            if (seq !== custSeq) return;
            const ul = document.getElementById('eCustomerResults');
            if (!people.length && !suppliers.length) {
                const li = document.createElement('li'); li.textContent = T('field.customer_none'); li.setAttribute('aria-disabled', 'true');
                ul.replaceChildren(li); ul.hidden = false; return;
            }
            const item = (key, label, sub) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option'); li.dataset.id = key; li.dataset.label = label;
                li.append(document.createTextNode(label));
                if (sub) { const s = document.createElement('small'); s.textContent = sub; li.append(s); }
                li.addEventListener('mousedown', e => { e.preventDefault(); setCustomer(key, label); });
                return li;
            };
            const heading = text => { const li = document.createElement('li'); li.className = 'dom-person-group'; li.setAttribute('role', 'presentation'); li.textContent = text; return li; };
            const out = [];
            // Headings only when both kinds are there - one list needs no label.
            if (people.length && suppliers.length) out.push(heading(T('field.customer_group_people')));
            people.forEach(p => out.push(item('user:' + p.id, p.name, p.email && p.email !== p.name ? p.email : '')));
            if (suppliers.length && people.length) out.push(heading(T('field.customer_group_suppliers')));
            suppliers.forEach(s => s.kind === 'supplier'
                ? out.push(item('supplier:' + s.id, s.name, T('field.customer_is_supplier')))
                : out.push(item('contact:' + s.id, s.name + (s.supplier ? ' (' + s.supplier + ')' : ''), s.email || '')));
            ul.replaceChildren(...out);
            ul.hidden = false;
        }, 220);
    }
    function customerKeys(e) {
        const ul = document.getElementById('eCustomerResults');
        const items = Array.from(ul.querySelectorAll('li[role=option]'));
        if (ul.hidden || !items.length) return;
        let i = items.findIndex(li => li.getAttribute('aria-selected') === 'true');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            i = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1);
            items.forEach((li, n) => li.setAttribute('aria-selected', n === i ? 'true' : 'false'));
        } else if (e.key === 'Enter' && i >= 0) {
            e.preventDefault(); setCustomer(items[i].dataset.id, items[i].dataset.label);
        } else if (e.key === 'Escape') { hideCustomers(); }
    }
    document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('eCustomer');
        if (!input) return;   // no edit modal for a domain that does not exist
        input.addEventListener('input', searchCustomers);
        input.addEventListener('keydown', customerKeys);
        document.getElementById('eCustomerClear').addEventListener('click', () => { setCustomer('', ''); input.focus(); });
        document.addEventListener('click', e => { if (!e.target.closest('.dom-person')) hideCustomers(); });
    });

    async function saveEdit() {
        const body = { id: ID };
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            body[k] = el.dataset.bool ? (el.checked ? 1 : 0) : el.value;
        });
        // Who (#162): sent only when changed, so a contact or analyst set by
        // somebody else - one this analyst's lists cannot show - is left alone.
        const tech = document.getElementById('eTech').value;
        if (tech !== techAtOpen) {
            body.tech_contact_id = tech.startsWith('c:') ? tech.slice(2) : null;
            if (L.parties_ready) body.tech_analyst_id = tech.startsWith('a:') ? tech.slice(2) : null;
        }
        const cust = document.getElementById('eCustomerId').value;
        if (cust !== custAtOpen) {
            const [kind, id] = cust ? cust.split(':') : ['', ''];
            body.customer_user_id = kind === 'user' ? id : null;
            if (L.parties_ready) {
                body.customer_supplier_id = kind === 'supplier' ? id : null;
                body.customer_contact_id = kind === 'contact' ? id : null;
            }
        }
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
