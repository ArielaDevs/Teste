/**
 * Report Packs - charts.
 *
 * Every chart is drawn by Chart.js onto a canvas that is a fixed size IN
 * MILLIMETRES (CSS pixels = mm x PX_PER_MM). Only the resolution changes:
 * devicePixelRatio is the zoom on screen and ~300 dpi for the PDF. Because
 * Chart.js lays out in CSS pixels, the legend, labels and bars land in the same
 * places at every resolution - the PDF's chart is the screen's chart, sharper.
 */
(function () {
    'use strict';

    const PX_PER_MM = 4;                 // layout scale; resolution is separate
    const PT = 25.4 / 72;

    const PALETTES = {
        default: ['#5b6abf', '#e8553e', '#2fbf71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#3498db', '#e91e63', '#00bcd4', '#8bc34a', '#ff9800', '#673ab7', '#009688', '#ff5722', '#607d8b', '#795548', '#cddc39'],
        ocean:   ['#0b4f6c', '#01baef', '#20bf55', '#0b7a75', '#7dcfb6', '#1d4e89', '#00b2ca', '#f79256', '#4b8f8c', '#5c80bc', '#2ec4b6', '#3d5a80'],
        sunset:  ['#e63946', '#f4a261', '#e9c46a', '#2a9d8f', '#264653', '#ff7b54', '#ffb26b', '#d62828', '#f77f00', '#fcbf49', '#9d0208', '#6a040f'],
        forest:  ['#2d6a4f', '#52b788', '#95d5b2', '#1b4332', '#74c69d', '#b7e4c7', '#40916c', '#d8f3dc', '#081c15', '#a3b18a', '#588157', '#3a5a40'],
        mono:    ['#1f3864', '#2f5597', '#4472c4', '#8faadc', '#b4c7e7', '#203864', '#5b7db5', '#9dc3e6', '#2e4a7a', '#6f8fc7', '#c9d7ee', '#41608f'],
        vivid:   ['#ff006e', '#3a86ff', '#ffbe0b', '#8338ec', '#fb5607', '#06d6a0', '#118ab2', '#ef476f', '#ffd166', '#073b4c', '#7209b7', '#4cc9f0'],
    };

    function colours(data, palette) {
        const p = PALETTES[palette] || PALETTES.default;
        return (data.labels || []).map((_, i) => (data.colours && data.colours[i]) || p[i % p.length]);
    }

    /**
     * Draw `chart` ({w, h in mm, data, block}) and return a canvas.
     * scale = device pixels per CSS pixel.
     */
    function render(chart, theme, scale, labels) {
        const wPx = Math.max(40, Math.round(chart.w * PX_PER_MM));
        const hPx = Math.max(40, Math.round(chart.h * PX_PER_MM));
        const holder = document.createElement('div');
        holder.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + wPx + 'px;height:' + hPx + 'px';
        const canvas = document.createElement('canvas');
        // ⚠️ The LAYOUT size, not the backing size. With responsive off, Chart.js
        // takes the canvas's own width/height as its CSS size and multiplies by
        // devicePixelRatio itself. Handing it wPx * scale made every chart lay
        // out `scale` times larger with the same fonts - legible on screen at 1x,
        // a third of the size in the 3x PDF.
        canvas.width = wPx; canvas.height = hPx;
        canvas.style.width = wPx + 'px'; canvas.style.height = hPx + 'px';
        holder.appendChild(canvas);
        document.body.appendChild(holder);

        const d = chart.data;
        const type = d.chart || 'bar';
        const round = type === 'doughnut' || type === 'pie';
        const fontPx = (pt) => pt * PT * PX_PER_MM;
        const font = { family: window.RPEngine.FONT_CSS[theme.font] || 'Helvetica, Arial, sans-serif', size: fontPx(theme.size - 1.5) };
        const pal = colours(d, theme.palette);
        const seriesPal = PALETTES[theme.palette] || PALETTES.default;

        const empty = !(d.labels || []).length || (d.series || []).every(s => !s.values.some(v => v));
        let cfg;
        if (empty) {
            cfg = null;
        } else if (round) {
            const legend = chart.block.legend && chart.block.legend !== 'auto' ? chart.block.legend : (chart.w >= 95 ? 'right' : 'bottom');
            cfg = {
                type,
                data: { labels: d.labels, datasets: [{ data: d.series[0].values, backgroundColor: pal, borderColor: '#ffffff', borderWidth: 1.5 }] },
                options: {
                    cutout: type === 'doughnut' ? '52%' : 0,
                    plugins: {
                        legend: { display: legend !== 'none', position: legend, labels: { font, boxWidth: fontPx(7), boxHeight: fontPx(7), padding: fontPx(4), color: '#333' } },
                    },
                },
            };
        } else {
            const multi = (d.series || []).length > 1;
            const datasets = d.series.map((s, i) => ({
                label: s.name,
                data: s.values,
                backgroundColor: multi ? seriesPal[i % seriesPal.length] : (type === 'line' ? seriesPal[0] : pal),
                borderColor: multi || type === 'line' ? seriesPal[i % seriesPal.length] : pal,
                borderWidth: type === 'line' ? fontPx(1.6) : 0,
                pointRadius: type === 'line' ? ((d.labels.length > 40) ? 0 : fontPx(1.6)) : 0,
                tension: 0.25,
                fill: false,
                borderRadius: type === 'line' ? 0 : fontPx(1),
            }));
            const horizontal = type === 'hbar';
            cfg = {
                type: type === 'line' ? 'line' : 'bar',
                data: { labels: d.labels, datasets },
                options: {
                    indexAxis: horizontal ? 'y' : 'x',
                    plugins: { legend: { display: multi, position: 'bottom', labels: { font, boxWidth: fontPx(7), color: '#333' } } },
                    scales: {
                        x: { ticks: { font, color: '#555', maxRotation: horizontal ? 0 : 50, autoSkip: true }, grid: { color: horizontal ? '#eceff2' : 'transparent' } },
                        y: { ticks: { font, color: '#555', precision: 0, autoSkip: !horizontal }, grid: { color: horizontal ? 'transparent' : '#eceff2' }, beginAtZero: true },
                    },
                },
            };
        }

        let chartObj = null;
        if (cfg) {
            Object.assign(cfg.options, {
                responsive: false, animation: false, devicePixelRatio: scale,
                maintainAspectRatio: false, layout: { padding: fontPx(2) },
            });
            cfg.options.plugins.tooltip = { enabled: false };
            chartObj = new window.Chart(canvas.getContext('2d'), cfg);
        } else {
            canvas.width = wPx * scale; canvas.height = hPx * scale;
            const ctx = canvas.getContext('2d');
            ctx.scale(scale, scale);
            ctx.fillStyle = '#8a8f98';
            ctx.font = 'italic ' + font.size + 'px ' + font.family;
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillText((labels && labels.noData) || 'No data for this period.', wPx / 2, hPx / 2);
        }
        // Chart.js draws synchronously with animation off; detach before returning.
        const out = document.createElement('canvas');
        out.width = canvas.width; out.height = canvas.height;
        out.getContext('2d').drawImage(canvas, 0, 0);
        if (chartObj) chartObj.destroy();
        holder.remove();
        return out;
    }

    window.RPCharts = { render, PALETTES, PX_PER_MM };
})();
