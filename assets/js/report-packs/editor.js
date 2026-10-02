/**
 * Report Packs - editing rich text in place.
 *
 * A text box, the header, the footer or the cover is edited by laying a
 * contentEditable box over it on the page, styled to match. The ribbon's Home
 * tab drives it (bold, colour, lists, alignment...). When editing ends, the
 * HTML is read back into the paragraph model (docToHtml / htmlToDoc) and the
 * engine lays it out again.
 *
 * The HTML here is only ever the analyst's own, built by docToHtml from the
 * stored model with every piece of text escaped; it is never stored. What is
 * stored is the model, which the server checks field by field.
 */
(function () {
    'use strict';

    const FIELD_KEYS = ['page', 'pages', 'date_from', 'date_to', 'today', 'title', 'company'];
    const T = (k, p) => (window.t ? window.t('reporting.packs.' + k, p) : k);

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Model -> HTML (for the editor) ─────────────────────────────────────
    function runHtml(r) {
        const css = [];
        if (r.b) css.push('font-weight:700');
        if (r.i) css.push('font-style:italic');
        const deco = [r.u ? 'underline' : '', r.s ? 'line-through' : ''].filter(Boolean).join(' ');
        if (deco) css.push('text-decoration:' + deco);
        if (r.c) css.push('color:' + r.c);
        if (r.hl) css.push('background-color:' + r.hl);
        if (r.sz) css.push('font-size:' + r.sz + 'pt');
        const style = css.length ? ' style="' + css.join(';') + '"' : '';
        if (r.fld) {
            return '<span class="rp-field" contenteditable="false" data-fld="' + esc(r.fld) + '"' + style + '>' + esc(T('field.' + r.fld)) + '</span>';
        }
        const text = esc(r.x || '').replace(/\n/g, '<br>');
        return css.length ? '<span' + style + '>' + text + '</span>' : text;
    }

    function docToHtml(doc, logoUrl) {
        let html = '';
        const stack = [];                        // open lists: [{tag, lv}]
        const closeTo = (lv) => { while (stack.length && stack[stack.length - 1].lv >= lv) html += '</' + stack.pop().tag + '>'; };
        (doc || []).forEach(p => {
            const align = p.a && p.a !== 'left' ? ' style="text-align:' + p.a + '"' : '';
            if (p.t === 'li') {
                const lv = p.lv || 0, tag = p.l === 'ol' ? 'ol' : 'ul';
                while (stack.length && (stack[stack.length - 1].lv > lv || (stack[stack.length - 1].lv === lv && stack[stack.length - 1].tag !== tag))) html += '</' + stack.pop().tag + '>';
                while (!stack.length || stack[stack.length - 1].lv < lv) {
                    const want = stack.length ? stack[stack.length - 1].lv + 1 : 0;
                    const t = want === lv ? tag : 'ul';
                    html += '<' + t + '>';
                    stack.push({ tag: t, lv: want });
                    if (want >= lv) break;
                }
                html += '<li' + align + '>' + ((p.r || []).map(runHtml).join('') || '<br>') + '</li>';
                return;
            }
            closeTo(0);
            if (p.t === 'img') {
                html += '<p' + align + '><img class="rp-logo-img" data-rp="logo" data-w="' + (p.w || 30) + '" src="' + esc(logoUrl || '') + '" style="width:' + (p.w || 30) + 'mm" alt="' + esc(T('designer.logo')) + '"></p>';
                return;
            }
            const tag = ['h1', 'h2', 'h3'].includes(p.t) ? p.t : 'p';
            html += '<' + tag + align + '>' + ((p.r || []).map(runHtml).join('') || '<br>') + '</' + tag + '>';
        });
        closeTo(0);
        return html || '<p><br></p>';
    }

    // ── HTML -> model (when editing ends) ──────────────────────────────────
    function hex(colour) {
        if (!colour) return null;
        colour = colour.trim();
        if (/^#[0-9a-f]{6}$/i.test(colour)) return colour.toLowerCase();
        if (/^#[0-9a-f]{3}$/i.test(colour)) return ('#' + colour.slice(1).split('').map(c => c + c).join('')).toLowerCase();
        const m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?/i.exec(colour);
        if (!m || (m[4] !== undefined && +m[4] === 0)) return null;
        return '#' + [m[1], m[2], m[3]].map(n => (+n).toString(16).padStart(2, '0')).join('');
    }

    function sizePt(v) {
        if (!v) return null;
        const m = /^([\d.]+)(px|pt)$/.exec(v.trim());
        if (!m) return null;
        const pt = m[2] === 'px' ? +m[1] * 0.75 : +m[1];
        return Math.max(6, Math.min(72, Math.round(pt * 2) / 2));
    }

    /** Read a run style from an element and inherited style. */
    function styleOf(node, inherited) {
        const st = Object.assign({}, inherited);
        if (node.nodeType !== 1) return st;
        const tag = node.tagName.toLowerCase();
        if (tag === 'b' || tag === 'strong') st.b = true;
        if (tag === 'i' || tag === 'em') st.i = true;
        if (tag === 'u') st.u = true;
        if (tag === 's' || tag === 'strike' || tag === 'del') st.s = true;
        if (tag === 'font' && node.getAttribute('color')) st.c = hex(node.getAttribute('color')) || st.c;
        const s = node.style;
        if (s) {
            if (s.fontWeight) st.b = s.fontWeight === 'bold' || parseInt(s.fontWeight, 10) >= 600;
            if (s.fontStyle) st.i = s.fontStyle === 'italic';
            const deco = (s.textDecorationLine || s.textDecoration || '');
            if (deco) { st.u = deco.includes('underline'); st.s = deco.includes('line-through'); }
            if (s.color) st.c = hex(s.color) || st.c;
            if (s.backgroundColor) st.hl = hex(s.backgroundColor) || st.hl;
            if (s.fontSize) st.sz = sizePt(s.fontSize) || st.sz;
        }
        return st;
    }

    function pushRun(runs, text, st) {
        if (!text) return;
        const r = { x: text };
        ['b', 'i', 'u', 's'].forEach(k => { if (st[k]) r[k] = true; });
        if (st.c && st.c !== '#222222' && st.c !== '#000000') r.c = st.c;
        if (st.hl) r.hl = st.hl;
        if (st.sz) r.sz = st.sz;
        const last = runs[runs.length - 1];
        if (last && last.x !== undefined && !last.fld && JSON.stringify(Object.assign({}, last, { x: '' })) === JSON.stringify(Object.assign({}, r, { x: '' }))) {
            last.x += text;
        } else runs.push(r);
    }

    function collectRuns(node, st, runs) {
        node.childNodes.forEach(ch => {
            // The zero-width space is only there to give the caret somewhere to sit
            // after a field; it is not in the PDF fonts and would print as "?".
            if (ch.nodeType === 3) { pushRun(runs, ch.nodeValue.replace(/ /g, ' ').replace(/[​﻿]/g, '').replace(/[\r\n\t]+/g, ' '), st); return; }
            if (ch.nodeType !== 1) return;
            const tag = ch.tagName.toLowerCase();
            if (tag === 'br') { pushRun(runs, '\n', st); return; }
            if (ch.classList && ch.classList.contains('rp-field')) {
                const f = ch.getAttribute('data-fld');
                if (FIELD_KEYS.includes(f)) {
                    const fs = styleOf(ch, st);
                    const r = { fld: f };
                    ['b', 'i', 'u', 's'].forEach(k => { if (fs[k]) r[k] = true; });
                    if (fs.c && fs.c !== '#222222') r.c = fs.c;
                    if (fs.sz) r.sz = fs.sz;
                    runs.push(r);
                }
                return;
            }
            if (['ul', 'ol', 'p', 'div', 'h1', 'h2', 'h3', 'li'].includes(tag)) return;   // handled as blocks
            collectRuns(ch, styleOf(ch, st), runs);
        });
    }

    function alignOf(el) {
        const a = (el.style && el.style.textAlign) || el.getAttribute('align') || '';
        return ['center', 'right', 'justify'].includes(a) ? a : 'left';
    }

    function trimRuns(runs) {
        // Drop a trailing line break the editor leaves behind, and empty runs.
        while (runs.length && runs[runs.length - 1].x !== undefined && /^\n*$/.test(runs[runs.length - 1].x)) runs.pop();
        return runs.filter(r => r.fld || r.x !== '');
    }

    function htmlToDoc(root) {
        const doc = [];
        const walk = (container, listCtx) => {
            let loose = [];
            const flushLoose = () => {
                if (!loose.length) return;
                const wrap = document.createElement('div');
                loose.forEach(n => wrap.appendChild(n.cloneNode(true)));
                const runs = [];
                collectRuns(wrap, {}, runs);
                const r = trimRuns(runs);
                if (r.length) doc.push({ t: 'p', a: 'left', r });
                loose = [];
            };
            Array.from(container.childNodes).forEach(n => {
                const tag = n.nodeType === 1 ? n.tagName.toLowerCase() : null;
                if (tag === 'ul' || tag === 'ol') {
                    flushLoose();
                    Array.from(n.children).forEach(li => {
                        if (li.tagName.toLowerCase() === 'ul' || li.tagName.toLowerCase() === 'ol') {
                            walk(li.parentNode === n ? wrapList(li) : li, { lv: listCtx ? listCtx.lv + 1 : 1 });
                            return;
                        }
                        const runs = [];
                        collectRuns(li, styleOf(li, {}), runs);
                        const item = { t: 'li', a: alignOf(li), r: trimRuns(runs), l: tag };
                        const lv = Math.min(4, listCtx ? listCtx.lv : 0);
                        if (lv) item.lv = lv;          // the stored model omits level 0
                        doc.push(item);
                        // nested lists inside this li
                        Array.from(li.children).filter(c => /^(ul|ol)$/i.test(c.tagName)).forEach(sub => {
                            walk(wrapList(sub), { lv: (listCtx ? listCtx.lv : 0) + 1 });
                        });
                    });
                    return;
                }
                if (tag && ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'].includes(tag)) {
                    flushLoose();
                    const img = n.querySelector && n.querySelector('img[data-rp="logo"]');
                    if (img) {
                        const w = parseFloat(img.getAttribute('data-w')) || 30;
                        doc.push({ t: 'img', src: 'logo', w, a: alignOf(n) });
                        return;
                    }
                    if (tag === 'div' && n.querySelector('p,div,h1,h2,h3,ul,ol')) { walk(n, listCtx); return; }
                    const runs = [];
                    collectRuns(n, styleOf(n, {}), runs);
                    const t = ['h1', 'h2', 'h3'].includes(tag) ? tag : (tag === 'h4' || tag === 'h5' || tag === 'h6') ? 'h3' : 'p';
                    doc.push({ t, a: alignOf(n), r: trimRuns(runs) });
                    return;
                }
                if (tag === 'img' && n.getAttribute('data-rp') === 'logo') {
                    flushLoose();
                    doc.push({ t: 'img', src: 'logo', w: parseFloat(n.getAttribute('data-w')) || 30, a: 'center' });
                    return;
                }
                loose.push(n);
            });
            flushLoose();
        };
        const wrapList = (list) => { const d = document.createElement('div'); d.appendChild(list.cloneNode(true)); return d; };
        walk(root, null);
        // Keep an empty paragraph rather than nothing, so the box stays editable.
        return doc.length ? doc : [{ t: 'p', a: 'left', r: [] }];
    }

    // ── The in-place editor ───────────────────────────────────────────────
    let active = null;    // {el, onDone, region}

    // The ribbon's drop-downs and colour pickers take focus, and with it the
    // text selection the command is meant for. Remember the last selection
    // inside the editor so a command can put it back first.
    let savedRange = null;
    document.addEventListener('selectionchange', () => {
        if (!active) return;
        const s = window.getSelection();
        if (s.rangeCount && active.el.contains(s.anchorNode)) savedRange = s.getRangeAt(0).cloneRange();
    });
    function restoreRange() {
        if (!active || !savedRange) return;
        const s = window.getSelection();
        // Only when focus really took the selection away. If it is still in the
        // editor it is the newest one - selectionchange fires asynchronously, so
        // the saved copy can be a step behind.
        if (s.rangeCount && active.el.contains(s.anchorNode)) return;
        s.removeAllRanges();
        s.addRange(savedRange);
    }

    /**
     * Open an editor over a rectangle (CSS px, relative to `host`).
     * opts: {host, left, top, width, minHeight, doc, theme, zoom, logoUrl, onDone(doc), onInput}
     */
    function open(opts) {
        close(true);
        const el = document.createElement('div');
        el.className = 'rp-inline-editor';
        el.contentEditable = 'true';
        el.spellcheck = true;
        el.setAttribute('role', 'textbox');
        el.setAttribute('aria-multiline', 'true');
        el.setAttribute('aria-label', opts.label || T('designer.editing'));
        const pxPerMm = 96 / 25.4 * opts.zoom;
        Object.assign(el.style, {
            left: opts.left + 'px', top: opts.top + 'px', width: opts.width + 'px', minHeight: opts.minHeight + 'px',
            fontFamily: window.RPEngine.FONT_CSS[opts.theme.font],
            // 1pt on paper = (25.4/72) mm = that many mm at this zoom.
            fontSize: (opts.theme.size * 25.4 / 72 * pxPerMm) + 'px',
            '--rp-zoom': opts.zoom,
            '--rp-heading': opts.theme.heading,
        });
        el.innerHTML = docToHtml(opts.doc, opts.logoUrl);
        // Point sizes written by the toolbar are in pt; at a zoom they must scale.
        // So must a logo's width, which the model keeps in mm.
        el.querySelectorAll('[style*="font-size"]').forEach(n => scaleFont(n, pxPerMm));
        el.querySelectorAll('img[data-rp="logo"]').forEach(img => { img.style.width = (parseFloat(img.dataset.w) || 30) * pxPerMm + 'px'; });
        opts.host.appendChild(el);
        document.execCommand('styleWithCSS', false, true);
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }
        el.focus();
        placeCaretAtEnd(el);
        el.addEventListener('input', () => opts.onInput && opts.onInput());
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(false); }
            e.stopPropagation();       // keep designer shortcuts away from typing
        });
        el.addEventListener('paste', onPaste);
        active = { el, opts, pxPerMm };
        return el;
    }

    function scaleFont(node, pxPerMm) {
        const m = /([\d.]+)pt/.exec(node.style.fontSize || '');
        if (m) { node.dataset.pt = m[1]; node.style.fontSize = (+m[1] * 25.4 / 72 * pxPerMm) + 'px'; }
    }

    /** Pasting brings plain text only: a report's styles come from the report. */
    function onPaste(e) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, text);
    }

    function placeCaretAtEnd(el) {
        const r = document.createRange();
        r.selectNodeContents(el);
        r.collapse(false);
        const s = window.getSelection();
        s.removeAllRanges(); s.addRange(r);
    }

    /** End editing. commit=false (Escape) still keeps the text - Word has no "discard". */
    function close(commit) {
        if (!active) return;
        const { el, opts, pxPerMm } = active;
        active = null;
        savedRange = null;
        // Turn on-screen px font sizes back into points before reading the model.
        el.querySelectorAll('[style*="font-size"]').forEach(n => {
            const px = parseFloat(n.style.fontSize);
            if (n.dataset.pt) n.style.fontSize = n.dataset.pt + 'pt';
            else if (px) n.style.fontSize = (px / pxPerMm / (25.4 / 72)).toFixed(1) + 'pt';
        });
        const doc = htmlToDoc(el);
        el.remove();
        if (opts.onDone) opts.onDone(doc, commit !== false);
    }

    function isActive() { return !!active; }
    function element() { return active && active.el; }

    // ── Commands from the ribbon ───────────────────────────────────────────
    function cmd(name, value) {
        if (!active) return false;
        active.el.focus();
        restoreRange();
        if (name === 'fontSize') {
            // execCommand's sizes are 1-7; wrap the selection in a span of the exact size.
            wrapSelection(span => { span.style.fontSize = (value * 25.4 / 72 * active.pxPerMm) + 'px'; span.dataset.pt = value; });
            return true;
        }
        if (name === 'block') { document.execCommand('formatBlock', false, value); return true; }
        if (name === 'field') {
            const html = '<span class="rp-field" contenteditable="false" data-fld="' + esc(value) + '">' + esc(T('field.' + value)) + '</span>&#8203;';
            document.execCommand('insertHTML', false, html);
            return true;
        }
        if (name === 'logo') {
            document.execCommand('insertHTML', false, '<p style="text-align:center"><img class="rp-logo-img" data-rp="logo" data-w="30" src="' + esc(active.opts.logoUrl || '') + '" style="width:' + (30 * active.pxPerMm) + 'px"></p>');
            return true;
        }
        if (name === 'clear') { document.execCommand('removeFormat'); return true; }
        document.execCommand(name, false, value === undefined ? null : value);
        return true;
    }

    function wrapSelection(fn) {
        const sel = window.getSelection();
        if (!sel.rangeCount || sel.isCollapsed) return;
        const range = sel.getRangeAt(0);
        const span = document.createElement('span');
        fn(span);
        try { range.surroundContents(span); }
        catch (e) { span.appendChild(range.extractContents()); range.insertNode(span); }
        sel.removeAllRanges();
        const r = document.createRange(); r.selectNodeContents(span); sel.addRange(r);
    }

    /** What the cursor is sitting in, so the ribbon can show it (B pressed etc.). */
    function state() {
        if (!active) return null;
        const q = (c) => { try { return document.queryCommandState(c); } catch (e) { return false; } };
        let block = 'p';
        const sel = window.getSelection();
        if (sel.rangeCount) {
            let n = sel.anchorNode;
            while (n && n !== active.el) {
                if (n.nodeType === 1 && /^(H1|H2|H3)$/.test(n.tagName)) { block = n.tagName.toLowerCase(); break; }
                n = n.parentNode;
            }
        }
        return {
            bold: q('bold'), italic: q('italic'), underline: q('underline'), strike: q('strikeThrough'),
            ul: q('insertUnorderedList'), ol: q('insertOrderedList'),
            left: q('justifyLeft'), center: q('justifyCenter'), right: q('justifyRight'), justify: q('justifyFull'),
            block,
        };
    }

    /** Set the width (mm) of the logo the cursor is next to. */
    function setLogoWidth(mm) {
        if (!active) return;
        const img = active.el.querySelector('img[data-rp="logo"].rp-selected') || active.el.querySelector('img[data-rp="logo"]');
        if (!img) return;
        img.dataset.w = mm; img.style.width = (mm * active.pxPerMm) + 'px';
    }

    window.RPEditor = { open, close, cmd, state, isActive, element, docToHtml, htmlToDoc, setLogoWidth, FIELD_KEYS };
})();
