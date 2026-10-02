/**
 * LMS -> Tests (competency tests). One file for the three analyst pages, chosen
 * by <body data-ct-page="index|edit|result">:
 *   index  - Tests, Question bank and Candidates tabs
 *   edit   - the test builder: role, skills, questions, sending to candidates
 *   result - one candidate's result
 * Shared pieces: the question editor and the bank picker (modals built here, so
 * no page carries a copy of their markup). All writes go to api/lms/tests.php.
 */
(function () {
    'use strict';

    var BASE = window.CT_BASE || '/';
    var API = BASE + 'api/lms/tests.php';
    var DIFFS = ['beginner', 'intermediate', 'advanced'];
    var FORMATS = ['choice', 'graded'];

    function L(key, en, p) { return window.tf ? window.tf('lms.tests.' + key, en, p) : en.replace(/\{(\w+)\}/g, function (m, k) { return p && p[k] != null ? p[k] : m; }); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function $(id) { return document.getElementById(id); }

    function get(params) {
        return fetch(API + '?' + new URLSearchParams(params), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function post(body) {
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    var toastEl;
    function toast(msg, kind) {
        if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'toast'; document.body.appendChild(toastEl); }
        toastEl.textContent = msg;
        toastEl.className = 'toast show ' + (kind || 'success');
        clearTimeout(toastEl._t);
        toastEl._t = setTimeout(function () { toastEl.className = 'toast'; }, kind === 'error' ? 5000 : 2600);
    }
    function fail(d) { toast((d && d.error) || L('failed', 'That did not work.'), 'error'); }

    function diffLabel(d) { return { beginner: L('diff_beginner', 'Beginner'), intermediate: L('diff_intermediate', 'Intermediate'), advanced: L('diff_advanced', 'Advanced') }[d] || d; }
    function fmtLabel(f) { return f === 'graded' ? L('fmt_graded', 'Graded (best answer)') : L('fmt_choice', 'Multiple choice'); }
    function statusLabel(s) { return { draft: L('st_draft', 'Draft'), approved: L('st_approved', 'Approved'), hidden: L('st_hidden', 'Hidden') }[s] || s; }
    function stateLabel(s) {
        return { ready: L('state_ready', 'Not started'), in_progress: L('state_in_progress', 'In progress'), submitted: L('state_submitted', 'Finished'),
                 expired: L('state_expired', 'Link expired'), cancelled: L('state_cancelled', 'Cancelled'), timeup: L('state_in_progress', 'In progress') }[s] || s;
    }
    function when(utc) { if (!utc) return ''; return window.fmtDateTime ? window.fmtDateTime(utc) : (window.Tz && Tz.formatDateTime ? Tz.formatDateTime(utc) : utc.slice(0, 16)); }
    function pct(n) { return n == null ? '' : (Math.round(n * 10) / 10) + '%'; }
    function opts(list, sel, label) { return list.map(function (v) { return '<option value="' + v + '"' + (v === sel ? ' selected' : '') + '>' + esc(label(v)) + '</option>'; }).join(''); }

    // ------------------------------------------------------------------ modal shell
    function modal(id, title, bodyHtml, actionsHtml) {
        var m = $(id);
        if (!m) {
            m = document.createElement('div');
            m.className = 'modal'; m.id = id;
            m.innerHTML = '<div class="modal-content ct-modal"><div class="modal-header"></div><div class="ct-modal-body"></div></div>';
            document.body.appendChild(m);
            m.addEventListener('mousedown', function (e) { if (e.target === m) m.classList.remove('active'); });
        }
        m.querySelector('.modal-header').textContent = title;
        m.querySelector('.ct-modal-body').innerHTML = bodyHtml + '<div class="modal-actions">' + actionsHtml + '</div>';
        m.classList.add('active');
        return m;
    }
    function closeModal(m) { m.classList.remove('active'); }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(function (m) { m.classList.remove('active'); });
    });

    // ------------------------------------------------------------------ question card
    function questionCard(q, o) {
        o = o || {};
        var max = Math.max.apply(null, q.answers.map(function (a) { return a.marks; }).concat([0]));
        var ans = q.answers.map(function (a) {
            return '<li><span class="ct-mark' + (a.marks === max && max > 0 ? ' best' : '') + '">' + a.marks + '</span><span>' + esc(a.text) + '</span></li>';
        }).join('');
        var acts = (o.actions || []).map(function (a) {
            return '<button class="btn btn-sm ' + (a.primary ? 'btn-primary' : 'btn-secondary') + '" data-act="' + a.act + '" data-id="' + q.id + '">' + esc(a.label) + '</button>';
        }).join('');
        return '<div class="ct-q is-' + q.status + '" data-id="' + q.id + '"><div class="ct-q-top">'
            + (o.pick ? '<input type="checkbox" class="ct-pick" value="' + q.id + '"' + (o.picked ? ' checked' : '') + '>' : '')
            + (o.num ? '<span class="ct-q-num">' + o.num + '.</span>' : '')
            + '<div class="ct-q-body"><div class="ct-chips">'
            + '<span class="ct-chip">' + esc(q.skill) + '</span><span class="ct-chip">' + esc(diffLabel(q.difficulty)) + '</span>'
            + '<span class="ct-chip">' + esc(fmtLabel(q.format)) + '</span><span class="ct-chip ' + q.status + '">' + esc(statusLabel(q.status)) + '</span>'
            + (o.usedIn != null ? '<span class="ct-chip">' + esc(L('used_in', 'In {n} test(s)', { n: o.usedIn })) + '</span>' : '')
            + '</div><p class="ct-q-text">' + esc(q.question_text) + '</p><ul class="ct-answers">' + ans + '</ul>'
            + (q.explanation ? '<div class="ct-why">' + esc(q.explanation) + '</div>' : '')
            + '</div>' + (acts ? '<div class="ct-q-actions">' + acts + '</div>' : '') + '</div></div>';
    }

    // ------------------------------------------------------------------ question editor
    var gradedDefault = [5, 3, 1, 0];
    function editQuestion(q, onSaved, testId) {
        q = q || { skill: '', difficulty: 'intermediate', format: 'choice', question_text: '', explanation: '', status: 'approved',
                   answers: [{ text: '', marks: 1 }, { text: '', marks: 0 }, { text: '', marks: 0 }, { text: '', marks: 0 }] };
        var body = '<div class="ct-grid2"><div class="form-group"><label>' + esc(L('f_skill', 'Skill')) + '</label><input id="qeSkill" value="' + esc(q.skill) + '" maxlength="120"></div>'
            + '<div class="form-group"><label>' + esc(L('f_difficulty', 'Difficulty')) + '</label><select id="qeDiff">' + opts(DIFFS, q.difficulty, diffLabel) + '</select></div>'
            + '<div class="form-group"><label>' + esc(L('f_format', 'Format')) + '</label><select id="qeFmt">' + opts(FORMATS, q.format, fmtLabel) + '</select></div></div>'
            + '<div class="form-group"><label>' + esc(L('f_question', 'Question')) + '</label><textarea id="qeText" rows="4">' + esc(q.question_text) + '</textarea></div>'
            + '<div class="form-group"><label id="qeAnsLabel"></label><div id="qeAnswers"></div>'
            + '<button type="button" class="btn btn-sm btn-secondary" id="qeAdd">' + esc(L('add_answer', 'Add')) + '</button></div>'
            + '<div class="form-group"><label>' + esc(L('f_explanation', 'Why the best answer is best (only you see this)')) + '</label><textarea id="qeWhy" rows="2">' + esc(q.explanation || '') + '</textarea></div>'
            + '<div class="form-group"><label>' + esc(L('f_status', 'Status')) + '</label><select id="qeStatus">' + opts(['draft', 'approved', 'hidden'], q.status, statusLabel) + '</select></div>';
        var m = modal('ctQuestionModal', q.id ? L('edit_question', 'Edit question') : L('new_question', 'New question'), body,
            '<button class="btn btn-secondary" data-close>' + esc(L('cancel', 'Cancel')) + '</button><button class="btn btn-primary" id="qeSave">' + esc(L('save', 'Save')) + '</button>');
        var answers = q.answers.map(function (a) { return { text: a.text, marks: a.marks }; });

        function readRows() {
            m.querySelectorAll('.ct-ans-row').forEach(function (row, i) {
                answers[i].text = row.querySelector('.qeT').value;
                var mk = row.querySelector('.qeM');
                if (mk.type === 'radio') answers[i].marks = mk.checked ? 1 : 0;
                else answers[i].marks = parseInt(mk.value, 10) || 0;
            });
        }
        function draw() {
            var graded = $('qeFmt').value === 'graded';
            $('qeAnsLabel').textContent = graded ? L('answers_graded', 'Answers and their marks (best answer scores most)') : L('answers_choice', 'Answers (tick the correct one)');
            $('qeAnswers').innerHTML = answers.map(function (a, i) {
                return graded
                    ? '<div class="ct-ans-row"><input class="qeT" value="' + esc(a.text) + '"><input class="qeM" type="number" min="0" max="100" value="' + a.marks + '"><button type="button" class="ct-x" data-rm="' + i + '">×</button></div>'
                    : '<div class="ct-ans-row choice"><input class="qeM" type="radio" name="qeCorrect"' + (a.marks > 0 ? ' checked' : '') + '><input class="qeT" value="' + esc(a.text) + '"><button type="button" class="ct-x" data-rm="' + i + '">×</button></div>';
            }).join('');
        }
        draw();
        $('qeFmt').addEventListener('change', function () {
            readRows();
            if ($('qeFmt').value === 'graded') {
                // Switching a choice question to graded: the right answer becomes the best.
                answers.sort(function (a, b) { return b.marks - a.marks; });
                answers.forEach(function (a, i) { a.marks = gradedDefault[i] != null ? gradedDefault[i] : 0; });
            } else {
                var best = Math.max.apply(null, answers.map(function (a) { return a.marks; }));
                var done = false;
                answers.forEach(function (a) { a.marks = (!done && a.marks === best) ? 1 : 0; if (a.marks) done = true; });
            }
            draw();
        });
        $('qeAdd').addEventListener('click', function () { readRows(); if (answers.length < 6) { answers.push({ text: '', marks: 0 }); draw(); } });
        $('qeAnswers').addEventListener('click', function (e) {
            var rm = e.target.getAttribute('data-rm');
            if (rm == null) return;
            readRows(); if (answers.length > 2) { answers.splice(+rm, 1); draw(); }
        });
        m.querySelector('[data-close]').addEventListener('click', function () { closeModal(m); });
        $('qeSave').addEventListener('click', function () {
            readRows();
            post({ action: 'save_question', test_id: testId || null, question: {
                id: q.id || 0, skill: $('qeSkill').value, difficulty: $('qeDiff').value, format: $('qeFmt').value,
                question_text: $('qeText').value, explanation: $('qeWhy').value, status: $('qeStatus').value, answers: answers
            } }).then(function (d) {
                if (!d.success) return fail(d);
                closeModal(m); toast(L('saved', 'Saved')); if (onSaved) onSaved(d.id);
            });
        });
    }

    // ------------------------------------------------------------------ bank (shared by the tab and the picker)
    function bankFilters(prefix, skills, withStatus) {
        return '<div class="ct-filters">'
            + '<input type="search" id="' + prefix + 'Q" placeholder="' + esc(L('search_ph', 'Search questions, answers, skills or roles…')) + '">'
            + '<select id="' + prefix + 'Skill"><option value="">' + esc(L('all_skills', 'All skills')) + '</option>'
            + skills.map(function (s) { return '<option value="' + esc(s.skill) + '">' + esc(s.skill) + ' (' + s.n + ')</option>'; }).join('') + '</select>'
            + '<select id="' + prefix + 'Diff"><option value="">' + esc(L('all_difficulties', 'Any difficulty')) + '</option>' + opts(DIFFS, '', diffLabel) + '</select>'
            + '<select id="' + prefix + 'Fmt"><option value="">' + esc(L('all_formats', 'Any format')) + '</option>' + opts(FORMATS, '', fmtLabel) + '</select>'
            + (withStatus ? '<select id="' + prefix + 'Status"><option value="usable">' + esc(L('status_usable', 'Draft and approved')) + '</option>'
                + opts(['draft', 'approved', 'hidden'], '', statusLabel) + '<option value="all">' + esc(L('status_all', 'Everything')) + '</option></select>' : '')
            + '</div>';
    }
    function bankQuery(prefix, statusOverride) {
        return { action: 'bank', q: $(prefix + 'Q').value.trim(), skill: $(prefix + 'Skill').value, difficulty: $(prefix + 'Diff').value,
                 format: $(prefix + 'Fmt').value, status: statusOverride || ($(prefix + 'Status') ? $(prefix + 'Status').value : 'usable') };
    }
    function wireFilters(prefix, run) {
        var t;
        ['Q', 'Skill', 'Diff', 'Fmt', 'Status'].forEach(function (k) {
            var el = $(prefix + k);
            if (!el) return;
            el.addEventListener(k === 'Q' ? 'input' : 'change', function () { clearTimeout(t); t = setTimeout(run, k === 'Q' ? 250 : 0); });
        });
    }

    function pickFromBank(testId, already, done) {
        get({ action: 'skills' }).then(function (sd) {
            var m = modal('ctPickModal', L('add_from_bank', 'Add questions from the bank'),
                '<p class="ct-note">' + esc(L('picker_hint', 'Approved and draft questions are shown; hidden ones never go on a test. Tick the ones you want.')) + '</p>'
                + bankFilters('pk', sd.skills || [], false) + '<div class="ct-picker-list" id="pkList"></div>',
                '<span class="ct-note" id="pkCount" style="margin-right:auto"></span><button class="btn btn-secondary" data-close>' + esc(L('cancel', 'Cancel')) + '</button>'
                + '<button class="btn btn-primary" id="pkAdd">' + esc(L('add', 'Add')) + '</button>');
            var picked = {};
            function count() { $('pkCount').textContent = L('n_selected', '{n} selected', { n: Object.keys(picked).length }); }
            function run() {
                get(bankQuery('pk', 'usable')).then(function (d) {
                    var list = (d.questions || []).filter(function (q) { return already.indexOf(q.id) === -1; });
                    $('pkList').innerHTML = list.length ? list.map(function (q) { return questionCard(q, { pick: true, picked: !!picked[q.id] }); }).join('')
                        : '<div class="ct-empty">' + esc(L('no_matches', 'Nothing matches.')) + '</div>';
                });
            }
            $('pkList').addEventListener('change', function (e) {
                if (!e.target.classList.contains('ct-pick')) return;
                if (e.target.checked) picked[e.target.value] = 1; else delete picked[e.target.value];
                count();
            });
            m.querySelector('[data-close]').addEventListener('click', function () { closeModal(m); });
            $('pkAdd').addEventListener('click', function () {
                var ids = Object.keys(picked).map(Number);
                if (!ids.length) return closeModal(m);
                post({ action: 'add_questions', test_id: testId, question_ids: ids }).then(function (d) {
                    if (!d.success) return fail(d);
                    closeModal(m); toast(L('added_n', '{n} added', { n: d.added })); done();
                });
            });
            wireFilters('pk', run); count(); run();
        });
    }

    // ------------------------------------------------------------------ candidates table (index tab + builder)
    function candidatesTable(rows, showTest) {
        if (!rows.length) return '<div class="ct-empty">' + esc(showTest ? L('no_candidates_any', 'No candidates yet. Open a test and press Send.') : L('no_candidates', 'Nobody has been sent this yet.')) + '</div>';
        return '<table class="lms-table"><thead><tr><th>' + esc(L('col_candidate', 'Candidate')) + '</th>'
            + (showTest ? '<th>' + esc(L('col_test', 'Test')) + '</th>' : '')
            + '<th>' + esc(L('col_status', 'Status')) + '</th><th>' + esc(L('col_score', 'Score')) + '</th><th>' + esc(L('col_sent', 'Sent')) + '</th><th>'
            + esc(L('col_finished', 'Finished')) + '</th><th></th></tr></thead><tbody>'
            + rows.map(function (s) {
                var acts = '<a class="btn btn-sm btn-secondary" href="' + BASE + 'lms/tests/result.php?id=' + s.id + '">' + esc(L('open', 'Open')) + '</a> ';
                if (s.state === 'ready' || s.state === 'expired') acts += '<button class="btn btn-sm btn-secondary" data-sit="new_link" data-id="' + s.id + '">' + esc(L('new_link', 'Link')) + '</button> ';
                if (s.state === 'ready' || s.state === 'in_progress') acts += '<button class="btn btn-sm btn-secondary" data-sit="cancel" data-id="' + s.id + '">' + esc(L('cancel', 'Cancel')) + '</button> ';
                acts += '<button class="btn btn-sm btn-secondary" data-sit="delete" data-id="' + s.id + '">' + esc(L('delete', 'Delete')) + '</button>';
                return '<tr><td><strong>' + esc(s.candidate_name) + '</strong>' + (s.candidate_email ? '<br><span class="ct-note">' + esc(s.candidate_email) + '</span>' : '') + '</td>'
                    + (showTest ? '<td>' + esc(s.test_title || '') + '</td>' : '')
                    + '<td><span class="ct-state ' + s.state + '">' + esc(stateLabel(s.state)) + '</span>'
                    + (s.finish_reason === 'time_up' ? ' <span class="ct-note">' + esc(L('time_up', 'time ran out')) + '</span>' : '') + '</td>'
                    + '<td>' + (s.score_percent != null ? '<strong>' + pct(s.score_percent) + '</strong>' : '') + '</td>'
                    + '<td>' + esc(when(s.created_datetime)) + '</td><td>' + esc(when(s.submitted_datetime)) + '</td>'
                    + '<td style="white-space:nowrap">' + acts + '</td></tr>';
            }).join('') + '</tbody></table>';
    }
    function showLink(link, note, emailed) {
        var m = modal('ctLinkModal', L('link_title', 'The candidate\'s link'),
            (emailed === true ? '<p>' + esc(L('link_emailed', 'Emailed to the candidate. A copy is below in case you need it.')) + '</p>' : '')
            + (emailed === false ? '<p style="color:#c33">' + esc(L('link_email_failed', 'The email could not be sent - copy the link below and send it yourself.')) + '</p>' : '')
            + '<div class="ct-link-box"><input readonly id="ctLinkVal"><button class="btn btn-primary" id="ctLinkCopy">' + esc(L('copy', 'Copy')) + '</button></div>'
            + '<p class="ct-note">' + esc(note) + '</p>',
            '<button class="btn btn-secondary" data-close>' + esc(L('close', 'Close')) + '</button>');
        $('ctLinkVal').value = link;
        $('ctLinkCopy').addEventListener('click', function () {
            (window.copyToClipboard ? window.copyToClipboard(link) : Promise.resolve(false)).then(function (ok) {
                if (ok) toast(L('copied', 'Copied')); else { $('ctLinkVal').select(); toast(L('copy_manual', 'Press Ctrl+C to copy'), 'error'); }
            });
        });
        m.querySelector('[data-close]').addEventListener('click', function () { closeModal(m); });
    }
    function linkNote(days) { return L('link_note', 'This is the only time the link is shown - it is not stored. It works once, for one person, and stays open for {days} day(s) until they press Start. Lost it? Use Link on their row to make a new one.', { days: days }); }
    function wireSittingActions(host, reload) {
        host.addEventListener('click', function (e) {
            var b = e.target.closest('[data-sit]');
            if (!b) return;
            var id = +b.dataset.id, act = b.dataset.sit;
            if (act === 'new_link') {
                if (!confirm(L('confirm_new_link', 'Make a new link? The old one stops working.'))) return;
                post({ action: 'new_link', id: id }).then(function (d) { if (!d.success) return fail(d); showLink(d.link, linkNote(d.expires_days)); reload(); });
            } else if (act === 'cancel') {
                if (!confirm(L('confirm_cancel', 'Cancel this candidate\'s test? Their link stops working.'))) return;
                post({ action: 'cancel_sitting', id: id }).then(function (d) { if (!d.success) return fail(d); reload(); });
            } else if (act === 'delete') {
                if (!confirm(L('confirm_delete_sitting', 'Delete this candidate and their result for good?'))) return;
                post({ action: 'delete_sitting', id: id }).then(function (d) { if (!d.success) return fail(d); toast(L('deleted', 'Deleted')); reload(); });
            }
        });
    }
    function sendToCandidate(testId, canEmail, done) {
        var m = modal('ctSendModal', L('send_title', 'Send to a candidate'),
            '<div class="form-group"><label>' + esc(L('f_cand_name', 'Candidate\'s name')) + '</label><input id="sdName" maxlength="200"></div>'
            + '<div class="form-group"><label>' + esc(L('f_cand_email', 'Email (optional)')) + '</label><input id="sdEmail" type="email" maxlength="255"></div>'
            + '<label class="ct-check"><input type="checkbox" id="sdSend"' + (canEmail ? ' checked' : ' disabled') + '> ' + esc(L('email_link', 'Email them the link')) + '</label>'
            + (canEmail ? '' : '<p class="ct-note">' + esc(L('no_mailbox', 'No mailbox can send email yet, so copy the link and send it yourself.')) + '</p>')
            + '<p class="ct-note">' + esc(L('send_freeze', 'The test is frozen for this candidate as it is now - later edits do not change their paper.')) + '</p>',
            '<button class="btn btn-secondary" data-close>' + esc(L('cancel', 'Cancel')) + '</button><button class="btn btn-primary" id="sdGo">' + esc(L('send', 'Send')) + '</button>');
        m.querySelector('[data-close]').addEventListener('click', function () { closeModal(m); });
        $('sdName').focus();
        $('sdGo').addEventListener('click', function () {
            var b = this; b.disabled = true;
            post({ action: 'create_sitting', test_id: testId, candidate_name: $('sdName').value, candidate_email: $('sdEmail').value,
                   send_email: $('sdSend').checked }).then(function (d) {
                b.disabled = false;
                if (!d.success) return fail(d);
                closeModal(m); showLink(d.link, linkNote(d.expires_days), d.emailed); done();
            });
        });
    }

    // ================================================================== INDEX
    function initIndex() {
        var tabs = document.querySelectorAll('.lms-tab');
        function show(tab) {
            tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === tab); });
            document.querySelectorAll('.ct-panel').forEach(function (p) { p.hidden = p.id !== 'panel-' + tab; });
            if (history.replaceState) history.replaceState(null, '', '#' + tab);
            ({ tests: loadTests, bank: loadBank, candidates: loadCandidates })[tab]();
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.tab); }); });

        function loadTests() {
            get({ action: 'tests' }).then(function (d) {
                if (!d.success) return fail(d);
                var rows = d.tests || [];
                $('testsList').innerHTML = rows.length ? '<table class="lms-table"><thead><tr><th>' + esc(L('col_title', 'Test')) + '</th><th>' + esc(L('col_skills', 'Skills'))
                    + '</th><th>' + esc(L('col_questions', 'Questions')) + '</th><th>' + esc(L('col_candidates', 'Candidates')) + '</th><th></th></tr></thead><tbody>'
                    + rows.map(function (t) {
                        return '<tr><td><a href="edit.php?id=' + t.id + '"><strong>' + esc(t.title) + '</strong></a></td><td>' + esc(t.skills || '') + '</td>'
                            + '<td>' + t.question_count + (+t.unready_count ? ' <span class="ct-chip draft">' + esc(L('to_check', '{n} to check', { n: t.unready_count })) + '</span>' : '') + '</td>'
                            + '<td>' + t.sitting_count + '</td><td style="white-space:nowrap"><a class="btn btn-sm btn-secondary" href="edit.php?id=' + t.id + '">' + esc(L('open', 'Open'))
                            + '</a> <button class="btn btn-sm btn-secondary" data-del="' + t.id + '">' + esc(L('delete', 'Delete')) + '</button></td></tr>';
                    }).join('') + '</tbody></table>'
                    : '<div class="ct-section ct-empty">' + esc(L('no_tests', 'No tests yet. Press New, describe the role, add the skills - the AI drafts the questions and you check them.')) + '</div>';
            });
        }
        $('testsList').addEventListener('click', function (e) {
            var id = e.target.getAttribute('data-del');
            if (!id || !confirm(L('confirm_delete_test', 'Delete this test? Its questions stay in the bank, and any candidates\' results are kept.'))) return;
            post({ action: 'delete_test', id: +id }).then(function (d) { if (!d.success) return fail(d); toast(d.archived ? L('archived', 'Archived - its candidates\' results are kept') : L('deleted', 'Deleted')); loadTests(); });
        });

        var bankReady = false, bankPicked = {};
        function loadBank() {
            if (!bankReady) {
                bankReady = true;
                get({ action: 'skills' }).then(function (sd) {
                    $('bankFilters').innerHTML = bankFilters('bk', sd.skills || [], true);
                    wireFilters('bk', runBank); runBank();
                });
                return;
            }
            runBank();
        }
        function runBank() {
            get(bankQuery('bk')).then(function (d) {
                if (!d.success) return fail(d);
                bankPicked = {};
                bulk();
                var qs = d.questions || [];
                $('bankList').innerHTML = qs.length ? qs.map(function (q) {
                    var a = [{ act: 'edit', label: L('edit', 'Edit') }];
                    if (q.status === 'draft') a.unshift({ act: 'approve', label: L('approve', 'Approve'), primary: true });
                    a.push(q.status === 'hidden' ? { act: 'unhide', label: L('unhide', 'Unhide') } : { act: 'hide', label: L('hide', 'Hide') });
                    if (q.status === 'draft') a.push({ act: 'delete', label: L('delete', 'Delete') });
                    return questionCard(q, { pick: true, usedIn: +q.used_in, actions: a });
                }).join('') + (d.capped ? '<p class="ct-note">' + esc(L('capped', 'Showing the first 500 - search or filter to narrow it down.')) + '</p>' : '')
                    : '<div class="ct-empty">' + esc(L('bank_empty', 'No questions here. They arrive when a test\'s skills are filled, or press New to write one.')) + '</div>';
                $('bankList')._qs = qs;
            });
        }
        function bulk() {
            var n = Object.keys(bankPicked).length;
            $('bankBulk').innerHTML = n ? esc(L('n_selected', '{n} selected', { n: n }))
                + ' <button class="btn btn-sm btn-primary" data-bulk="approved">' + esc(L('approve', 'Approve')) + '</button>'
                + ' <button class="btn btn-sm btn-secondary" data-bulk="hidden">' + esc(L('hide', 'Hide')) + '</button>' : '';
        }
        $('bankBulk').addEventListener('click', function (e) {
            var st = e.target.getAttribute('data-bulk');
            if (!st) return;
            post({ action: 'set_status', status: st, ids: Object.keys(bankPicked).map(Number) }).then(function (d) { if (!d.success) return fail(d); toast(L('saved', 'Saved')); runBank(); });
        });
        $('bankList').addEventListener('change', function (e) {
            if (!e.target.classList.contains('ct-pick')) return;
            if (e.target.checked) bankPicked[e.target.value] = 1; else delete bankPicked[e.target.value];
            bulk();
        });
        $('bankList').addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var id = +b.dataset.id, act = b.dataset.act;
            var q = ($('bankList')._qs || []).filter(function (x) { return x.id === id; })[0];
            if (act === 'edit') return editQuestion(q, runBank);
            if (act === 'delete') {
                if (!confirm(L('confirm_delete_q', 'Delete this draft question?'))) return;
                return post({ action: 'delete_question', id: id }).then(function (d) { if (!d.success) return fail(d); runBank(); });
            }
            var st = { approve: 'approved', hide: 'hidden', unhide: 'approved' }[act];
            post({ action: 'set_status', status: st, ids: [id] }).then(function (d) { if (!d.success) return fail(d); runBank(); });
        });
        $('bankNew').addEventListener('click', function () { editQuestion(null, function () { bankReady = false; loadBank(); }); });

        function loadCandidates() {
            get({ action: 'sittings' }).then(function (d) {
                if (!d.success) return fail(d);
                $('candList').innerHTML = candidatesTable(d.sittings || [], true);
                $('candRetention').textContent = d.retention_days > 0
                    ? L('retention_on', 'Finished, cancelled and expired candidates are deleted automatically after {n} days (LMS → Settings → Competency tests).', { n: d.retention_days })
                    : L('retention_off', 'Candidates are kept until you delete them (LMS → Settings → Competency tests).');
            });
        }
        wireSittingActions($('candList'), loadCandidates);

        var start = (location.hash || '').replace('#', '');
        show(['tests', 'bank', 'candidates'].indexOf(start) > -1 ? start : 'tests');
    }

    // ================================================================== EDIT (builder)
    function initEdit() {
        var testId = +(document.body.dataset.testId || 0);
        var defaultLimit = document.body.dataset.defaultLimit;
        var data = { test: null, skills: [], questions: [] };
        var canEmail = false;

        function skillRow(sk) {
            sk = sk || { id: 0, skill: '', difficulty: 'intermediate', question_count: 5, format: 'choice' };
            var div = document.createElement('div');
            div.className = 'ct-skill';
            div.dataset.id = sk.id || 0;
            div.innerHTML = '<input class="skName" maxlength="120" placeholder="' + esc(L('skill_ph', 'e.g. Active Directory, Networking, PowerShell')) + '" value="' + esc(sk.skill) + '">'
                + '<select class="skDiff">' + opts(DIFFS, sk.difficulty, diffLabel) + '</select>'
                + '<input class="skCount" type="number" min="1" max="20" value="' + (sk.question_count || 5) + '">'
                + '<select class="skFmt">' + opts(FORMATS, sk.format, fmtLabel) + '</select>'
                + '<span class="ct-skill-have"></span>'
                + '<span style="display:flex;gap:4px"><button type="button" class="btn btn-sm btn-secondary skFill">' + esc(L('fill', 'Fill')) + '</button>'
                + '<button type="button" class="ct-x skRm" title="' + esc(L('remove', 'Remove')) + '">×</button></span>';
            $('skills').appendChild(div);
            return div;
        }
        function readTest() {
            var skills = [];
            document.querySelectorAll('.ct-skill').forEach(function (row) {
                skills.push({ id: +row.dataset.id, skill: row.querySelector('.skName').value, difficulty: row.querySelector('.skDiff').value,
                              question_count: +row.querySelector('.skCount').value, format: row.querySelector('.skFmt').value });
            });
            return { action: 'save_test', id: testId, title: $('tTitle').value, role_description: $('tRole').value,
                     time_limit_minutes: $('tLimit').value, pass_mark: $('tPass').value, skills: skills };
        }
        function save(quiet) {
            return post(readTest()).then(function (d) {
                if (!d.success) { fail(d); throw new Error('save'); }
                if (!testId) { testId = d.id; history.replaceState(null, '', 'edit.php?id=' + d.id); document.body.dataset.testId = d.id; }
                if (!quiet) toast(L('saved', 'Saved'));
                return load();
            });
        }
        function haveFor(sk) {
            return data.questions.filter(function (q) { return q.skill === sk.skill && q.difficulty === sk.difficulty && q.format === sk.format && q.status !== 'hidden'; }).length;
        }
        function load() {
            if (!testId) { render(); return Promise.resolve(); }
            return get({ action: 'test', id: testId }).then(function (d) {
                if (!d.success) return fail(d);
                data = d;
                $('tTitle').value = d.test.title; $('tRole').value = d.test.role_description || '';
                $('tLimit').value = d.test.time_limit_minutes || ''; $('tPass').value = d.test.pass_mark != null ? d.test.pass_mark : '';
                $('skills').innerHTML = '';
                d.skills.forEach(function (sk) { skillRow(sk); });
                if (!d.skills.length) skillRow();
                $('aiWarn').hidden = d.ai_ready;
                render();
                loadSittings();
            });
        }
        function render() {
            document.querySelectorAll('.ct-skill').forEach(function (row, i) {
                var sk = data.skills[i];
                var el = row.querySelector('.ct-skill-have');
                if (!sk || +row.dataset.id !== +sk.id) { el.textContent = ''; return; }
                var have = haveFor(sk);
                el.textContent = L('have_of', '{have} of {want}', { have: have, want: sk.question_count });
                el.classList.toggle('ok', have >= sk.question_count);
            });
            var qs = data.questions || [];
            var drafts = qs.filter(function (q) { return q.status === 'draft'; }).length;
            $('qCount').textContent = qs.length ? L('q_count', '{n} question(s)', { n: qs.length }) + (drafts ? ' · ' + L('to_check', '{n} to check', { n: drafts }) : '') : '';
            $('approveAll').hidden = !drafts;
            $('questions').innerHTML = qs.length ? qs.map(function (q, i) {
                var a = [];
                if (q.status === 'draft') a.push({ act: 'approve', label: L('approve', 'Approve'), primary: true });
                a.push({ act: 'edit', label: L('edit', 'Edit') }, { act: 'remove', label: L('remove', 'Remove') });
                if (q.status !== 'hidden') a.push({ act: 'hide', label: L('hide', 'Hide') });
                return questionCard(q, { num: i + 1, actions: a });
            }).join('') : '<div class="ct-empty">' + esc(L('no_questions', 'No questions yet. Add the skills above and press Fill all - the bank is used first and the AI writes the rest as drafts for you to check.')) + '</div>';
            $('sendBtn').disabled = !testId || !qs.length || !!drafts || qs.some(function (q) { return q.status === 'hidden'; });
            $('sendHint').textContent = !qs.length ? '' : drafts ? L('send_blocked', 'Approve or remove the draft questions first - nothing goes to a candidate unchecked.')
                : (qs.some(function (q) { return q.status === 'hidden'; }) ? L('send_hidden', 'Remove the hidden questions first.') : '');
        }
        function loadSittings() {
            if (!testId) return;
            get({ action: 'sittings', test_id: testId }).then(function (d) { if (d.success) $('sittings').innerHTML = candidatesTable(d.sittings || [], false); });
        }

        function fill(rows) {
            var useBank = $('useBank').checked;
            var total = { bank: 0, ai: 0 }, msgs = [];
            var i = 0;
            function next() {
                if (i >= rows.length) {
                    $('fillStatus').textContent = '';
                    document.querySelectorAll('.skFill, #fillAll').forEach(function (b) { b.disabled = false; });
                    toast(L('filled', '{bank} from the bank, {ai} written by the AI', { bank: total.bank, ai: total.ai }));
                    msgs.forEach(function (m) { toast(m, 'error'); });
                    return load();
                }
                var sk = rows[i++];
                $('fillStatus').textContent = L('filling', 'Filling "{skill}" ({i} of {n})…', { skill: sk.skill, i: i, n: rows.length });
                return post({ action: 'generate', test_id: testId, skill_id: +sk.id, use_bank: useBank }).then(function (d) {
                    if (!d.success) msgs.push(sk.skill + ': ' + d.error);
                    else { total.bank += d.from_bank; total.ai += d.generated; if (d.message && !d.generated && !d.from_bank) msgs.push(sk.skill + ': ' + d.message); }
                    return load().then(next);
                }).catch(function () { msgs.push(sk.skill + ': ' + L('failed', 'That did not work.')); next(); });
            }
            document.querySelectorAll('.skFill, #fillAll').forEach(function (b) { b.disabled = true; });
            next();
        }

        $('addSkill').addEventListener('click', function () { skillRow().querySelector('.skName').focus(); });
        $('skills').addEventListener('click', function (e) {
            var row = e.target.closest('.ct-skill');
            if (!row) return;
            if (e.target.classList.contains('skRm')) { row.remove(); if (!document.querySelector('.ct-skill')) skillRow(); return; }
            if (e.target.classList.contains('skFill')) {
                var idx = Array.prototype.indexOf.call(document.querySelectorAll('.ct-skill'), row);
                save(true).then(function () { var sk = data.skills[idx]; if (sk) fill([sk]); }).catch(function () {});
            }
        });
        $('saveTest').addEventListener('click', function () { save(false).catch(function () {}); });
        $('fillAll').addEventListener('click', function () {
            save(true).then(function () {
                var rows = data.skills.filter(function (sk) { return haveFor(sk) < sk.question_count; });
                if (!rows.length) return toast(L('all_filled', 'Every skill already has its questions.'));
                fill(rows);
            }).catch(function () {});
        });
        $('approveAll').addEventListener('click', function () {
            var ids = data.questions.filter(function (q) { return q.status === 'draft'; }).map(function (q) { return q.id; });
            if (!confirm(L('confirm_approve_all', 'Approve all {n} draft questions? Only do this once you have read them.', { n: ids.length }))) return;
            post({ action: 'set_status', status: 'approved', ids: ids }).then(function (d) { if (!d.success) return fail(d); load(); });
        });
        $('fromBank').addEventListener('click', function () {
            save(true).then(function () { pickFromBank(testId, data.questions.map(function (q) { return q.id; }), load); }).catch(function () {});
        });
        $('writeQ').addEventListener('click', function () {
            save(true).then(function () {
                var first = data.skills[0];
                editQuestion(first ? { skill: first.skill, difficulty: first.difficulty, format: first.format, question_text: '', explanation: '', status: 'approved',
                    answers: first.format === 'graded' ? gradedDefault.map(function (m) { return { text: '', marks: m }; }) : [{ text: '', marks: 1 }, { text: '', marks: 0 }, { text: '', marks: 0 }, { text: '', marks: 0 }] } : null,
                    load, testId);
            }).catch(function () {});
        });
        $('questions').addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var id = +b.dataset.id, act = b.dataset.act;
            var q = data.questions.filter(function (x) { return x.id === id; })[0];
            if (act === 'edit') return editQuestion(q, load);
            if (act === 'remove') return post({ action: 'remove_question', test_id: testId, question_id: id }).then(function (d) { if (!d.success) return fail(d); load(); });
            if (act === 'hide' && !confirm(L('confirm_hide', 'Hide this question in the bank? It will not be offered for any test again (you can unhide it in the bank).'))) return;
            post({ action: 'set_status', status: act === 'approve' ? 'approved' : 'hidden', ids: [id] }).then(function (d) {
                if (!d.success) return fail(d);
                if (act === 'hide') return post({ action: 'remove_question', test_id: testId, question_id: id }).then(load);
                load();
            });
        });
        $('sendBtn').addEventListener('click', function () { sendToCandidate(testId, canEmail, loadSittings); });
        wireSittingActions($('sittings'), loadSittings);

        get({ action: 'settings' }).then(function (d) { if (d.success) { canEmail = d.can_email; gradedDefault = d.settings.graded_marks.split(',').map(function (x) { return +x.trim(); }); } });
        if (!testId) { $('tLimit').value = defaultLimit > 0 ? defaultLimit : ''; skillRow(); render(); $('tTitle').focus(); }
        else load();
    }

    // ================================================================== RESULT
    function initResult() {
        var id = +document.body.dataset.sittingId;
        get({ action: 'sitting', id: id }).then(function (d) {
            if (!d.success) { $('result').innerHTML = '<div class="ct-section ct-empty">' + esc(d.error || '') + '</div>'; return; }
            var s = d.sitting, snap = d.snapshot || { questions: [] }, resp = d.responses || {};
            $('rName').textContent = s.candidate_name;
            var pass = snap.pass_mark;
            var verdict = (s.score_percent != null && pass != null)
                ? '<span class="ct-verdict ' + (s.score_percent >= pass ? 'pass' : 'fail') + '">' + esc(s.score_percent >= pass ? L('pass', 'Pass') : L('below_pass', 'Below the pass mark')) + ' (' + pass + '%)</span>' : '';
            var mins = (s.started_datetime && s.submitted_datetime)
                ? Math.max(1, Math.round((Date.parse(s.submitted_datetime.replace(' ', 'T') + 'Z') - Date.parse(s.started_datetime.replace(' ', 'T') + 'Z')) / 60000)) : null;
            var h = '<div class="ct-section"><div class="ct-score">'
                + '<div class="ct-score-big">' + (s.score_percent != null ? pct(s.score_percent) : '–') + '</div>'
                + '<div><span class="ct-state ' + s.state + '">' + esc(stateLabel(s.state)) + '</span> ' + verdict
                + (s.finish_reason === 'time_up' ? '<div class="ct-note" style="margin-top:6px">' + esc(L('time_up_long', 'The time ran out; the answers they had given were scored.')) + '</div>' : '') + '</div></div>'
                + '<div class="ct-meta"><div><b>' + esc(L('col_test', 'Test')) + '</b>' + esc(snap.title || s.test_title || '') + '</div>'
                + '<div><b>' + esc(L('email', 'Email')) + '</b>' + esc(s.candidate_email || '–') + '</div>'
                + '<div><b>' + esc(L('col_sent', 'Sent')) + '</b>' + esc(when(s.created_datetime)) + '</div>'
                + '<div><b>' + esc(L('started', 'Started')) + '</b>' + esc(when(s.started_datetime) || '–') + '</div>'
                + '<div><b>' + esc(L('col_finished', 'Finished')) + '</b>' + esc(when(s.submitted_datetime) || '–') + '</div>'
                + '<div><b>' + esc(L('time_taken', 'Time taken')) + '</b>' + (mins != null ? esc(L('n_minutes', '{n} min', { n: mins })) + (s.time_limit_minutes ? ' / ' + s.time_limit_minutes : '') : '–') + '</div></div></div>';
            if (s.skills && s.skills.length) {
                h += '<div class="ct-section"><h2>' + esc(L('by_skill', 'By skill')) + '</h2>' + s.skills.map(function (k) {
                    return '<div class="ct-skillbar"><span>' + esc(k.skill) + ' <span class="ct-note">' + esc(diffLabel(k.difficulty)) + '</span></span>'
                        + '<div class="track"><div class="fill" style="width:' + Math.max(0, Math.min(100, k.percent)) + '%"></div></div><strong>' + pct(k.percent) + '</strong></div>';
                }).join('') + '</div>';
            }
            h += '<div class="ct-section ct-noprint"><h2>' + esc(L('notes', 'Your notes')) + '</h2><textarea id="rNotes" rows="3" style="width:100%;box-sizing:border-box;padding:8px;font:inherit">'
                + esc(s.notes || '') + '</textarea><div style="margin-top:8px"><button class="btn btn-secondary" id="rNotesSave">' + esc(L('save', 'Save')) + '</button></div></div>';
            h += '<div class="ct-section"><h2>' + esc(L('answers_given', 'Their answers')) + '</h2>' + snap.questions.map(function (q, i) {
                var pick = resp[String(i)];
                var max = q.max;
                return '<div class="ct-q"><div class="ct-q-top"><span class="ct-q-num">' + (i + 1) + '.</span><div class="ct-q-body"><div class="ct-chips"><span class="ct-chip">' + esc(q.skill)
                    + '</span><span class="ct-chip">' + esc(diffLabel(q.difficulty)) + '</span><span class="ct-chip">' + esc(fmtLabel(q.format)) + '</span>'
                    + (pick == null ? '<span class="ct-chip draft">' + esc(L('unanswered', 'Not answered')) + '</span>' : '<span class="ct-chip">'
                        + esc(L('scored', '{got} of {max}', { got: q.answers[pick] ? q.answers[pick].marks : 0, max: max })) + '</span>') + '</div>'
                    + '<p class="ct-q-text">' + esc(q.text) + '</p><ul class="ct-answers">' + q.answers.map(function (a, j) {
                        return '<li' + (pick != null && +pick === j ? ' class="picked" data-picked="' + esc(L('their_answer', '← their answer')) + '"' : '') + '><span class="ct-mark'
                            + (a.marks === max && max > 0 ? ' best' : '') + '">' + a.marks + '</span><span>' + esc(a.text) + '</span></li>';
                    }).join('') + '</ul>' + (q.explanation ? '<div class="ct-why">' + esc(q.explanation) + '</div>' : '') + '</div></div></div>';
            }).join('') + '</div>';
            $('result').innerHTML = h;
            $('rNotesSave').addEventListener('click', function () {
                post({ action: 'save_notes', id: id, notes: $('rNotes').value }).then(function (r) { if (!r.success) return fail(r); toast(L('saved', 'Saved')); });
            });
        });
        $('rPrint').addEventListener('click', function () { window.print(); });
    }

    var page = document.body.dataset.ctPage;
    if (page === 'index') initIndex();
    else if (page === 'edit') initEdit();
    else if (page === 'result') initResult();
})();
