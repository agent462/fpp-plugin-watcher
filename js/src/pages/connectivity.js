/**
 * Connectivity Metrics Page Module
 *
 * Network connectivity monitoring with ping charts and network reset handling.
 * Extracted from connectivityUI.php
 */

import {
  escapeHtml,
  showElement,
  hideElement,
  formatLatency,
  updateLastUpdateTime,
} from '../core/utils.js';
import {
  buildChartOptions,
  createDataset,
  mapChartData,
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
// Network Reset State Functions
// =============================================================================

async function checkNetworkResetState() {
  try {
    const data = await fetchJson('/api/plugin/fpp-plugin-watcher/connectivity/state');
    const banner = document.getElementById('networkResetBanner');
    const details = document.getElementById('resetDetails');

    if (!banner || !details) return;

    if (data.success && data.hasResetAdapter) {
      const resetTime = data.resetTime || 'Unknown time';
      const adapter = data.adapter || 'Unknown adapter';
      const reason = data.reason || 'Max failures reached';

      details.innerHTML = `The network adapter <strong>${escapeHtml(adapter)}</strong> was reset on <strong>${escapeHtml(resetTime)}</strong>.<br>Reason: ${escapeHtml(reason)}`;
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }
  } catch (e) {
    console.error('Error checking network reset state:', e);
  }
}

async function clearNetworkResetState() {
  const btn = event.target.closest('button');
  if (!btn) return;

  const originalContent = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/connectivity/state/clear', {
      method: 'POST',
    });
    const data = await response.json();

    if (data.success) {
      const banner = document.getElementById('networkResetBanner');
      if (banner) banner.style.display = 'none';
      loadAllMetrics();
    } else {
      alert('Failed to clear reset state: ' + (data.error || 'Unknown error'));
      btn.disabled = false;
      btn.innerHTML = originalContent;
    }
  } catch (e) {
    console.error('Error clearing reset state:', e);
    alert('Failed to clear reset state: ' + e.message);
    btn.disabled = false;
    btn.innerHTML = originalContent;
  }
}

// =============================================================================
// Chart Update Functions
// =============================================================================

async function updateAllCharts() {
  const hours = parseInt(document.getElementById('timeRange')?.value || '12');
  const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/ping/rollup?hours=${hours}`);

  const noDataEl = document.getElementById('noDataMessage');
  const statsSection = document.getElementById('statsBarSection');
  const chartsSection = document.getElementById('rollupChartsSection');

  if (!data.success || !data.data?.length) {
    if (statsSection) statsSection.style.display = 'none';
    if (chartsSection) chartsSection.style.display = 'none';
    if (noDataEl) noDataEl.style.display = 'block';
    return false;
  }

  if (noDataEl) noDataEl.style.display = 'none';
  if (statsSection) statsSection.style.display = 'block';
  if (chartsSection) chartsSection.style.display = 'block';

  // Update tier badges
  if (data.tier_info) {
    ['latencyTierBadge', 'rangeTierBadge', 'sampleTierBadge'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.textContent = data.tier_info.label;
    });
  }

  // Calculate and display stats
  const latencies = data.data.map((d) => d.avg_latency).filter((v) => v !== null);
  const minLats = data.data.map((d) => d.min_latency).filter((v) => v !== null);
  const maxLats = data.data.map((d) => d.max_latency).filter((v) => v !== null);

  const setTextContent = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };

  setTextContent('currentLatency', formatLatency(latencies.at(-1)));
  setTextContent(
    'avgLatency',
    formatLatency(latencies.reduce((a, b) => a + b, 0) / latencies.length)
  );
  setTextContent('minLatency', formatLatency(Math.min(...minLats)));
  setTextContent('maxLatency', formatLatency(Math.max(...maxLats)));
  setTextContent('dataPoints', data.data.length.toLocaleString());

  // Build chart options
  const latencyOpts = buildChartOptions(hours, {
    yLabel: 'Latency (ms)',
    beginAtZero: true,
    yTickFormatter: (v) => v.toFixed(1) + ' ms',
    tooltipLabel: (ctx) => 'Latency: ' + ctx.parsed.y.toFixed(2) + ' ms',
  });

  const sampleOpts = buildChartOptions(hours, {
    yLabel: 'Number of Samples',
    beginAtZero: true,
    yTickFormatter: (v) => v.toFixed(0),
    tooltipLabel: (ctx) => 'Samples: ' + ctx.parsed.y,
  });

  // Latency chart
  updateOrCreateChart(
    state.charts,
    'latency',
    'latencyChart',
    'line',
    [createDataset('Average Latency (ms)', mapChartData(data, 'avg_latency'), 'purple')],
    latencyOpts
  );

  // Range chart (min/avg/max)
  updateOrCreateChart(
    state.charts,
    'range',
    'rangeChart',
    'line',
    [
      createDataset('Min Latency', mapChartData(data, 'min_latency'), 'green', { fill: false }),
      createDataset('Avg Latency', mapChartData(data, 'avg_latency'), 'purple', { fill: false }),
      createDataset('Max Latency', mapChartData(data, 'max_latency'), 'red', { fill: false }),
    ],
    latencyOpts
  );

  // Sample count chart (bar)
  const barDataset = createDataset('Sample Count', mapChartData(data, 'sample_count'), 'blue');
  barDataset.borderWidth = 1;
  updateOrCreateChart(state.charts, 'sample', 'sampleChart', 'bar', [barDataset], sampleOpts);

  return true;
}

async function updateRawPingLatencyChart() {
  const hours = parseInt(document.getElementById('rawTimeRange')?.value || '12');
  const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/ping/raw?hours=${hours}`);

  if (!data.success || !data.data?.length) return;

  // Group by host and create datasets
  const byHost = {};
  data.data.forEach((e) => {
    (byHost[e.host] = byHost[e.host] || []).push({ x: e.timestamp * 1000, y: e.latency });
  });

  const datasets = Object.keys(byHost).map((host, i) => {
    const color = getChartColor(i);
    return createDataset(host, byHost[host], color, { pointRadius: 2 });
  });

  const opts = buildChartOptions(hours, {
    yLabel: 'Latency (ms)',
    beginAtZero: true,
    tooltipLabel: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms',
  });

  updateOrCreateChart(state.charts, 'rawPing', 'rawPingLatencyChart', 'line', datasets, opts);
}

// =============================================================================
// Main Load Function
// =============================================================================

async function loadAllMetrics() {
  if (state.isRefreshing) return;
  state.isRefreshing = true;

  const refreshBtn = document.querySelector('.refreshButton i');
  if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

  try {
    await checkNetworkResetState();
    await Promise.all([updateAllCharts(), updateRawPingLatencyChart()]);
    hideElement('loadingIndicator');
    showElement('metricsContent');
    updateLastUpdateTime();
  } catch (error) {
    console.error('Error loading metrics:', error);
  } finally {
    state.isRefreshing = false;
    if (refreshBtn) refreshBtn.style.animation = '';
  }
}

// =============================================================================
// Public Interface
// =============================================================================

export const connectivity = {
  pageId: 'connectivityUI',

  /**
   * Initialize the connectivity page
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

    // Auto-refresh every 30 seconds
    state.refreshInterval = setInterval(() => {
      if (!state.isRefreshing) loadAllMetrics();
    }, 30000);
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
  clearNetworkResetState,
};
