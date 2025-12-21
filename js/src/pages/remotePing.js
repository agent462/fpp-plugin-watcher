/**
 * Remote Ping Page Module
 *
 * Multi-sync host ping metrics dashboard with per-host statistics.
 * Extracted from remotePingUI.php
 */

import {
  escapeHtml,
  showElement,
  hideElement,
  updateLastUpdateTime,
} from '../core/utils.js';
import {
  buildChartOptions,
  createDataset,
  updateOrCreateChart,
  getChartColor,
} from '../core/charts.js';
import { fetchJson } from '../core/api.js';

// =============================================================================
// Module State
// =============================================================================

let state = {
  charts: {},
  isRefreshing: false,
  refreshInterval: null,
  config: {},
};

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Group data entries by host
 * @param {Array} data - Array of data entries with hostname property
 * @returns {Object} - Object keyed by hostname with entries array and address
 */
function groupByHost(data) {
  const grouped = {};
  data.forEach((e) => {
    const host = e.hostname || 'unknown';
    (grouped[host] = grouped[host] || { entries: [], address: e.address || '' }).entries.push(e);
  });
  return grouped;
}

/**
 * Create datasets for each host
 * @param {Object} dataByHost - Grouped data by host
 * @param {Function} valueMapper - Function to map entry to {x, y} point
 * @param {number} pointRadius - Chart point radius
 * @returns {Array} - Array of Chart.js datasets
 */
function createHostDatasets(dataByHost, valueMapper, pointRadius = 0) {
  return Object.keys(dataByHost)
    .sort()
    .map((hostname, i) => {
      const color = getChartColor(i);
      return createDataset(hostname, dataByHost[hostname].entries.map(valueMapper), color, {
        fill: false,
        pointRadius,
      });
    });
}

// =============================================================================
// Chart Update Functions
// =============================================================================

