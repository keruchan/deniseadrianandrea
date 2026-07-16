/**
 * insights.js — draws every <canvas class="insights-chart" data-chart="…"> the
 * Insights render layer emits, using Chart.js (loaded via CDN before this file).
 *
 * Chart JSON contract (see includes/insights_render.php -> insights_chart_tag):
 *   { type: 'line'|'bar'|'hbar'|'pie'|'doughnut'|'scatter',
 *     labels: [...],
 *     series: [{ label, data, color }],   // scatter data = [{x,y,label}]
 *     yMax?, xMax?, yLabel?, xLabel?, stacked? }
 *
 * Colour rule (dataviz skill): magnitude/trends use a single hue; categorical
 * pies use a fixed-order palette; every chart keeps a legend/labels/table beside
 * it so identity is never carried by colour alone.
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined') {
        return;
    }

    // Fixed-order palette, sourced from the app's design tokens (css/dashboard.css).
    var PALETTE = {
        indigo: '#4f46e5',
        emerald: '#10b981',
        amber: '#f59e0b',
        rose: '#f43f5e',
        blue: '#1e3a8a',
        slate: '#64748b'
    };
    // Fixed categorical order for pie/doughnut slices.
    var CATEGORICAL = ['#4f46e5', '#10b981', '#f59e0b', '#f43f5e', '#1e3a8a', '#64748b'];

    var GRID = 'rgba(148, 163, 184, 0.22)';
    var TICK = '#475569';

    Chart.defaults.font.family = "'Inter', 'Manrope', system-ui, sans-serif";
    Chart.defaults.color = TICK;

    var charts = [];

    function hexToRgba(hex, alpha) {
        var h = hex.replace('#', '');
        var r = parseInt(h.substring(0, 2), 16);
        var g = parseInt(h.substring(2, 4), 16);
        var b = parseInt(h.substring(4, 6), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }

    function colorFor(name) {
        return PALETTE[name] || PALETTE.indigo;
    }

    function baseScales(cfg, horizontal) {
        var valueAxis = {
            beginAtZero: true,
            grid: { color: GRID, drawBorder: false },
            ticks: { color: TICK }
        };
        var catAxis = {
            grid: { display: false, drawBorder: false },
            ticks: { color: TICK, autoSkip: false, maxRotation: 0, minRotation: 0 }
        };
        if (horizontal) {
            if (cfg.xMax != null) { valueAxis.max = cfg.xMax; }
            return { x: valueAxis, y: catAxis };
        }
        if (cfg.yMax != null) { valueAxis.max = cfg.yMax; }
        return { x: catAxis, y: valueAxis };
    }

    function buildCartesian(cfg) {
        var horizontal = cfg.type === 'hbar';
        var isLine = cfg.type === 'line';
        var datasets = (cfg.series || []).map(function (s) {
            var hex = colorFor(s.color);
            if (isLine) {
                return {
                    label: s.label,
                    data: s.data,
                    borderColor: hex,
                    backgroundColor: hexToRgba(hex, 0.12),
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: hex,
                    tension: 0.3,
                    fill: true
                };
            }
            return {
                label: s.label,
                data: s.data,
                backgroundColor: hexToRgba(hex, 0.85),
                hoverBackgroundColor: hex,
                borderRadius: 4,
                borderSkipped: false,
                maxBarThickness: 46
            };
        });

        return {
            type: 'bar' === cfg.type || horizontal ? 'bar' : cfg.type,
            data: { labels: cfg.labels || [], datasets: datasets },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                interaction: isLine ? { mode: 'index', intersect: false } : { mode: 'nearest', intersect: true },
                scales: baseScales(cfg, horizontal),
                plugins: {
                    legend: { display: (cfg.series || []).length > 1, labels: { color: TICK, usePointStyle: true } },
                    tooltip: { enabled: true }
                }
            }
        };
    }

    function buildPie(cfg) {
        var series = (cfg.series || [])[0] || { data: [], label: '' };
        var colors = (cfg.labels || []).map(function (_, i) { return CATEGORICAL[i % CATEGORICAL.length]; });
        return {
            type: cfg.type,
            data: {
                labels: cfg.labels || [],
                datasets: [{
                    label: series.label,
                    data: series.data,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: cfg.type === 'doughnut' ? '58%' : 0,
                plugins: {
                    legend: { position: 'right', labels: { color: TICK, usePointStyle: true } },
                    tooltip: { enabled: true }
                }
            }
        };
    }

    function buildScatter(cfg) {
        var datasets = (cfg.series || []).map(function (s) {
            var hex = colorFor(s.color);
            return {
                label: s.label,
                data: s.data,
                backgroundColor: hexToRgba(hex, 0.7),
                borderColor: hex,
                pointRadius: 5,
                pointHoverRadius: 7
            };
        });
        return {
            type: 'scatter',
            data: { datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        max: cfg.xMax != null ? cfg.xMax : undefined,
                        title: { display: !!cfg.xLabel, text: cfg.xLabel || '', color: TICK },
                        grid: { color: GRID, drawBorder: false },
                        ticks: { color: TICK }
                    },
                    y: {
                        beginAtZero: true,
                        max: cfg.yMax != null ? cfg.yMax : undefined,
                        title: { display: !!cfg.yLabel, text: cfg.yLabel || '', color: TICK },
                        grid: { color: GRID, drawBorder: false },
                        ticks: { color: TICK }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var p = ctx.raw || {};
                                var name = p.label ? p.label + ': ' : '';
                                return name + '(' + p.x + ', ' + p.y + ')';
                            }
                        }
                    }
                }
            }
        };
    }

    function buildConfig(cfg) {
        if (cfg.type === 'pie' || cfg.type === 'doughnut') {
            return buildPie(cfg);
        }
        if (cfg.type === 'scatter') {
            return buildScatter(cfg);
        }
        return buildCartesian(cfg);
    }

    function initChart(canvas) {
        if (canvas.dataset.insightsInit === '1') {
            return;
        }
        var raw = canvas.getAttribute('data-chart');
        if (!raw) {
            return;
        }
        var cfg;
        try {
            cfg = JSON.parse(raw);
        } catch (e) {
            return;
        }
        try {
            var chart = new Chart(canvas.getContext('2d'), buildConfig(cfg));
            charts.push(chart);
            canvas.dataset.insightsInit = '1';
        } catch (e) {
            /* leave the canvas blank rather than break the page */
        }
    }

    function initAll() {
        document.querySelectorAll('canvas.insights-chart').forEach(initChart);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAll();

        // Charts inside inactive Bootstrap tab-panes render at 0px; resize on show.
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function () {
                charts.forEach(function (c) { c.resize(); });
            });
        });

        // After a Goal Analysis submit (the one action that still reloads), bring the
        // student-insights tabs into view instead of snapping to the top of the page.
        var tabset = document.querySelector('[data-insights-tabset]');
        if (tabset && /[?&]goal=/.test(window.location.search)) {
            tabset.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();
