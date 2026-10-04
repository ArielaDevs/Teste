/**
 * Adds the session's CSRF token to every request this page sends to FreeITSM
 * (S4 - see includes/csrf.php). Loaded into every page's <head> by the server,
 * before any other script, so code written before tokens existed - and code
 * written after, by anyone - is covered without knowing about it.
 *
 *   fetch()                 X-CSRF-Token header
 *   XMLHttpRequest          X-CSRF-Token header
 *   <form method="post">    hidden _csrf_token field (on submit, and form.submit())
 *   navigator.sendBeacon()  ?_csrf= in the URL - a beacon cannot carry headers
 *   EventSource             ?_csrf= in the URL - neither can a stream
 *
 * Only same-origin requests get the token: it must never be sent to another site.
 * GET and HEAD are left alone, except for EventSource, whose streams can write.
 */
(function () {
    'use strict';
    if (window.__freeitsmCsrf) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    var TOKEN = meta ? meta.getAttribute('content') : '';
    if (!TOKEN) return;
    window.__freeitsmCsrf = { token: TOKEN };

    var HEADER = 'X-CSRF-Token', FIELD = '_csrf_token', QUERY = '_csrf';
    var SAFE = { GET: 1, HEAD: 1, OPTIONS: 1 };

    function sameOrigin(url) {
        try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
    }
    function withQuery(url) {
        try {
            var u = new URL(url, location.href);
            if (u.origin !== location.origin || u.searchParams.has(QUERY)) return url;
            u.searchParams.set(QUERY, TOKEN);
            return u.toString();
        } catch (e) { return url; }
    }

    // ── fetch ────────────────────────────────────────────────────────────────
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            try {
                var isReq = typeof Request !== 'undefined' && input instanceof Request;
                var url = isReq ? input.url : String(input);
                var method = String((init && init.method) || (isReq ? input.method : 'GET')).toUpperCase();
                if (!SAFE[method] && sameOrigin(url)) {
                    init = Object.assign({}, init || {});
                    var h = new Headers(init.headers || (isReq ? input.headers : undefined));
                    if (!h.has(HEADER)) h.set(HEADER, TOKEN);
                    init.headers = h;
                }
            } catch (e) { /* never break a request over the token */ }
            return origFetch.call(this, input, init);
        };
    }

    // ── XMLHttpRequest ───────────────────────────────────────────────────────
    if (window.XMLHttpRequest) {
        var proto = XMLHttpRequest.prototype, origOpen = proto.open, origSend = proto.send, origSet = proto.setRequestHeader;
        proto.open = function (method, url) {
            this.__csrf = !SAFE[String(method).toUpperCase()] && sameOrigin(url);
            this.__csrfSet = false;
            return origOpen.apply(this, arguments);
        };
        proto.setRequestHeader = function (name) {
            if (String(name).toLowerCase() === HEADER.toLowerCase()) this.__csrfSet = true;
            return origSet.apply(this, arguments);
        };
        proto.send = function () {
            if (this.__csrf && !this.__csrfSet) { try { origSet.call(this, HEADER, TOKEN); } catch (e) {} }
            return origSend.apply(this, arguments);
        };
    }

    // ── plain HTML forms ─────────────────────────────────────────────────────
    function stamp(form) {
        try {
            if (!form || String(form.method).toLowerCase() !== 'post') return;
            if (!sameOrigin(form.action || location.href)) return;
            var f = form.querySelector('input[name="' + FIELD + '"]');
            if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = FIELD; form.appendChild(f); }
            f.value = TOKEN;
        } catch (e) {}
    }
    document.addEventListener('submit', function (e) { stamp(e.target); }, true);
    if (window.HTMLFormElement) {
        var origSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () { stamp(this); return origSubmit.apply(this, arguments); };
        var origRequest = HTMLFormElement.prototype.requestSubmit;
        if (origRequest) HTMLFormElement.prototype.requestSubmit = function () { stamp(this); return origRequest.apply(this, arguments); };
    }
    // FormData built from a form before it is posted by fetch already gets the
    // header; a FormData posted by a beacon gets the query string below.

    // ── sendBeacon and EventSource ───────────────────────────────────────────
    if (navigator.sendBeacon) {
        var origBeacon = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = function (url, data) { return origBeacon(withQuery(url), data); };
    }
    if (window.EventSource) {
        var OrigES = window.EventSource;
        var ES = function (url, cfg) { return new OrigES(withQuery(url), cfg); };
        ES.prototype = OrigES.prototype;
        ES.CONNECTING = OrigES.CONNECTING; ES.OPEN = OrigES.OPEN; ES.CLOSED = OrigES.CLOSED;
        window.EventSource = ES;
    }
})();
