<?php
/**
 * Manager access - one manager's management lines, FULL SCREEN (discussion #62).
 *
 * WHY A PAGE AND NOT A MODAL
 * --------------------------
 * Ed: "some people will work with large organisations with lots of departments
 * and lots of users, so if we are making a picker to grant them access then this
 * should be full screen not in a modal." So: a page, searched and paged on the
 * server (api/tickets/manager_access.php), never "load everyone".
 *
 * ONE body, two doors - like the person editor. tickets/manager-access.php and
 * asset-management/manager-access.php each print their own module's header and
 * call managerAccessRender(); System -> Managers links to the Tickets one.
 *
 * Strings are resolved here, not exported: Assets pages do not export the
 * tickets namespace to JavaScript.
 */

/** Print the page body (below the module header). Call once, inside <body>. */
function managerAccessRender(int $managerId, string $backUrl): void
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $keys = [
        'page_title', 'back', 'intro', 'off_banner', 'left_banner', 'needs_verify', 'readonly',
        'can_see_label', 'people', 'people_one', 'sources_heading', 'directory_direct', 'directory_all',
        'directory_note', 'directory_off', 'lines_heading', 'lines_none', 'excl_heading', 'excl_desc',
        'excl_none', 'line_everyone', 'line_user', 'line_group', 'line_department', 'line_reports',
        'line_reports_all', 'line_gone_user', 'line_gone_group', 'line_meta', 'line_meta_nobody',
        'dept_empty', 'remove', 'add_heading', 'tab_people', 'tab_groups', 'tab_departments', 'tab_other',
        'search_people', 'search_groups', 'search_departments', 'add', 'exclude', 'is_added',
        'is_excluded', 'no_results', 'page_of', 'prev', 'next', 'everyone_title', 'everyone_desc',
        'everyone_desc_one', 'reports_title', 'reports_desc', 'reports_direct', 'reports_all',
        'team_heading', 'team_filter', 'team_more', 'team_none', 'flag_left', 'load_failed', 'save_failed',
    ];
    $T = [];
    foreach ($keys as $k) $T[$k] = t('tickets.manager_access.' . $k);
    ?>
    <style>
        body { display: flex; flex-direction: column; background: var(--app-bg, #f5f5f5); }
        .ma-scroll { flex: 1; min-height: 0; overflow: auto; }
        .ma-wrap { padding: 20px 24px 40px; }
        .ma-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 16px; flex-wrap: wrap; }
        .ma-back { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted, #666); text-decoration: none; font-size: 13px; margin-bottom: 8px; }
        .ma-back:hover { color: var(--accent, #0078d4); }
        .ma-name { font-size: 22px; font-weight: 600; margin: 0; color: var(--text, #333); }
        .ma-sub { color: var(--text-muted, #666); font-size: 13px; margin-top: 4px; }
        .ma-stat { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-left: 4px solid var(--accent, #0078d4);
                   border-radius: 8px; padding: 12px 18px; min-width: 200px; }
        .ma-stat-label { font-size: 12px; color: var(--text-muted, #666); text-transform: uppercase; letter-spacing: .04em; }
        .ma-stat-num { font-size: 26px; font-weight: 600; color: var(--text, #333); margin-top: 2px; }
        .ma-banner { border-radius: 6px; padding: 10px 14px; margin-bottom: 12px; font-size: 13px; border: 1px solid; }
        .ma-banner.info { background: var(--info-bg, #e8f4fd); border-color: var(--info-border, #b6d7f2); color: var(--info-text, #0b4f85); }
        .ma-banner.warn { background: var(--warning-bg, #fff4ce); border-color: var(--warning-border, #f0d78c); color: var(--warning-text, #6b5900); }
        .ma-banner a { color: inherit; font-weight: 600; }
        .ma-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
        .ma-card { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; margin-bottom: 16px; }
        .ma-card-head { padding: 12px 16px; border-bottom: 1px solid var(--border-soft, #eee); font-weight: 600; font-size: 14px; color: var(--text, #333); }
        .ma-card-head small { display: block; font-weight: 400; color: var(--text-muted, #666); margin-top: 2px; font-size: 12px; }
        .ma-card-body { padding: 12px 16px; }
        .ma-sec { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #666); margin: 14px 0 6px; }
        .ma-sec:first-child { margin-top: 0; }
        .ma-sec small { text-transform: none; letter-spacing: 0; font-weight: 400; }
        .ma-line { display: flex; align-items: center; gap: 12px; padding: 9px 10px; border: 1px solid var(--border-soft, #eee); border-radius: 6px; margin-bottom: 6px; background: var(--surface-2, #fafafa); }
        .ma-line.excl { border-left: 3px solid var(--danger-accent, #c42b1c); }
        .ma-line.dir  { border-left: 3px solid var(--accent, #0078d4); }
        .ma-line-main { flex: 1; min-width: 0; }
        .ma-line-label { font-weight: 600; font-size: 13px; color: var(--text, #333); overflow-wrap: anywhere; }
        .ma-line-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 2px; }
        .ma-line-warn { font-size: 12px; color: var(--warning-text, #6b5900); margin-top: 2px; }
        .ma-chip { font-size: 12px; padding: 2px 8px; border-radius: 10px; background: var(--accent-soft, #e8f4fd); color: var(--text, #333); white-space: nowrap; }
        .ma-empty { color: var(--text-muted, #666); font-size: 13px; padding: 6px 0; }
        .ma-btn { padding: 5px 12px; font-size: 12px; border-radius: 4px; border: 1px solid var(--border, #ccc); background: var(--surface, #fff); color: var(--text, #333); cursor: pointer; white-space: nowrap; }
        .ma-btn:hover { background: var(--surface-hover, #f3f3f3); }
        .ma-btn.primary { background: var(--accent, #0078d4); border-color: var(--accent, #0078d4); color: var(--on-accent, #fff); }
        .ma-btn.primary:hover { background: var(--accent-hover, #106ebe); }
        .ma-btn:disabled { opacity: .5; cursor: default; }
        .ma-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border-soft, #eee); padding: 0 16px; }
        .ma-tab { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 12px; font-size: 13px; color: var(--text-muted, #666); cursor: pointer; }
        .ma-tab.active { color: var(--accent, #0078d4); border-bottom-color: var(--accent, #0078d4); font-weight: 600; }
        .ma-search { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ccc); border-radius: 4px; font-size: 13px;
                     background: var(--surface, #fff); color: var(--text, #333); }
        .ma-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        .ma-table td { padding: 8px 6px; border-bottom: 1px solid var(--border-soft, #eee); vertical-align: middle; color: var(--text, #333); }
        .ma-table td.acts { text-align: right; white-space: nowrap; }
        .ma-table td.acts .ma-btn + .ma-btn { margin-left: 6px; }
        .ma-muted { color: var(--text-muted, #666); font-size: 12px; }
        .ma-state { font-size: 12px; font-weight: 600; }
        .ma-state.added { color: var(--success-text, #107c10); }
        .ma-state.excluded { color: var(--danger-text, #b3261e); }
        .ma-flag { font-size: 11px; padding: 1px 6px; border-radius: 8px; background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); margin-left: 6px; }
        .ma-pager { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; font-size: 12px; color: var(--text-muted, #666); }
        .ma-pager .ma-btn + .ma-btn { margin-left: 6px; }
        .ma-option { border: 1px solid var(--border-soft, #eee); border-radius: 6px; padding: 12px; margin-bottom: 10px; display: flex; gap: 12px; align-items: center; }
        .ma-option select { padding: 6px 8px; border: 1px solid var(--border, #ccc); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; }
        .ma-team { list-style: none; margin: 8px 0 0; padding: 0; max-height: 60vh; overflow: auto; }
        .ma-team li { padding: 7px 2px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 13px; color: var(--text, #333); }
        .ma-team li .ma-muted { display: block; }
        .ma-team-side { position: sticky; top: 0; }
        @media (max-width: 960px) {
            .ma-grid { grid-template-columns: 1fr; }
            .ma-team-side { position: static; }
            .ma-wrap { padding: 16px; }
        }
    </style>

    <div class="ma-scroll">
    <div class="ma-wrap">
        <a class="ma-back" href="<?php echo htmlspecialchars($backUrl); ?>">&larr; <?php echo htmlspecialchars($T['back']); ?></a>
        <div class="ma-top">
            <div>
                <h1 class="ma-name" id="maName"><?php echo htmlspecialchars($T['page_title']); ?></h1>
                <div class="ma-sub" id="maSub"></div>
            </div>
            <div class="ma-stat" id="maStat" hidden>
                <div class="ma-stat-label"><?php echo htmlspecialchars($T['can_see_label']); ?></div>
                <div class="ma-stat-num" id="maStatNum"></div>
            </div>
        </div>
        <div id="maBanners"></div>

        <div class="ma-grid" id="maGrid" hidden>
            <div>
                <div class="ma-card">
                    <div class="ma-card-head"><?php echo htmlspecialchars($T['sources_heading']); ?></div>
                    <div class="ma-card-body" id="maSources"></div>
                </div>

                <div class="ma-card" id="maAddCard" hidden>
                    <div class="ma-card-head"><?php echo htmlspecialchars($T['add_heading']); ?></div>
                    <div class="ma-tabs" role="tablist">
                        <button type="button" class="ma-tab active" data-kind="user"><?php echo htmlspecialchars($T['tab_people']); ?></button>
                        <button type="button" class="ma-tab" data-kind="group"><?php echo htmlspecialchars($T['tab_groups']); ?></button>
                        <button type="button" class="ma-tab" data-kind="department"><?php echo htmlspecialchars($T['tab_departments']); ?></button>
                        <button type="button" class="ma-tab" data-kind="other"><?php echo htmlspecialchars($T['tab_other']); ?></button>
                    </div>
                    <div class="ma-card-body">
                        <div id="maSearchPane">
                            <input type="search" class="ma-search" id="maQ" autocomplete="off">
                            <div id="maResults"></div>
                        </div>
                        <div id="maOtherPane" hidden></div>
                    </div>
                </div>
            </div>

            <div class="ma-team-side">
                <div class="ma-card">
                    <div class="ma-card-head"><?php echo htmlspecialchars($T['team_heading']); ?></div>
                    <div class="ma-card-body">
                        <input type="search" class="ma-search" id="maTeamFilter" placeholder="<?php echo htmlspecialchars($T['team_filter']); ?>" autocomplete="off">
                        <ul class="ma-team" id="maTeam"></ul>
                        <div class="ma-muted" id="maTeamMore" style="margin-top:8px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script>
    (function () {
        const API = <?php echo json_encode($base . 'api/tickets/manager_access.php'); ?>;
        const SYSTEM_URL = <?php echo json_encode($base . 'system/managers/'); ?>;
        const MANAGER = <?php echo (int)$managerId; ?>;
        const T = <?php echo json_encode($T, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        const fmt = (s, p) => String(s).replace(/\{(\w+)\}/g, (_, k) => (p && k in p) ? p[k] : '{' + k + '}');
        const people = n => Number(n) === 1 ? T.people_one : fmt(T.people, { count: n });
        const toast = (m, k) => { if (typeof window.showToast === 'function') window.showToast(m, k); };
        // Stored in UTC (#126); shown as a date in the analyst's own zone.
        const day = s => {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            return isNaN(d) ? s : d.toLocaleDateString();
        };

        let S = null;                  // the manager's state from the server
        let kind = 'user', page = 1, timer = null, seq = 0;

        async function call(params, body) {
            const r = body
                ? await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                     body: JSON.stringify(Object.assign({ manager: MANAGER }, body)) })
                : await fetch(API + '?' + new URLSearchParams(Object.assign({ manager: MANAGER }, params)), { credentials: 'same-origin' });
            return r.json();
        }

        function lineLabel(l) {
            switch (l.grant_type) {
                case 'everyone':   return T.line_everyone;
                case 'user':       return l.label == null ? T.line_gone_user : fmt(T.line_user, { name: l.label });
                case 'group':      return l.label == null ? T.line_gone_group : fmt(T.line_group, { name: l.label });
                case 'department': return fmt(T.line_department, { name: l.target_value });
                case 'reports':    return l.target_value === 'all' ? T.line_reports_all : T.line_reports;
            }
            return l.grant_type;
        }

        function lineHtml(l) {
            const meta = l.added_by ? fmt(T.line_meta, { name: l.added_by, date: day(l.added) }) : fmt(T.line_meta_nobody, { date: day(l.added) });
            const warn = (l.grant_type === 'department' && !l.members) ? '<div class="ma-line-warn">⚠ ' + esc(T.dept_empty) + '</div>' : '';
            return '<div class="ma-line' + (l.is_exclusion ? ' excl' : '') + '">'
                 + '<div class="ma-line-main"><div class="ma-line-label">' + esc(lineLabel(l)) + '</div>'
                 + '<div class="ma-line-meta">' + esc(meta) + '</div>' + warn + '</div>'
                 + '<span class="ma-chip">' + esc(people(l.members)) + '</span>'
                 + (S.can_edit ? '<button type="button" class="ma-btn" data-remove="' + l.id + '">' + esc(T.remove) + '</button>' : '')
                 + '</div>';
        }

        function paint() {
            const m = S.manager;
            document.title = T.page_title + ' - ' + m.name;
            $('maName').textContent = m.name;
            $('maSub').textContent = [m.job_title, m.department, m.company, m.email].filter(Boolean).join(' · ');

            let b = '';
            if (S.needs_verify) b += '<div class="ma-banner warn">' + esc(T.needs_verify) + '</div>';
            else {
                if (!m.is_active) b += '<div class="ma-banner warn">' + esc(fmt(T.left_banner, { name: m.name })) + '</div>';
                if (!S.settings.enabled) {
                    const txt = esc(fmt(T.off_banner, { name: m.name }));
                    b += '<div class="ma-banner info">' + (S.is_admin ? txt.replace('System → Managers', '<a href="' + SYSTEM_URL + '">System → Managers</a>') : txt) + '</div>';
                }
                if (!S.can_edit) b += '<div class="ma-banner info">' + esc(T.readonly) + '</div>';
            }
            $('maBanners').innerHTML = b;
            if (S.needs_verify) { $('maGrid').hidden = true; $('maStat').hidden = true; return; }

            $('maStat').hidden = false;
            $('maStatNum').textContent = people(S.team_total);
            $('maGrid').hidden = false;
            $('maAddCard').hidden = !S.can_edit;

            // Where access comes from: the Manager field, then lines, then exclusions.
            let h = '';
            if (S.settings.directory) {
                h += '<div class="ma-line dir"><div class="ma-line-main"><div class="ma-line-label">'
                   + esc(S.settings.directory_depth === 'all' ? T.directory_all : T.directory_direct) + '</div>'
                   + '<div class="ma-line-meta">' + esc(T.directory_note) + '</div></div>'
                   + '<span class="ma-chip">' + esc(people(S.reports)) + '</span></div>';
            } else {
                h += '<div class="ma-empty">' + esc(T.directory_off) + '</div>';
            }
            const grants = S.lines.filter(l => !l.is_exclusion), excl = S.lines.filter(l => l.is_exclusion);
            h += '<div class="ma-sec">' + esc(T.lines_heading) + '</div>';
            h += grants.length ? grants.map(lineHtml).join('') : '<div class="ma-empty">' + esc(T.lines_none) + '</div>';
            h += '<div class="ma-sec">' + esc(T.excl_heading) + ' <small>- ' + esc(T.excl_desc) + '</small></div>';
            h += excl.length ? excl.map(lineHtml).join('') : '<div class="ma-empty">' + esc(T.excl_none) + '</div>';
            $('maSources').innerHTML = h;

            paintTeam();
            if (S.can_edit) { if (kind === 'other') paintOther(); else search(); }
        }

        function paintTeam() {
            const q = $('maTeamFilter').value.trim().toLowerCase();
            const list = S.team.filter(p => !q || (p.name || '').toLowerCase().includes(q) || (p.department || '').toLowerCase().includes(q));
            $('maTeam').innerHTML = list.length
                ? list.map(p => '<li>' + esc(p.name) + (p.is_active ? '' : '<span class="ma-flag">' + esc(T.flag_left) + '</span>')
                    + (p.department ? '<span class="ma-muted">' + esc(p.department) + '</span>' : '') + '</li>').join('')
                : '<li class="ma-empty">' + esc(T.team_none) + '</li>';
            $('maTeamMore').textContent = S.team_total > S.team.length ? fmt(T.team_more, { shown: S.team.length, total: S.team_total }) : '';
        }

        // What this row already is, so it is never added twice.
        function existing(k, row) {
            return S.lines.find(l => l.grant_type === k && (k === 'department'
                ? String(l.target_value).trim().toLowerCase() === String(row.name).trim().toLowerCase()
                : l.target_id === row.id));
        }

        async function search() {
            const mine = ++seq;
            const ph = { user: T.search_people, group: T.search_groups, department: T.search_departments }[kind];
            $('maQ').placeholder = ph;
            const d = await call({ action: 'search', kind: kind, q: $('maQ').value.trim(), page: page });
            if (mine !== seq) return;                       // a newer search has started
            if (!d.success) { $('maResults').innerHTML = '<div class="ma-empty">' + esc(d.error || '') + '</div>'; return; }
            if (!d.rows.length) { $('maResults').innerHTML = '<div class="ma-empty" style="margin-top:10px;">' + esc(T.no_results) + '</div>'; return; }
            const rows = d.rows.map((r, i) => {
                let main;
                if (kind === 'user') {
                    main = '<strong>' + esc(r.name) + '</strong>' + (r.is_active ? '' : '<span class="ma-flag">' + esc(T.flag_left) + '</span>')
                         + '<div class="ma-muted">' + esc([r.job_title, r.department, r.email].filter(Boolean).join(' · ')) + '</div>';
                } else if (kind === 'group') {
                    main = '<strong>' + esc(r.name) + '</strong>' + (r.description ? '<div class="ma-muted">' + esc(r.description) + '</div>' : '');
                } else {
                    main = '<strong>' + esc(r.name) + '</strong>';
                }
                const count = kind === 'user' ? '' : '<span class="ma-chip">' + esc(people(r.members)) + '</span>';
                const ex = existing(kind, r);
                const acts = ex
                    ? '<span class="ma-state ' + (ex.is_exclusion ? 'excluded">' + esc(T.is_excluded) : 'added">' + esc(T.is_added)) + '</span>'
                    : '<button type="button" class="ma-btn primary" data-add="' + i + '">' + esc(T.add) + '</button>'
                    + '<button type="button" class="ma-btn" data-exclude="' + i + '">' + esc(T.exclude) + '</button>';
                return '<tr><td>' + main + '</td><td>' + count + '</td><td class="acts">' + acts + '</td></tr>';
            }).join('');
            const pages = Math.max(1, Math.ceil(d.total / d.per_page));
            $('maResults').innerHTML = '<table class="ma-table"><tbody>' + rows + '</tbody></table>'
                + '<div class="ma-pager"><span>' + esc(fmt(T.page_of, { page: d.page, pages: pages, total: d.total })) + '</span><span>'
                + '<button type="button" class="ma-btn" data-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>' + esc(T.prev) + '</button>'
                + '<button type="button" class="ma-btn" data-page="' + (d.page + 1) + '"' + (d.page >= pages ? ' disabled' : '') + '>' + esc(T.next) + '</button>'
                + '</span></div>';
            $('maResults')._rows = d.rows;
        }

        function paintOther() {
            const has = t => S.lines.find(l => !l.is_exclusion && l.grant_type === t);
            const everyone = has('everyone'), reports = has('reports');
            const desc = S.manager.company ? fmt(T.everyone_desc, { company: S.manager.company }) : T.everyone_desc_one;
            $('maOtherPane').innerHTML =
                  '<div class="ma-option"><div class="ma-line-main"><div class="ma-line-label">' + esc(T.everyone_title) + '</div>'
                + '<div class="ma-line-meta">' + esc(desc) + '</div></div>'
                + (everyone ? '<span class="ma-state added">' + esc(T.is_added) + '</span>'
                            : '<button type="button" class="ma-btn primary" data-other="everyone">' + esc(T.add) + '</button>') + '</div>'
                + '<div class="ma-option"><div class="ma-line-main"><div class="ma-line-label">' + esc(T.reports_title) + '</div>'
                + '<div class="ma-line-meta">' + esc(T.reports_desc) + '</div></div>'
                + '<select id="maDepth"><option value="">' + esc(T.reports_direct) + '</option><option value="all">' + esc(T.reports_all) + '</option></select>'
                + '<button type="button" class="ma-btn primary" data-other="reports">' + esc(T.add) + '</button></div>';
            if (reports) $('maDepth').value = reports.target_value === 'all' ? 'all' : '';
        }

        async function write(body) {
            const d = await call(null, body);
            if (!d.success) { toast(fmt(T.save_failed, { error: d.error || '' }), 'error'); return; }
            S = d;
            paint();
        }

        document.addEventListener('click', e => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.classList.contains('ma-tab')) {
                document.querySelectorAll('.ma-tab').forEach(x => x.classList.toggle('active', x === t));
                kind = t.dataset.kind; page = 1;
                $('maSearchPane').hidden = kind === 'other';
                $('maOtherPane').hidden = kind !== 'other';
                if (kind === 'other') paintOther(); else { $('maQ').value = ''; $('maResults').innerHTML = ''; search(); }
                return;
            }
            if (t.dataset.remove) { write({ action: 'remove', line_id: Number(t.dataset.remove) }); return; }
            if (t.dataset.page)   { page = Number(t.dataset.page); search(); return; }
            if (t.dataset.add !== undefined || t.dataset.exclude !== undefined) {
                const row = $('maResults')._rows[Number(t.dataset.add ?? t.dataset.exclude)];
                t.disabled = true;
                write({ action: 'add', grant_type: kind, target_id: row.id || 0, target_value: kind === 'department' ? row.name : '',
                        is_exclusion: t.dataset.exclude !== undefined });
                return;
            }
            if (t.dataset.other === 'everyone') { write({ action: 'add', grant_type: 'everyone' }); return; }
            if (t.dataset.other === 'reports')  { write({ action: 'add', grant_type: 'reports', target_value: $('maDepth').value }); return; }
        });
        $('maQ').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; search(); }, 250); });
        $('maTeamFilter').addEventListener('input', paintTeam);

        (async function () {
            try {
                const d = await call({ action: 'get' });
                if (!d.success) { $('maBanners').innerHTML = '<div class="ma-banner warn">' + esc(d.error || T.load_failed) + '</div>'; return; }
                S = d;
                paint();
            } catch (e) {
                $('maBanners').innerHTML = '<div class="ma-banner warn">' + esc(T.load_failed) + '</div>';
            }
        })();
    })();
    </script>
    <?php
}
