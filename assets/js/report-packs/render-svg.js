/**
 * Report Packs - draw the engine's pages on screen, as SVG in millimetres.
 *
 * This renderer makes no layout decisions. Every x, y and width comes from
 * engine.js, and each text run is pinned to the width the engine measured
 * (textLength) - so if the browser's Arial differs a hair from the PDF's
 * Helvetica, the run is nudged to fit rather than wrapping differently.
 *
 * Text is set with textContent only; nothing from a pack is ever parsed as markup.
 */
(function () {
    'use strict';

    const NS = 'http://www.w3.org/2000/svg';
    const PT = 25.4 / 72;

    function el(name, attrs, parent) {
        const e = document.createElementNS(NS, name);
        for (const k in attrs) if (attrs[k] !== undefined && attrs[k] !== null) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }

    function fontAttrs(st) {
        return {
            'font-family': window.RPEngine.FONT_CSS[st.font] || window.RPEngine.FONT_CSS.helvetica,
            'font-size': (st.size * PT).toFixed(3),
            'font-weight': st.b ? '700' : '400',
            'font-style': st.i ? 'italic' : 'normal',
            fill: st.color || '#222',
        };
    }

    function drawText(g, x, y, text, st, w) {
        if (!text) return;
        if (st.hl) el('rect', { x, y: y - st.size * PT * 0.82, width: w, height: st.size * PT * 1.08, fill: st.hl }, g);
        const t = el('text', Object.assign({ x: x.toFixed(3), y: y.toFixed(3), 'xml:space': 'preserve' }, fontAttrs(st)), g);
        t.textContent = text;
        if (w > 0.5 && text.trim()) {
            t.setAttribute('textLength', w.toFixed(3));
            t.setAttribute('lengthAdjust', 'spacingAndGlyphs');
        }
        const lw = Math.max(0.15, st.size * PT * 0.07);
        if (st.u) el('line', { x1: x, x2: x + w, y1: y + st.size * PT * 0.12, y2: y + st.size * PT * 0.12, stroke: st.color || '#222', 'stroke-width': lw }, g);
        if (st.s) el('line', { x1: x, x2: x + w, y1: y - st.size * PT * 0.28, y2: y - st.size * PT * 0.28, stroke: st.color || '#222', 'stroke-width': lw }, g);
    }

    function drawPrim(g, d) {
        switch (d.k) {
            case 'text': drawText(g, d.x, d.y, d.text, d.st, d.w); break;
            case 'rect':
                el('rect', {
                    x: d.x, y: d.y, width: Math.max(0, d.w), height: Math.max(0, d.h), rx: d.r || 0,
                    fill: d.fill || 'none', stroke: d.stroke || null, 'stroke-width': d.stroke ? 0.25 : null,
                    'stroke-dasharray': d.dash ? '1.2 1' : null,
                }, g);
                break;
            case 'line':
                el('line', { x1: d.x1, y1: d.y1, x2: d.x2, y2: d.y2, stroke: d.color, 'stroke-width': d.width }, g);
                break;
            case 'dots':
                if (d.x2 > d.x1) el('line', { x1: d.x1, y1: d.y, x2: d.x2, y2: d.y, stroke: d.color, 'stroke-width': 0.3, 'stroke-dasharray': '0.3 1.1', 'stroke-linecap': 'round' }, g);
                break;
        }
    }

    function drawRich(g, rich, logoUrl) {
        if (!rich || !rich.L) return;
        const { L, x, y } = rich;
        L.images.forEach(im => {
            if (logoUrl) el('image', { href: logoUrl, x: x + im.x, y: y + im.y, width: im.w, height: im.h, preserveAspectRatio: 'xMidYMid meet' }, g);
            else el('rect', { x: x + im.x, y: y + im.y, width: im.w, height: im.h, fill: '#eef0f3' }, g);
        });
        L.lines.forEach(line => {
            if (line.marker) drawText(g, x + line.marker.x, y + line.baseline, line.marker.text, line.marker.st, 0);
            line.items.forEach(it => drawText(g, x + it.x, y + line.baseline, it.text, it.st, it.w));
        });
    }

    /**
     * Draw one page into a new <svg>. opts: {logoUrl, chartImage(item) -> dataURL|null}
     */
    function page(pg, G, opts) {
        const svg = el('svg', {
            xmlns: NS, viewBox: '0 0 ' + G.pw + ' ' + G.ph,
            width: G.pw + 'mm', height: G.ph + 'mm', class: 'rp-page-svg',
            'text-rendering': 'geometricPrecision',
        });
        el('rect', { x: 0, y: 0, width: G.pw, height: G.ph, fill: '#ffffff' }, svg);
        const g = el('g', {}, svg);

        if (pg.headerRich) {
            drawRich(el('g', { class: 'rp-svg-header' }, g), pg.headerRich, opts.logoUrl);
            if (pg.headerRule) el('line', { x1: G.m.l, x2: G.pw - G.m.r, y1: pg.headerRule.y, y2: pg.headerRule.y, stroke: '#333', 'stroke-width': 0.3 }, g);
        }
        if (pg.footerRich) {
            drawRich(el('g', { class: 'rp-svg-footer' }, g), pg.footerRich, opts.logoUrl);
            if (pg.footerRule) el('line', { x1: G.m.l, x2: G.pw - G.m.r, y1: pg.footerRule.y, y2: pg.footerRule.y, stroke: '#bbb', 'stroke-width': 0.25 }, g);
        }
        if (pg.coverRich) drawRich(g, pg.coverRich, opts.logoUrl);
        if (pg.toc) pg.toc.forEach(d => drawPrim(g, d));

        pg.items.forEach(it => {
            const bg = el('g', { 'data-block': it.id }, g);
            it.draw.forEach(d => drawPrim(bg, d));
            if (it.rich) drawRich(bg, it.rich, opts.logoUrl);
            if (it.chart) {
                const url = opts.chartImage ? opts.chartImage(it) : null;
                if (url) el('image', { href: url, x: it.chart.x, y: it.chart.y, width: it.chart.w, height: it.chart.h, preserveAspectRatio: 'none' }, bg);
            }
        });
        return svg;
    }

    window.RPRenderSvg = { page };
})();
