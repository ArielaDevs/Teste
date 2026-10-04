/**
 * The Domains panel other modules show (3.0.0): the domains linked to a CMDB
 * object, a knowledge article or a contract, with unlink and "link a domain".
 *
 *   DomainLinks.mount(hostEl, {
 *       kind:  'cmdb' | 'article' | 'contract',
 *       id:    the record's id,
 *       base:  BASE_URL,
 *       cardClass / headClass / titleClass: the host page's own card classes,
 *       editable: false to list only
 *       hideEmpty: true to show nothing at all when nothing is linked
 *       onChange: called after a link is made or removed
 *       bare: true when the host page draws its own heading - just the body
 *   });
 *
 * Every rule - who may see or make a link, same company, the other module's own
 * permissions - is server-side in includes/domains/links.php. A host page only
 * mounts this for an analyst who can open Domains, so nothing here has to ask.
 *
 * Labels come from the 'domains' translation namespace, which the host page
 * exports alongside its own.
 */
(function () {
    'use strict';
    if (window.DomainLinks) return;

    const T = (k, p) => (window.t ? window.t('domains.' + k, p) : k);
    const esc = v => (v === null || v === undefined) ? '' : String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = d => {
        if (!d) return '';
        // A calendar date, not an instant: fmtNaiveDate, never fmtDate - which would
        // shift it a day for anyone west of UTC (the Domains module's own rule).
        try { return window.fmtNaiveDate ? window.fmtNaiveDate(String(d).slice(0, 10) + 'T00:00:00') : String(d).slice(0, 10); } catch (e) { return String(d).slice(0, 10); }
    };

    async function call(base, path, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        const r = await fetch(base + 'api/domains/' + path, Object.assign({ credentials: 'same-origin' }, opts));
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Error');
        return d;
    }
    const toast = (m, k) => { if (window.showToast) window.showToast(m, k); };

    function pill(days) {
        if (days === null || days === undefined) return '';
        const cls = days < 0 ? 'bad' : (days <= 30 ? 'warn' : 'ok');
        const txt = days < 0 ? T('days.expired_ago', { n: -days }) : T('days.in', { n: days });
        return '<span class="dl-pill ' + cls + '">' + esc(txt) + '</span>';
    }

    function money(amount, cur) {
        try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur || 'GBP' }).format(amount); }
        catch (e) { return (cur ? cur + ' ' : '') + Number(amount).toFixed(2); }
    }

    function mount(host, o) {
        if (!host) return;
        const editable = o.editable !== false;
        const listUrl = o.kind === 'contract' ? 'links.php?contract_id=' + o.id : 'links.php?for=' + o.kind + '&id=' + o.id;
        async function load() {
            let rows = [];
            try { rows = (await call(o.base, listUrl)).domains || []; }
            catch (e) { host.innerHTML = ''; return; }          // no Domains for this analyst: no panel
            // A reading view with nothing linked shows nothing, rather than an
            // empty 'Domains' box under every article.
            if (!rows.length && o.hideEmpty) { host.innerHTML = ''; return; }
            const hint = T('links.domains_hint_' + o.kind);
            let total = '';
            if (o.kind === 'contract') {
                const sums = {};
                rows.forEach(r => { if (r.cost !== null) { const c = r.currency || 'GBP'; sums[c] = (sums[c] || 0) + r.cost / Math.max(1, r.billing_years || 1); } });
                const parts = Object.keys(sums).map(c => money(sums[c], c));
                if (parts.length) total = '<div class="dl-total">' + esc(T('links.total_year', { amount: parts.join(' + ') })) + '</div>';
            }
            host.innerHTML =
                (o.bare ? '<div class="dl-bare">' : '<div class="' + (o.cardClass || 'dl-card') + '">'
                + '<div class="' + (o.headClass || 'dl-head') + '"><span class="' + (o.titleClass || 'dl-title') + '">' + esc(T('links.domains_title')) + '</span>'
                + '<span class="dl-count">' + rows.length + '</span></div>')
                + '<div class="dl-body">'
                + (hint && hint.indexOf('links.') !== 0 ? '<p class="dl-hint">' + esc(hint) + '</p>' : '')
                + (rows.length ? '<ul class="dl-list">' + rows.map(r =>
                    '<li class="dl-row"><div class="dl-main"><a class="dl-link" href="' + esc(o.base + r.url) + '">' + esc(r.name) + '</a> ' + pill(r.days_left)
                    + (r.grade ? ' <span class="dl-grade g-' + esc(String(r.grade).charAt(0)) + '">' + esc(r.grade) + '</span>' : '')
                    + '<div class="dl-sub">' + esc([r.expiry_date ? T('links.expires', { date: fmt(r.expiry_date) }) : '', r.status || '',
                        (o.kind === 'contract' && r.cost !== null) ? T('links.per_year', { amount: money(r.cost / Math.max(1, r.billing_years || 1), r.currency) }) : ''].filter(Boolean).join(' · ')) + '</div></div>'
                    + (editable ? '<button type="button" class="dl-x" data-dl-unlink="' + r.id + '" title="' + esc(T('links.remove')) + '" aria-label="' + esc(T('links.remove')) + ': ' + esc(r.name) + '">&times;</button>' : '')
                    + '</li>').join('') + '</ul>' : '<div class="dl-none">' + esc(T('links.no_domains')) + '</div>')
                + total
                + (editable ? '<div class="dl-search"><input type="text" autocomplete="off" placeholder="' + esc(T('links.domain_search_ph')) + '" aria-label="' + esc(T('links.add_domain')) + '">'
                    + '<ul class="dl-results" role="listbox" hidden></ul></div>' : '')
                + '</div></div>';
        }

        async function search(input) {
            const ul = host.querySelector('.dl-results');
            try {
                const url = o.kind === 'contract'
                    ? 'list.php?q=' + encodeURIComponent(input.value.trim())
                    : 'links.php?for=' + o.kind + '&id=' + o.id + '&pick=1&q=' + encodeURIComponent(input.value.trim());
                let res = (await call(o.base, url)).domains || [];
                // Only domains under NO contract: offering one under another contract
                // would quietly move it. Moving is a deliberate change, in its Edit.
                if (o.kind === 'contract') res = res.filter(r => !r.contract_id).slice(0, 20)
                    .map(r => ({ id: r.id, name: r.display_name || r.domain_name, expiry_date: r.expiry_date }));
                ul.innerHTML = res.length
                    ? res.map(r => '<li role="option" tabindex="-1" data-dl-add="' + r.id + '">' + esc(r.name) + (r.expiry_date ? '<small>' + esc(T('links.expires', { date: fmt(r.expiry_date) })) + '</small>' : '') + '</li>').join('')
                    : '<li class="dl-dim" aria-disabled="true">' + esc(T('links.no_match')) + '</li>';
                ul.hidden = false;
            } catch (e) { toast(e.message, 'error'); }
        }

        async function link(domainId, add) {
            try {
                if (o.kind === 'contract') {
                    await call(o.base, 'links.php', { action: 'set_contract', domain_id: domainId, contract_id: add ? o.id : null });
                } else {
                    await call(o.base, 'links.php', { action: add ? 'add' : 'remove', domain_id: domainId, kind: o.kind, target_id: o.id });
                }
                toast(T(add ? 'links.linked' : 'links.unlinked'), 'success');
                await load();
                if (typeof o.onChange === 'function') o.onChange();
            } catch (e) { toast(e.message, 'error'); }
        }

        // 🔴 Mounting again on the same box (a page that re-renders, a reading
        // view refreshed from the editor) must not add a second set of listeners -
        // one click would then link twice. The box is wired ONCE and the
        // listeners always call the functions of the LATEST mount.
        host._dl = { search, link };
        if (!host._dlWired) {
            host._dlWired = true;
            let timer = null;
            host.addEventListener('input', e => {
                if (!e.target.closest('.dl-search input')) return;
                clearTimeout(timer);
                timer = setTimeout(() => host._dl.search(e.target), 220);
            });
            host.addEventListener('focusin', e => { if (e.target.closest('.dl-search input')) host._dl.search(e.target); });
            host.addEventListener('focusout', e => {
                const box = e.target.closest('.dl-search');
                if (box) setTimeout(() => { if (!box.contains(document.activeElement)) { const ul = box.querySelector('.dl-results'); if (ul) ul.hidden = true; } }, 150);
            });
            // mousedown, not click: the input's blur would hide the list first.
            host.addEventListener('mousedown', e => {
                const li = e.target.closest('[data-dl-add]');
                if (li) { e.preventDefault(); host._dl.link(Number(li.dataset.dlAdd), true); }
            });
            host.addEventListener('click', e => {
                const x = e.target.closest('[data-dl-unlink]');
                if (x) host._dl.link(Number(x.dataset.dlUnlink), false);
            });
            host.addEventListener('keydown', e => {
                const li = e.target.closest('[data-dl-add]');
                if (li && e.key === 'Enter') { e.preventDefault(); host._dl.link(Number(li.dataset.dlAdd), true); }
                if (e.key === 'Escape') { const ul = host.querySelector('.dl-results'); if (ul) ul.hidden = true; }
                if (e.key === 'ArrowDown' && e.target.matches('.dl-search input')) { const f = host.querySelector('[data-dl-add]'); if (f) { e.preventDefault(); f.focus(); } }
            });
        }

        load();
    }

    window.DomainLinks = { mount };
})();
