/**
 * Reporting -> Report Packs list (reporting/packs/index.php).
 *
 * Everything a pack's name or description contains goes onto the page with
 * textContent, never innerHTML: a shared pack's name is somebody else's text.
 */
(function () {
    'use strict';

    const API = window.RP_API;
    const T = (k, p) => (window.t ? window.t('reporting.packs.' + k, p) : k);

    let packs = [];
    let copyOf = null;          // set when the New dialog is copying a pack
    let deleting = null;
    let sharing = null;         // {id, shares[], analysts[], teams[], departments[]}

    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }

    async function api(path, body) {
        const opts = body === undefined ? {} : {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
        };
        const res = await fetch(API + path, opts);
        let data = null;
        try { data = await res.json(); } catch (e) { /* fall through */ }
        if (!data) throw new Error(T('err.load'));
        if (!data.success) { const err = new Error(data.error || T('err.load')); err.data = data; throw err; }
        return data;
    }

    async function load() {
        const grid = document.getElementById('rpGrid');
        try {
            packs = (await api('list.php')).packs;
            render();
        } catch (e) {
            grid.replaceChildren(el('div', 'rp-empty', e.message));
        }
    }

    const ROLE_LABEL = { owner: 'list.role_owner', edit: 'list.role_edit', view: 'list.role_view' };

    function render() {
        const grid = document.getElementById('rpGrid');
        const q = (document.getElementById('rpSearch').value || '').trim().toLowerCase();
        const shown = packs.filter(p => !q || (p.name + ' ' + (p.description || '') + ' ' + (p.owner_name || '')).toLowerCase().includes(q));

        if (!packs.length) {
            const empty = el('div', 'rp-empty-state');
            empty.append(el('h2', null, T('list.empty_title')), el('p', null, T('list.empty_body')));
            const b = el('button', 'btn btn-primary', T('list.new'));
            b.onclick = openNew;
            empty.append(b);
            grid.replaceChildren(empty);
            return;
        }
        if (!shown.length) { grid.replaceChildren(el('div', 'rp-empty', T('list.no_match'))); return; }

        grid.replaceChildren(...shown.map(card));
    }

    function card(p) {
        const c = el('article', 'rp-card');
        c.tabIndex = 0;
        c.onclick = (e) => { if (!e.target.closest('button')) open(p.id); };
        c.onkeydown = (e) => { if (e.key === 'Enter' && e.target === c) open(p.id); };

        const thumb = el('div', 'rp-card-thumb');
        thumb.setAttribute('aria-hidden', 'true');
        for (let i = 0; i < 3; i++) thumb.append(el('span'));
        c.append(thumb);

        const body = el('div', 'rp-card-body');
        const title = el('h3', 'rp-card-title', p.name);
        body.append(title);
        if (p.description) body.append(el('p', 'rp-card-desc', p.description));

        const meta = el('div', 'rp-card-meta');
        meta.append(el('span', 'rp-role rp-role-' + p.role, T(ROLE_LABEL[p.role])));
        if (p.shared && p.role === 'owner') meta.append(el('span', 'rp-role rp-role-shared', T('list.shared')));
        body.append(meta);

        const who = p.role === 'owner'
            ? T('list.changed', { when: p.updated_display || '' })
            : T('list.changed_by', { when: p.updated_display || '', name: p.owner_name || T('list.nobody') });
        body.append(el('p', 'rp-card-when', who));
        c.append(body);

        const actions = el('div', 'rp-card-actions');
        const add = (label, fn, cls) => { const b = el('button', 'rp-link' + (cls ? ' ' + cls : ''), label); b.onclick = (e) => { e.stopPropagation(); fn(); }; actions.append(b); };
        add(p.role === 'view' ? T('list.open_view') : T('list.open'), () => open(p.id));
        add(T('list.copy'), () => openNew(p));
        if (p.role === 'owner') {
            add(T('list.share'), () => openShare(p));
            add(T('list.delete'), () => askDelete(p), 'rp-link-danger');
        }
        c.append(actions);
        return c;
    }

    function open(id) { window.location.href = 'designer.php?id=' + encodeURIComponent(id); }

    // ── Modals ──────────────────────────────────────────────────────────
    function openModal(id) {
        const m = document.getElementById(id);
        m.classList.add('active');
        const first = m.querySelector('input, select, button');
        if (first) setTimeout(() => first.focus(), 50);
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    });

    function showError(id, msg) {
        const e = document.getElementById(id);
        e.textContent = msg || '';
        e.hidden = !msg;
    }

    function openNew(from) {
        copyOf = from && from.id ? from : null;
        document.getElementById('rpNewTitle').textContent = copyOf ? T('list.copy_title') : T('list.new_title');
        document.getElementById('rpNewSave').textContent = copyOf ? T('list.copy') : T('list.create');
        document.getElementById('rpNewName').value = copyOf ? T('list.copy_name', { name: copyOf.name }) : '';
        document.getElementById('rpNewDesc').value = copyOf ? (copyOf.description || '') : '';
        showError('rpNewError', '');
        openModal('rpNewModal');
    }

    async function create() {
        const name = document.getElementById('rpNewName').value.trim();
        if (!name) { showError('rpNewError', T('err.name_required')); return; }
        const btn = document.getElementById('rpNewSave');
        btn.disabled = true;
        try {
            const r = await api('create.php', { name, description: document.getElementById('rpNewDesc').value.trim(), copy_of: copyOf ? copyOf.id : 0 });
            open(r.id);
        } catch (e) {
            showError('rpNewError', e.message);
            btn.disabled = false;
        }
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && document.getElementById('rpNewModal').classList.contains('active') && e.target.tagName === 'INPUT') create();
    });

    function askDelete(p) {
        deleting = p;
        document.getElementById('rpDeleteText').textContent = T('list.delete_confirm', { name: p.name });
        openModal('rpDeleteModal');
    }
    async function confirmDelete() {
        if (!deleting) return;
        try {
            await api('delete.php', { id: deleting.id });
            closeModal('rpDeleteModal');
            packs = packs.filter(p => p.id !== deleting.id);
            deleting = null;
            render();
        } catch (e) {
            document.getElementById('rpDeleteText').textContent = e.message;
        }
    }

    // ── Sharing ─────────────────────────────────────────────────────────
    async function openShare(p) {
        showError('rpShareError', '');
        document.getElementById('rpShareTitle').textContent = T('share.title_named', { name: p.name });
        document.getElementById('rpShareList').replaceChildren(el('li', 'rp-share-empty', T('list.loading')));
        openModal('rpShareModal');
        try {
            const r = await api('shares.php?id=' + encodeURIComponent(p.id));
            sharing = { id: p.id, shares: r.shares, analysts: r.analysts, teams: r.teams, departments: r.departments };
            fillShareTargets();
            renderShares();
        } catch (e) {
            showError('rpShareError', e.message);
        }
    }

    function targetsFor(type) {
        if (!sharing) return [];
        if (type === 'analyst') return sharing.analysts.map(a => ({ id: a.id, label: a.name + (a.department ? ' (' + a.department + ')' : '') }));
        if (type === 'team') return sharing.teams.map(t => ({ id: t.id, label: t.name }));
        return sharing.departments.map(d => ({ value: d, label: d }));
    }

    function fillShareTargets() {
        const type = document.getElementById('rpShareType').value;
        const dl = document.getElementById('rpShareTargets');
        dl.replaceChildren(...targetsFor(type).map(t => { const o = document.createElement('option'); o.value = t.label; return o; }));
        const input = document.getElementById('rpShareTarget');
        input.value = '';
        input.placeholder = T('share.pick_' + type);
    }

    function addShare() {
        showError('rpShareError', '');
        const type = document.getElementById('rpShareType').value;
        const text = document.getElementById('rpShareTarget').value.trim();
        if (!text) return;
        const canEdit = document.getElementById('rpShareLevel').value === '1';
        let entry = null;
        if (type === 'department') {
            // A department need not be in the list: it may be one nobody has yet.
            entry = { type, value: text, label: text, can_edit: canEdit };
        } else {
            const hit = targetsFor(type).find(t => t.label.toLowerCase() === text.toLowerCase());
            if (!hit) { showError('rpShareError', T('share.not_found')); return; }
            entry = { type, id: hit.id, label: type === 'analyst' ? sharing.analysts.find(a => a.id === hit.id).name : hit.label, can_edit: canEdit };
        }
        const key = s => s.type + ':' + (s.type === 'department' ? String(s.value).toLowerCase() : s.id);
        const existing = sharing.shares.findIndex(s => key(s) === key(entry));
        if (existing >= 0) sharing.shares[existing].can_edit = canEdit; else sharing.shares.push(entry);
        document.getElementById('rpShareTarget').value = '';
        renderShares();
    }

    const TYPE_ICON = { analyst: '👤', team: '👥', department: '🏢' };

    function renderShares() {
        const list = document.getElementById('rpShareList');
        if (!sharing.shares.length) { list.replaceChildren(el('li', 'rp-share-empty', T('share.none'))); return; }
        list.replaceChildren(...sharing.shares.map((s, i) => {
            const li = el('li', 'rp-share-row');
            const icon = el('span', 'rp-share-icon', TYPE_ICON[s.type] || '');
            icon.setAttribute('aria-hidden', 'true');
            const name = el('span', 'rp-share-name', s.label);
            const kind = el('span', 'rp-share-kind', T('share.type_' + s.type));
            const level = el('select', 'rp-input rp-share-level');
            [['0', T('share.can_view')], ['1', T('share.can_edit')]].forEach(([v, l]) => { const o = el('option', null, l); o.value = v; level.append(o); });
            level.value = s.can_edit ? '1' : '0';
            level.setAttribute('aria-label', T('share.level_for', { name: s.label }));
            level.onchange = () => { s.can_edit = level.value === '1'; };
            const rm = el('button', 'rp-link rp-link-danger', T('share.remove'));
            rm.onclick = () => { sharing.shares.splice(i, 1); renderShares(); };
            li.append(icon, name, kind, level, rm);
            return li;
        }));
    }

    async function saveShares() {
        if (!sharing) return;
        try {
            await api('shares.php', { id: sharing.id, shares: sharing.shares });
            closeModal('rpShareModal');
            const p = packs.find(x => x.id === sharing.id);
            if (p) p.shared = sharing.shares.length > 0;
            render();
        } catch (e) {
            showError('rpShareError', e.message);
        }
    }

    window.RPList = { render, openNew, create, closeModal, confirmDelete, fillShareTargets, addShare, saveShares };
    load();
})();