async function updateRawPingLatencyChart() {
  const hours = parseInt(document.getElementById('rawTimeRange')?.value || '12');
  const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw?hours=${hours}`);

  if (!data.success || !data.data?.length) {
    showElement('noDataMessage');
    return;
  }
  hideElement('noDataMessage');

  const dataByHost = groupByHost(data.data);
  const datasets = createHostDatasets(dataByHost, (e) => ({ x: e.timestamp * 1000, y: e.latency }), 2);
  const opts = buildChartOptions(hours, {
    yLabel: 'Latency (ms)',
    beginAtZero: true,
    tooltipLabel: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms',
  });
  updateOrCreateChart(state.charts, 'rawPing', 'rawPingLatencyChart', 'line', datasets, opts);
}

async function updateAllCharts() {
  const hours = parseInt(document.getElementById('timeRange')?.value || '12');
  const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup?hours=${hours}`);

  const sections = ['statsBarSection', 'rollupChartsSection', 'perHostStatsSection'];
  if (!data.success || !data.data?.length) {
    sections.forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
    return;
  }
  sections.forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'block';
  });

  // Update tier badges
  if (data.tier_info) {
    const latencyBadge = document.getElementById('latencyTierBadge');
    const successBadge = document.getElementById('successTierBadge');
    if (latencyBadge) latencyBadge.textContent = data.tier_info.label;
    if (successBadge) successBadge.textContent = data.tier_info.label;
  }

  const dataByHost = groupByHost(data.data);
  const hostnames = Object.keys(dataByHost).sort();

  // Calculate per-host stats
  const hostStats = hostnames.map((hostname) => {
    const entries = dataByHost[hostname].entries;
    const latencies = entries.map((e) => e.avg_latency).filter((v) => v !== null);
    const success = entries.reduce((s, e) => s + (e.success_count || 0), 0);
    const failure = entries.reduce((s, e) => s + (e.failure_count || 0), 0);
    const total = success + failure;
    return {
      hostname,
      address: dataByHost[hostname].address,
      avgLatency: latencies.length ? latencies.reduce((a, b) => a + b) / latencies.length : null,
      minLatency: latencies.length ? Math.min(...latencies) : null,
      maxLatency: latencies.length ? Math.max(...latencies) : null,
      successRate: total ? (success / total) * 100 : 0,
    };
  });

  // Render per-host stat cards
  const perHostStatsEl = document.getElementById('perHostStats');
  if (perHostStatsEl) {
    perHostStatsEl.innerHTML = hostStats
      .map((s) => {
        const lc =
          s.avgLatency === null ? '' : s.avgLatency > 100 ? 'danger' : s.avgLatency > 50 ? 'warning' : 'success';
        const sc = s.successRate >= 99 ? 'success' : s.successRate >= 90 ? 'warning' : 'danger';
        const bc = { danger: '#dc3545', warning: '#ffc107', success: '#28a745' }[lc] || '#6c757d';
        return `<div class="hostStatCard" style="border-left-color:${bc}">
            <div class="hostname">${escapeHtml(s.hostname)}</div>
            <div class="address">${escapeHtml(s.address)}</div>
            <div class="stats-row">
                <div class="stat"><div class="stat-label">Avg Latency</div><div class="stat-value ${lc}">${s.avgLatency !== null ? s.avgLatency.toFixed(1) + ' ms' : '--'}</div></div>
                <div class="stat"><div class="stat-label">Min/Max</div><div class="stat-value">${s.minLatency !== null ? s.minLatency.toFixed(1) : '--'} / ${s.maxLatency !== null ? s.maxLatency.toFixed(1) : '--'}</div></div>
                <div class="stat"><div class="stat-label">Success Rate</div><div class="stat-value ${sc}">${s.successRate.toFixed(1)}%</div></div>
            </div></div>`;
      })
      .join('');
  }

  // Update summary stats
  const allLat = hostStats.filter((s) => s.avgLatency !== null).map((s) => s.avgLatency);
  const setTextContent = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };

  setTextContent('hostsCount', hostnames.length.toString());
  setTextContent(
    'overallAvgLatency',
    allLat.length ? (allLat.reduce((a, b) => a + b) / allLat.length).toFixed(2) + ' ms' : '-- ms'
  );
  setTextContent('bestLatency', allLat.length ? Math.min(...allLat).toFixed(2) + ' ms' : '-- ms');
  setTextContent('worstLatency', allLat.length ? Math.max(...allLat).toFixed(2) + ' ms' : '-- ms');
  setTextContent('dataPoints', data.data.length.toLocaleString());

  // Update charts
  const latencyOpts = buildChartOptions(hours, {
    yLabel: 'Latency (ms)',
    beginAtZero: true,
    yTickFormatter: (v) => v.toFixed(1) + ' ms',
    tooltipLabel: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms',
  });
  updateOrCreateChart(
    state.charts,
    'latency',
    'latencyChart',
    'line',
    createHostDatasets(dataByHost, (e) => ({ x: e.timestamp * 1000, y: e.avg_latency })),
    latencyOpts
  );

  const successOpts = buildChartOptions(hours, {
    yLabel: 'Success Rate (%)',
    yMax: 100,
    yTickFormatter: (v) => v + '%',
    tooltipLabel: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + '%',
  });
  const successDatasets = createHostDatasets(dataByHost, (e) => {
    const t = (e.success_count || 0) + (e.failure_count || 0);
    return { x: e.timestamp * 1000, y: t ? (e.success_count / t) * 100 : 100 };
  });
  updateOrCreateChart(state.charts, 'success', 'successChart', 'line', successDatasets, successOpts);
}

// =============================================================================
// Main Load Function
// =============================================================================

async function loadAllMetrics() {
  if (state.isRefreshing) return;
  state.isRefreshing = true;

  const btn = document.querySelector('.refreshButton i');
  if (btn) btn.style.animation = 'spin 1s linear infinite';

  try {
    await Promise.all([updateRawPingLatencyChart(), updateAllCharts()]);
    hideElement('loadingIndicator');
    showElement('metricsContent');
    updateLastUpdateTime();
  } catch (e) {
    console.error('Error loading metrics:', e);
  } finally {
    state.isRefreshing = false;
    if (btn) btn.style.animation = '';
  }
}

// =============================================================================
// Public Interface
// =============================================================================

export const remotePing = {
  pageId: 'remotePingUI',

  /**
   * Initialize the remote ping page
   * @param {Object} config - Configuration from PHP
   */
  init(config = {}) {
    state.config = config;

    // Load metrics on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', loadAllMetrics);
    } else {
      loadAllMetrics();
    }

    // Auto-refresh every 60 seconds
    state.refreshInterval = setInterval(() => {
      if (!state.isRefreshing) loadAllMetrics();
    }, 60000);
  },

  /**
   * Cleanup and reset state
   */
  destroy() {
    if (state.refreshInterval) {
      clearInterval(state.refreshInterval);
    }
    Object.values(state.charts).forEach((c) => c?.destroy?.());
    state = {
      charts: {},
      isRefreshing: false,
      refreshInterval: null,
      config: {},
    };
  },

  // Public methods for onclick handlers
  refresh: loadAllMetrics,
  updateAllCharts,
  updateRawPingLatencyChart,
};
