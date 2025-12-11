/**
 * Watcher Plugin - Common UI JavaScript
 *
 * Shared utilities used across multiple UI pages:
 * - connectivityUI, localMetricsUI, remoteMetricsUI
 * - remotePingUI, remoteControlUI, falconMonitorUI, configUI
 */

// Guard against double-loading (IIFE ensures proper scoping)
(function() {
    if (window._watcherCommonUILoaded) return;
    window._watcherCommonUILoaded = true;

    // =============================================================================
    // HTML Escaping
    // HTML Escaping Convention:
    // - JavaScript: Use escapeHtml() for all dynamic content in innerHTML
    // - PHP: Use h() or htmlspecialchars() in lib/ui/common.php
    // =============================================================================

    /**
     * HTML escape helper - prevents XSS by escaping special characters
     * Mirrors h() in lib/ui/common.php for naming consistency
     * @param {string|null|undefined} text - Text to escape
     * @returns {string} - Escaped text safe for innerHTML
     */
    window.escapeHtml = function(text) {
        if (text === null || text === undefined) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    };

    // =============================================================================
    // Button Loading State
    // =============================================================================

    window.withButtonLoading = async function(btn, originalIconClass, asyncFn) {
        const icon = btn?.querySelector('i');
        if (icon) icon.className = 'fas fa-spinner fa-spin';
        if (btn) btn.disabled = true;
        try {
            return await asyncFn();
        } finally {
            if (icon) icon.className = originalIconClass;
            if (btn) btn.disabled = false;
        }
    };

    // =============================================================================
    // DOM Helpers
    // =============================================================================

    window.showElement = function(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'block';
    };

    window.hideElement = function(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    };

    window.setLoading = function(id, show) {
        const el = document.getElementById(id);
        if (el) el.style.display = show ? 'flex' : 'none';
    };

    // =============================================================================
    // Fetch Helper
    // =============================================================================

    window.fetchJson = async function(url, timeout = 10000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        try {
            const res = await fetch(url, { signal: controller.signal, cache: 'no-store' });
            clearTimeout(timeoutId);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return await res.json();
        } catch (e) {
            clearTimeout(timeoutId);
            throw e;
        }
    };

    // =============================================================================
    // Chart.js Color Palette
    // =============================================================================

    window.CHART_COLORS = {
        purple: { border: 'rgb(102, 126, 234)', bg: 'rgba(102, 126, 234, 0.1)' },
        red: { border: 'rgb(245, 87, 108)', bg: 'rgba(245, 87, 108, 0.1)' },
        green: { border: 'rgb(56, 239, 125)', bg: 'rgba(56, 239, 125, 0.1)' },
        blue: { border: 'rgb(79, 172, 254)', bg: 'rgba(79, 172, 254, 0.1)' },
        pink: { border: 'rgb(240, 147, 251)', bg: 'rgba(240, 147, 251, 0.1)' },
        orange: { border: 'rgb(255, 159, 64)', bg: 'rgba(255, 159, 64, 0.1)' },
        teal: { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
        coral: { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
        yellow: { border: 'rgb(255, 193, 7)', bg: 'rgba(255, 193, 7, 0.1)' },
        cyan: { border: 'rgb(23, 162, 184)', bg: 'rgba(23, 162, 184, 0.1)' },
        indigo: { border: 'rgb(111, 66, 193)', bg: 'rgba(111, 66, 193, 0.1)' }
    };

    // Array of colors for multi-host charts
    const CHART_COLOR_ARRAY = [
        CHART_COLORS.purple, CHART_COLORS.green, CHART_COLORS.pink, CHART_COLORS.blue,
        CHART_COLORS.coral, CHART_COLORS.yellow, CHART_COLORS.cyan, CHART_COLORS.indigo
    ];

    window.getChartColor = function(index) {
        return CHART_COLOR_ARRAY[index % CHART_COLOR_ARRAY.length];
    };

    // =============================================================================
    // Chart.js Time Helpers
    // =============================================================================

    function getTimeUnit(hours) {
        if (hours <= 1) return 'minute';
        if (hours <= 24) return 'hour';
        if (hours <= 168) return 'day';
        return 'week';
    }

    function getTimeFormats() {
        return {
            minute: 'HH:mm',
            hour: 'MMM d, HH:mm',
            day: 'MMM d',
            week: 'MMM d, yyyy'
        };
    }

    // =============================================================================
    // Chart.js Dataset Factory
    // =============================================================================

    window.createDataset = function(label, data, color, options = {}) {
        const c = typeof color === 'string' ? (CHART_COLORS[color] || CHART_COLORS.purple) : color;
        return {
            label,
            data,
            borderColor: c.border,
            backgroundColor: c.bg,
            borderWidth: 2,
            fill: options.fill ?? true,
            tension: 0,
            spanGaps: true,
            pointRadius: options.pointRadius ?? 0,
            pointHoverRadius: 5,
            ...options
        };
    };

    window.mapChartData = function(payload, field) {
        return (payload?.data || []).map(e => ({ x: e.timestamp * 1000, y: e[field] }));
    };

    // =============================================================================
    // Chart.js Options Builder
    // =============================================================================

    window.buildChartOptions = function(hours, config = {}) {
        const {
            yLabel = 'Value',
            beginAtZero = false,
            yMax,
            yTickFormatter = v => v,
            tooltipLabel,
            showLegend = true,
            animation = false,
            decimationSamples = 500
        } = config;

        const unit = getTimeUnit(hours);
        const formats = getTimeFormats();

        return {
            responsive: true,
            maintainAspectRatio: true,
            animation: animation,
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            },
            plugins: {
                decimation: {
                    enabled: true,
                    algorithm: 'lttb',
                    samples: decimationSamples
                },
                legend: {
                    display: showLegend,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        title: ctx => new Date(ctx[0].parsed.x).toLocaleString(),
                        label: tooltipLabel || (ctx => `${ctx.dataset.label}: ${ctx.parsed.y}`)
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit,
                        displayFormats: formats,
                        tooltipFormat: 'MMM d, yyyy HH:mm:ss'
                    },
                    title: {
                        display: true,
                        text: 'Time',
                        font: { size: 14, weight: 'bold' }
                    },
                    grid: {
                        display: true,
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    beginAtZero,
                    ...(yMax !== undefined && { max: yMax }),
                    title: {
                        display: true,
                        text: yLabel,
                        font: { size: 14, weight: 'bold' }
                    },
                    grid: {
                        display: true,
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        callback: yTickFormatter
                    }
                }
            }
        };
    };

    // =============================================================================
    // Chart.js Update/Create Helper
    // =============================================================================

    window.updateOrCreateChart = function(chartsMap, key, canvasId, type, datasets, options) {
        if (chartsMap[key]) {
            const chart = chartsMap[key];
            // Update existing datasets' data in place for smooth updates
            datasets.forEach((newDataset, i) => {
                if (chart.data.datasets[i]) {
                    chart.data.datasets[i].data = newDataset.data;
                    chart.data.datasets[i].label = newDataset.label;
                } else {
                    chart.data.datasets.push(newDataset);
                }
            });
            // Remove extra datasets if there are fewer now
            chart.data.datasets.length = datasets.length;
            chart.update('none');
            return chart;
        }

        const ctx = document.getElementById(canvasId)?.getContext('2d');
        if (!ctx) return null;

        chartsMap[key] = new Chart(ctx, {
            type,
            data: { datasets },
            options
        });
        return chartsMap[key];
    };

    // =============================================================================
    // Refresh State Controller
    // =============================================================================

    window.createRefreshController = function(refreshFn, intervalMs = 30000) {
        let isRefreshing = false;
        let intervalId = null;

        const controller = {
            get isRefreshing() { return isRefreshing; },

            async refresh(showLoading = true) {
                if (isRefreshing) return;
                isRefreshing = true;

                const refreshBtn = document.querySelector('.refreshButton i');
                if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

                try {
                    await refreshFn(showLoading);
                } finally {
                    isRefreshing = false;
                    if (refreshBtn) refreshBtn.style.animation = '';
                }
            },

            startAutoRefresh() {
                if (!intervalId) {
                    intervalId = setInterval(() => controller.refresh(false), intervalMs);
                }
            },

            stopAutoRefresh() {
                if (intervalId) {
                    clearInterval(intervalId);
                    intervalId = null;
                }
            }
        };

        return controller;
    };

    // =============================================================================
    // Time/Date Helpers
    // =============================================================================

    window.updateLastUpdateTime = function(elementId = 'lastUpdate') {
        const el = document.getElementById(elementId);
        if (el) {
            el.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
        }
    };

    // =============================================================================
    // Format Helpers
    // =============================================================================

    window.formatBytes = function(bytes) {
        if (!bytes) return '0 Bytes';
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + ['Bytes', 'KB', 'MB', 'GB', 'TB'][i];
    };

    window.formatLatency = function(ms) {
        return ms !== null && ms !== undefined ? ms.toFixed(2) + ' ms' : '-- ms';
    };

    window.formatPercent = function(value, decimals = 1) {
        return value !== null && value !== undefined ? value.toFixed(decimals) + '%' : '--%';
    };

    window.formatDuration = function(seconds) {
        if (!seconds || seconds <= 0) return '--';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        if (h > 0) return `${h}h ${m}m ${s}s`;
        if (m > 0) return `${m}m ${s}s`;
        return `${s}s`;
    };

    // =============================================================================
    // Temperature Helpers
    // =============================================================================

    window.toFahrenheit = function(celsius) {
        return celsius * 9/5 + 32;
    };

    window.getTempUnit = function(useFahrenheit) {
        return useFahrenheit ? '°F' : '°C';
    };

    window.formatTemp = function(celsius, useFahrenheit) {
        const value = useFahrenheit ? window.toFahrenheit(celsius) : celsius;
        return value.toFixed(1) + window.getTempUnit(useFahrenheit);
    };

    window.getTempStatus = function(celsius) {
        if (celsius < 40) return { text: 'Cool', color: '#38ef7d', icon: '❄️' };
        if (celsius < 60) return { text: 'Normal', color: '#28a745', icon: '✅' };
        if (celsius < 80) return { text: 'Warm', color: '#ffc107', icon: '⚠️' };
        return { text: 'Hot', color: '#f5576c', icon: '🔥' };
    };

    /**
     * Format thermal zone type for display.
     * Converts "cpu-thermal" to "CPU Thermal", etc.
     * @param {string} type - Raw type from sysfs (e.g., "cpu-thermal")
     * @returns {string} Formatted display name (e.g., "CPU Thermal")
     */
    window.formatThermalZoneName = function(type) {
        if (!type) return type;
        const abbreviations = { cpu: 'CPU', gpu: 'GPU', soc: 'SoC', acpi: 'ACPI', pch: 'PCH' };
        // Replace underscores/hyphens with spaces and capitalize each word
        let formatted = type.replace(/[_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        // Apply abbreviation replacements (case-insensitive)
        Object.entries(abbreviations).forEach(([search, replace]) => {
            formatted = formatted.replace(new RegExp('\\b' + search + '\\b', 'gi'), replace);
        });
        return formatted;
    };

    window.loadTemperaturePreference = async function() {
        const cached = localStorage.getItem('temperatureInF');
        if (cached !== null) {
            return cached === 'true';
        }
        try {
            const { value } = await fetchJson('/api/settings/temperatureInF');
            const useFahrenheit = value === '1' || value === 1;
            localStorage.setItem('temperatureInF', useFahrenheit);
            return useFahrenheit;
        } catch {
            return false;
        }
    };

})();
