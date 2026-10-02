/**
 * Report Packs - the runtime shared by the designer and by export.
 *
 * Owns the things a pack needs besides its design: each block's data (fetched
 * with the VIEWER's rights, cached by handler + options + criteria so moving a
 * block never refetches it), the header fields for the period, the logo, and
 * the chart images. layout() asks the engine for pages; exportPdf() turns the
 * same pages into a file.
 */
(function () {
    'use strict';

    const T = (k, p) => (window.t ? window.t('reporting.packs.' + k, p) : k);

    function create(apiBase, logoUrl) {
        const rt = {
            design: null, name: '', fields: {}, logo: null,
            data: new Map(),          // cacheKey -> {data} | {error} | {pending: Promise}
            onChange: null,
            unsupported: false,
        };
        const chartCache = new Map();
        let queue = [], running = 0;

        function post(path, body) {
            return fetch(apiBase + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(r => r.json().catch(() => ({ success: false, error: T('err.block') })));
        }

        function keyFor(b) {
            return JSON.stringify([b.handler, b.opts || {}, rt.design.criteria]);
        }

        function pump() {
            while (running < 4 && queue.length) {
                const job = queue.shift();
                running++;
                post('block_data.php', { handler: job.b.handler, opts: job.b.opts || {}, criteria: rt.design.criteria })
                    .then(r => r.success ? { data: r.data } : { error: r.error || T('err.block') })
                    .catch(() => ({ error: T('err.block') }))
                    .then(res => {
                        rt.data.set(job.key, res);
                        running--;
                        job.resolve();
                        pump();
                        if (rt.onChange && !job.quiet) rt.onChange('data');
                    });
            }
        }

        /** Start fetching whatever the design needs and is not cached. Resolves when all are in. */
        rt.loadData = function () {
            const waits = [];
            rt.design.blocks.forEach(b => {
                if (b.type !== 'data') return;
                const key = keyFor(b);
                const have = rt.data.get(key);
                if (have && have.pending) { waits.push(have.pending); return; }
                if (have) return;
                let resolve;
                const pending = new Promise(r => { resolve = r; });
                rt.data.set(key, { pending });
                queue.push({ b, key, resolve });
                waits.push(pending);
            });
            pump();
            return Promise.all(waits);
        };

        /** One block's data, through the same cache and queue (toolbox previews). */
        rt.fetchBlock = function (b) {
            const key = keyFor(b);
            const have = rt.data.get(key);
            if (have && !have.pending) return Promise.resolve(have);
            if (have && have.pending) return have.pending.then(() => rt.data.get(key));
            let resolve;
            const pending = new Promise(r => { resolve = r; });
            rt.data.set(key, { pending });
            queue.push({ b, key, resolve, quiet: true });
            pump();
            return pending.then(() => rt.data.get(key));
        };

        /** Forget cached data (the Refresh button, or new criteria). */
        rt.refresh = function () { rt.data.clear(); chartCache.clear(); return rt.loadContext().then(rt.loadData); };

        rt.dataMap = function () {
            const m = {};
            rt.design.blocks.forEach(b => {
                if (b.type !== 'data') return;
                const e = rt.data.get(keyFor(b));
                m[b.id] = e && !e.pending ? e : undefined;
            });
            return m;
        };

        /** The header fields for the current criteria. */
        rt.loadContext = function () {
            return post('context.php', { criteria: rt.design.criteria }).then(r => {
                if (r.success) {
                    rt.fields = r.fields;
                    rt.range = r.range;
                    rt.companyError = r.company_error;
                }
            });
        };

        rt.loadLogo = function () {
            return new Promise(resolve => {
                if (!logoUrl) { resolve(null); return; }
                const img = new Image();
                img.onload = () => {
                    // Rasterise once (an SVG logo has to be, for the PDF) at a size
                    // that prints crisply, keeping its shape.
                    const w0 = img.naturalWidth || 300, h0 = img.naturalHeight || 100;
                    const scale = Math.min(4, 1200 / w0);
                    const c = document.createElement('canvas');
                    c.width = Math.max(1, Math.round(w0 * scale)); c.height = Math.max(1, Math.round(h0 * scale));
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    let dataUrl = null;
                    try { dataUrl = c.toDataURL('image/png'); } catch (e) { /* tainted: no logo in the PDF */ }
                    rt.logo = { w: w0, h: h0, url: logoUrl, dataUrl };
                    resolve(rt.logo);
                };
                img.onerror = () => resolve(null);
                img.src = logoUrl;
            });
        };

        rt.ctx = function () {
            return {
                fields: rt.fields, title: rt.name,
                logo: rt.logo ? { w: rt.logo.w, h: rt.logo.h } : null,
                labels: { loading: T('designer.loading_block'), snapshot: T('designer.snapshot_note') },
            };
        };

        rt.layout = function () {
            return window.RPEngine.paginate(rt.design, rt.dataMap(), rt.ctx());
        };

        /** A chart item's picture at `scale`, cached on everything that changes it. */
        rt.chartImage = function (item, scale) {
            const c = item.chart;
            const key = [item.id, c.w.toFixed(2), c.h.toFixed(2), scale, JSON.stringify(rt.design.theme), JSON.stringify(c.data), JSON.stringify(c.block.legend || '')].join('|');
            let url = chartCache.get(key);
            if (!url) {
                const canvas = window.RPCharts.render(c, rt.design.theme, scale, { noData: T('designer.no_data') });
                url = canvas.toDataURL('image/png');
                if (chartCache.size > 200) chartCache.clear();
                chartCache.set(key, url);
            }
            return url;
        };

        /** True when some text cannot be put into the PDF's fonts. */
        rt.checkUnsupported = function () {
            const E = window.RPEngine;
            const texts = [];
            const docText = d => (d || []).forEach(p => (p.r || []).forEach(r => r.x && texts.push(r.x)));
            docText(rt.design.header.doc); docText(rt.design.footer.doc); docText(rt.design.cover.doc);
            rt.design.blocks.forEach(b => { if (b.doc) docText(b.doc); if (b.text) texts.push(b.text); if (b.title) texts.push(b.title); });
            texts.push(rt.name);
            rt.unsupported = texts.some(t => E.hasUnsupported(t));
            return rt.unsupported;
        };

        rt.fileName = function () {
            const clean = s => String(s || '').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '-').replace(/\s+/g, ' ').trim();
            const period = rt.fields.date_from && rt.fields.date_to
                ? ' - ' + clean(rt.fields.date_from).replace(/\//g, '.') + ' to ' + clean(rt.fields.date_to).replace(/\//g, '.')
                : '';
            return (clean(rt.name) || 'Report').slice(0, 150) + period + '.pdf';
        };

        /** Load everything, lay out, and build the PDF. Resolves to the jsPDF document. */
        rt.buildPdf = async function () {
            await rt.loadContext();
            await rt.loadData();
            const layout = rt.layout();
            return window.RPRenderPdf.build(layout, {
                title: rt.name,
                logo: rt.logo && rt.logo.dataUrl ? rt.logo : null,
                chartImage: (it) => rt.chartImage(it, 3),
            });
        };

        rt.exportPdf = async function () {
            const doc = await rt.buildPdf();
            doc.save(rt.fileName());
            return doc;
        };

        return rt;
    }

    window.RPRuntime = { create };
})();
