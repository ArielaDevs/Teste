/**
 * The notification bell - ONE script for both the analyst app (discussion #55)
 * and the self-service portal (discussion #62).
 *
 *   NotificationBell.init({
 *       list:       URL returning { unread, notifications } (and just { unread }
 *                   with count_only=1),
 *       markRead:   URL taking { ids } or { all },
 *       clear:      URL taking { ids } or { all, include_unread },
 *       linkPrefix: prepended to each notification's relative link,
 *       seenKey:    sessionStorage key for the chime's baseline
 *   });
 *
 * The markup comes from notificationBellMarkup() (includes/notification_bell.php)
 * and the look from assets/css/notification-bell.css. Everything below moved
 * here from includes/waffle-menu.php unchanged apart from the URLs, so the two
 * bells cannot drift: a fix to one is a fix to both.
 *
 * Needs window.t (i18n.js) for the common.notifications.* strings, and uses
 * showConfirm / showToast when present.
 */
window.NotificationBell = {
    init: function (cfg) {
        // Where to ask. The analyst bell and the portal bell differ ONLY here.
        const LIST   = cfg.list;
        const COUNT  = cfg.list + (cfg.list.indexOf('?') === -1 ? '?' : '&') + 'count_only=1';
        const MARK   = cfg.markRead;
        const CLEAR  = cfg.clear;
        const PREFIX = cfg.linkPrefix || '';
        const btn   = document.getElementById('nbBtn');
        const panel = document.getElementById('nbPanel');
        const badge = document.getElementById('nbCount');
        const list  = document.getElementById('nbList');
        if (!btn) return;

        const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
            ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

        // Stored UTC without a zone marker; left as-is, Safari and Firefox read it
        // as local time and every notification looks hours old.
        function ago(utc) {
            if (!utc) return '';
            const then = new Date(String(utc).replace(' ', 'T') + 'Z');
            if (isNaN(then)) return '';
            const mins = Math.floor((Date.now() - then.getTime()) / 60000);
            if (mins < 1)  return window.t('common.notifications.just_now');
            if (mins < 60) return window.t('common.notifications.minutes', { n: mins });
            const hrs = Math.floor(mins / 60);
            if (hrs < 24)  return window.t('common.notifications.hours', { n: hrs });
            return window.t('common.notifications.days', { n: Math.floor(hrs / 24) });
        }

        function describe(n) {
            // Translated at render time, never baked into the row — otherwise a
            // notification written today reads forever in whoever wrote it's language.
            const key = 'common.notifications.event.' + n.event_type;
            const txt = window.t(key, { actor: n.actor_name || window.t('common.notifications.someone') });
            return txt === key ? (n.actor_name || '') : txt;
        }

        function render(items) {
            if (!items.length) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                return;
            }
            list.innerHTML = items.map(n => {
                const count = n.event_count > 1
                    ? '<span class="nb-badge-count">' + n.event_count + '</span>' : '';
                const ref = n.entity_ref ? esc(n.entity_ref) : '';
                return `<a class="nb-item${n.is_read ? '' : ' unread'}" href="${n.link ? esc(PREFIX + n.link) : '#'}"
                           data-id="${n.id}">
                    <div class="nb-item-top">
                        <span class="nb-item-ref">${ref}${count}</span>
                        <span class="nb-clear" role="button" tabindex="0"
                              title="${esc(window.t('common.notifications.clear_one'))}"
                              aria-label="${esc(window.t('common.notifications.clear_one'))}">&times;</span>
                    </div>
                    <div class="nb-item-title">${esc(n.title || '')}</div>
                    <div class="nb-item-bottom">
                        <span class="nb-item-body">${esc(describe(n))}</span>
                        <span class="nb-item-meta">${esc(ago(n.updated_datetime))}</span>
                    </div>
                </a>`;
            }).join('');
        }

        let lastUnread = 0;
        function paintBadge(unread) {
            lastUnread = unread;
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.hidden = unread === 0;
        }

        // ===== Chime (preference notification_sound, off by default) =====
        // The unread count is normally non-zero when a page loads, so the count
        // alone cannot say "something new arrived" — sounding on it would chime
        // on every navigation for notifications you were told about yesterday.
        // The last count seen is therefore kept per tab, and the first poll of a
        // tab only records a baseline. Private-mode browsers throw on
        // sessionStorage, which costs nothing worse than a silent chime.
        const SEEN_KEY = cfg.seenKey || 'nbSeenUnread';
        function recordSeen(unread) {
            try { sessionStorage.setItem(SEEN_KEY, String(unread)); } catch (e) { /* ignore */ }
        }
        function chimeIfNew(unread) {
            let prev = null;
            try { prev = sessionStorage.getItem(SEEN_KEY); } catch (e) { /* ignore */ }
            recordSeen(unread);
            if (prev === null) return;                      // first poll in this tab
            if (unread > parseInt(prev, 10) && typeof window.playNotificationSound === 'function') {
                window.playNotificationSound();
            }
        }

        async function poll() {
            try {
                const d = await (await fetch(COUNT, { credentials: 'same-origin' })).json();
                if (d.success) { paintBadge(d.unread); chimeIfNew(d.unread); }
            } catch (e) { /* a failed poll is not worth telling anyone about */ }
        }

        async function open() {
            panel.classList.add('open');
            list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.loading')) + '</div>';
            try {
                const d = await (await fetch(LIST, { credentials: 'same-origin' })).json();
                // Opening the panel re-baselines rather than chiming: you are
                // looking straight at the list, so anything in it is not news.
                if (d.success) { render(d.notifications || []); paintBadge(d.unread); recordSeen(d.unread); }
            } catch (e) {
                list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.load_failed')) + '</div>';
            }
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.contains('open') ? panel.classList.remove('open') : open();
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && !btn.contains(e.target)) panel.classList.remove('open');
        });

        // Follow the link AND mark read. Not awaited: making somebody wait on a
        // bookkeeping write before their ticket opens would be the wrong trade.
        list.addEventListener('click', function (e) {
            const item = e.target.closest('.nb-item');
            if (!item) return;
            const id = parseInt(item.dataset.id, 10);

            // The clear button sits INSIDE the row's anchor, so without stopping
            // the event here, clearing a notification would also navigate to the
            // ticket it was about.
            if (e.target.closest('.nb-clear')) {
                e.preventDefault();
                e.stopPropagation();
                if (id) clearOne(id, item);
                return;
            }

            if (id) {
                fetch(MARK, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] }), keepalive: true
                }).catch(() => {});
            }
        });

        // role="button" is a promise that Enter and Space work. The span is inside
        // an anchor, so Enter would otherwise follow the link instead.
        list.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const btn = e.target.closest('.nb-clear');
            if (!btn) return;
            const item = btn.closest('.nb-item');
            const id = item && parseInt(item.dataset.id, 10);
            e.preventDefault();
            e.stopPropagation();
            if (id) clearOne(id, item);
        });

        // Awaited, unlike mark-read: this one removes something from the screen,
        // so a row must not vanish before the server has agreed that it is gone.
        async function clearOne(id, item) {
            // Asks first, for the same reason Clear all does: the delete is real
            // and there is no undo. The X sits a few pixels from the row itself,
            // so a mis-aimed click would otherwise silently destroy the thing you
            // were reaching for. No tick box here — a single row has no narrower
            // and wider reading for one to choose between.
            const ok = await showConfirm({
                title:   window.t('common.notifications.clear_one_title'),
                message: window.t('common.notifications.clear_one_msg'),
                okLabel: window.t('common.notifications.clear_ok'),
                okClass: 'danger'
            });
            if (!ok) return;
            try {
                const d = await (await fetch(CLEAR, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: [id] })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                item.remove();
                paintBadge(d.unread);
                // Re-baseline, or the chime goes quiet: chimeIfNew only fires when
                // the count rises above the stored figure, and clearing lowers it.
                recordSeen(d.unread);
                if (!list.querySelector('.nb-item')) {
                    list.innerHTML = '<div class="nb-empty">' + esc(window.t('common.notifications.empty')) + '</div>';
                }
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        document.getElementById('nbMarkAll').addEventListener('click', async function (e) {
            e.stopPropagation();
            try {
                const d = await (await fetch(MARK, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true })
                })).json();
                if (d.success) { paintBadge(d.unread); recordSeen(d.unread); open(); }
            } catch (err) { /* leave the panel as it is */ }
        });

        // Clear all deletes for good, so it asks first — and by default it spares
        // anything still unread. The tick box is the safety catch: emptying the
        // panel should not be able to bin news nobody has looked at yet, unless
        // that is deliberately asked for.
        document.getElementById('nbClearAll').addEventListener('click', function (e) {
            e.stopPropagation();

            const unread = lastUnread;
            const opts = {
                title:    window.t('common.notifications.clear_title'),
                message:  unread > 0
                            ? window.t('common.notifications.clear_msg_read')
                            : window.t('common.notifications.clear_msg'),
                okLabel:  window.t('common.notifications.clear_ok'),
                okClass:  'danger',
                onConfirm: state => doClearAll(!!state.checked)
            };
            // No unread rows means there is nothing for the catch to protect, and
            // an option that cannot change the outcome is just a thing to read.
            if (unread > 0) {
                opts.checkbox = {
                    label: unread === 1
                        ? window.t('common.notifications.clear_unread_one')
                        : window.t('common.notifications.clear_unread', { n: unread }),
                    checked: false
                };
            }
            showConfirm(opts);
        });

        async function doClearAll(includeUnread) {
            try {
                const d = await (await fetch(CLEAR, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ all: true, include_unread: includeUnread })
                })).json();
                if (!d.success) throw new Error(d.error || 'failed');
                paintBadge(d.unread);
                recordSeen(d.unread);
                // Everything unread and the box left unticked: the honest outcome
                // is that nothing happened, and saying so beats a panel that looks
                // like it ignored the click.
                if (d.cleared === 0 && typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_nothing'), 'info');
                }
                open();
            } catch (err) {
                if (typeof window.showToast === 'function') {
                    window.showToast(window.t('common.notifications.clear_failed'), 'error');
                }
            }
        }

        poll();
        setInterval(poll, 60000);
    }
};
