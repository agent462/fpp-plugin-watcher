/**
 * Remote Metrics Page Module
 *
 * All remote systems metrics dashboard showing CPU, memory, disk, temperature,
 * wireless, ping, and eFuse data from multi-sync hosts.
 * Extracted from remoteMetricsUI.php
 */

import {
  escapeHtml,
  showElement,
  hideElement,
  toFahrenheit,
  getTempUnit,
  formatThermalZoneName,
  updateLastUpdateTime,
} from '../core/utils.js';
import { CHART_COLORS, createDataset, updateOrCreateChart } from '../core/charts.js';
import { fetchJson, loadTemperaturePreference } from '../core/api.js';

// =============================================================================
// Module State
// =============================================================================

let state = {
  charts: {},
  isRefreshing: false,
  useFahrenheit: false,
  refreshInterval: null,
  systemMetrics: {},
  config: {},
};

// =============================================================================
// Helper Functions
// =============================================================================

const getSelectedHours = () => document.getElementById('timeRange')?.value || '12';
const convertTemp = (c) => (state.useFahrenheit ? toFahrenheit(c) : c);

const metricConfig = {
  cpu: {
    getValue: (m) => m.cpu?.current,
    getClass: (v) => (v > 80 ? 'danger' : v > 60 ? 'warning' : ''),
    format: (v) => v.toFixed(1) + '%',
    chartKey: 'cpu_usage',
  },
  memory: {
    getValue: (m) => m.memory?.current,
    getClass: (v) => (v < 100 ? 'danger' : v < 250 ? 'warning' : ''),
    format: (v) => v.toFixed(0) + ' MB',
    chartKey: 'free_mb',
  },
  bufferCache: {
    getValue: (m) => m.bufferCache?.current,
    getClass: () => '',
    format: (v) => v.toFixed(0) + ' MB',
    chartKey: 'buffer_cache_mb',
  },
  disk: {
    getValue: (m) => m.disk?.current,
    getClass: (v) => (v < 1 ? 'danger' : v < 2 ? 'warning' : ''),
    format: (v) => v.toFixed(1) + ' GB',
    chartKey: 'free_gb',
  },
  load: {
    getValue: (m) => m.load?.shortterm,
    getClass: () => '',
    format: (v) => v.toFixed(2),
    chartKey: null,
  },
  temp: {
    getValue: (m) => m.temperature?.current,
    getClass: (v) => (v > 80 ? 'danger' : v > 70 ? 'warning' : ''),
    format: (v) => convertTemp(v).toFixed(1) + getTempUnit(state.useFahrenheit),
    chartKey: 'temperature',
  },
  wireless: {
    getValue: (m) => m.wireless?.signal,
    getClass: (v) => (v < -80 ? 'danger' : v < -70 ? 'warning' : ''),
    format: (v) => v.toFixed(0) + ' dBm',
    chartKey: 'signal_dbm',
  },
  ping: {
    getValue: (m) => m.ping?.current,
    getClass: (v) => (v > 100 ? 'danger' : v > 50 ? 'warning' : ''),
    format: (v) => v.toFixed(1) + ' ms',
    chartKey: 'avg_latency',
  },
  efuse: {
    getValue: (m) => m.efuse?.current,
    getClass: () => '',
    format: (v) => (v / 1000).toFixed(2) + ' A',
    chartKey: 'total_ma',
  },
};

function getMetricDisplay(metrics, key) {
  const cfg = metricConfig[key];
  const val = cfg.getValue(metrics);
  return val == null
    ? { value: '--', class: '' }
    : { value: cfg.format(val), class: cfg.getClass(val) };
}

function processMetricData(data, valueKey) {
  if (!data?.success || !data.data?.length) return null;
  const valid = data.data.filter((d) => d[valueKey] !== null);
  if (!valid.length) return null;
  return {
    current: valid.at(-1)[valueKey],
    average: valid.reduce((a, b) => a + b[valueKey], 0) / valid.length,
    data: data.data,
  };
}

// =============================================================================
// Fetch System Metrics
// =============================================================================

async function fetchSystemMetrics(system) {
  const { address, hostname, model, type, version } = system;
  const hours = getSelectedHours();
  const result = {
    hostname,
    address,
    model: model || type || 'Unknown',
    version: version || '',
    watcherVersion: null,
    online: false,
    noWatcher: false,
    error: null,
    cpu: null,
    memory: null,
    bufferCache: null,
    disk: null,
    load: null,
    temperature: null,
    wireless: null,
    ping: null,
    efuse: null,
  };

  try {
    const [allData, versionData, efuseData] = await Promise.all([
      fetchJson(
        `/api/plugin/fpp-plugin-watcher/remote/metrics/all?host=${encodeURIComponent(address)}&hours=${hours}`,
        12000
      ),
      fetchJson(`/api/plugin/fpp-plugin-watcher/remote/version?host=${encodeURIComponent(address)}`, 5000).catch(
        () => null
      ),
      fetchJson(
        `/api/plugin/fpp-plugin-watcher/remote/efuse/heatmap?host=${encodeURIComponent(address)}&hours=${hours}`,
        8000
      ).catch(() => null),
    ]);

    if (!allData.success) {
      result.error = 'API returned unsuccessful response';
      return result;
    }
    result.online = true;
    if (versionData?.version) result.watcherVersion = versionData.version;

    result.cpu = processMetricData(allData.cpu, 'cpu_usage');
    result.memory = processMetricData(allData.memory, 'free_mb');
    result.bufferCache = processMetricData(allData.memory, 'buffer_cache_mb');
    result.disk = processMetricData(allData.disk, 'free_gb');

    if (allData.load?.success && allData.load.data?.length) {
      const valid = allData.load.data.filter((d) => d.shortterm !== null);
      if (valid.length) {
        const latest = valid.at(-1);
        result.load = {
          shortterm: latest.shortterm,
          midterm: latest.midterm,
          longterm: latest.longterm,
        };
      }
    }

    // Temperature
    const tempData = allData.thermal;
    if (tempData?.success && tempData.data?.length && tempData.zones?.length) {
      const zones = tempData.zones.filter((z) => z.startsWith('thermal_zone'));
      for (const zone of zones.length ? zones : tempData.zones) {
        const valid = tempData.data.filter((d) => d[zone] !== null);
        if (valid.length) {
          const friendlyName = tempData.zone_names?.[zone] || zone;
          result.temperature = {
            current: valid.at(-1)[zone],
            average: valid.reduce((a, b) => a + b[zone], 0) / valid.length,
            data: tempData.data.map((d) => ({ timestamp: d.timestamp, temperature: d[zone] })),
            zone,
            friendlyName,
          };
          break;
        }
      }
    }

    // Wireless
    const wData = allData.wireless;
    if (wData?.success && wData.data?.length && wData.interfaces?.length) {
      const iface = wData.interfaces[0];
      const powerKey = `${iface}_signal_power`;
      const valid = wData.data.filter((d) => d[powerKey] !== null);
      if (valid.length) {
        const latest = valid.at(-1);
        result.wireless = {
          signal: latest[powerKey],
          noise: latest[`${iface}_signal_noise`],
          quality: latest[`${iface}_signal_quality`],
          data: wData.data.map((d) => ({ timestamp: d.timestamp, signal_dbm: d[powerKey] })),
          interface: iface,
        };
      }
    }

    // Ping
    const pingData = allData.ping;
    if (pingData?.success && pingData.data?.length) {
      const valid = pingData.data.filter((d) => d.avg_latency !== null);
      if (valid.length) {
        result.ping = {
          current: valid.at(-1).avg_latency,
          average: valid.reduce((a, b) => a + b.avg_latency, 0) / valid.length,
          min: Math.min(...valid.map((d) => d.min_latency)),
          max: Math.max(...valid.map((d) => d.max_latency)),
          data: pingData.data,
          tier: pingData.tier_info?.label || '5-min averages',
        };
      }
    }

    // eFuse total amperage
    if (efuseData?.success && efuseData.totalHistory?.length) {
      const valid = efuseData.totalHistory.filter((d) => d.value !== null && d.value > 0);
      if (valid.length) {
        const latest = valid.at(-1);
        const values = valid.map((d) => d.value);
        result.efuse = {
          current: latest.value,
          average: values.reduce((a, b) => a + b, 0) / values.length,
          peak: Math.max(...values),
          portCount: efuseData.portCount || 0,
          hardware: efuseData.hardware,
          data: efuseData.totalHistory.map((d) => ({
            timestamp: d.timestamp,
            total_ma: d.value,
            min: d.min,
            max: d.max,
          })),
          tier: efuseData.tier_info?.label || '1-minute averages',
        };
      }
    }
  } catch (e) {
    // Watcher API failed - check if host is still online via basic FPP API
    try {
      const fppStatusResponse = await fetchJson(
        `/api/plugin/fpp-plugin-watcher/remote/fppd/status?host=${encodeURIComponent(address)}`,
        8000
      );
      if (fppStatusResponse?.success && fppStatusResponse.data) {
        result.online = true;
        result.noWatcher = true;
      }
    } catch {
      result.error = e.message || 'Failed to connect';
    }
  }
  return result;
}

// =============================================================================
// Card Rendering
// =============================================================================

function renderLoadingCard(system, index) {
  return `<div class="systemCard" data-system="${index}">
        <div class="systemHeader">
            <div><div class="systemName">${escapeHtml(system.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(system.address)}" target="_blank" style="color:#007bff">${escapeHtml(system.address)}</a> | ${escapeHtml(system.model || system.type || 'Unknown')} | FPP ${escapeHtml(system.version || '')}</div></div>
            <div class="systemStatus" style="background:#e9ecef;color:#6c757d">Loading...</div>
        </div>
        <div class="noDataMessage"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Fetching metrics...</p></div>
    </div>`;
}

function renderSystemCard(m, index) {
  if (!m.online) {
    return `<div class="systemCard" data-system="${index}">
            <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}</div></div>
            <div class="systemStatus offline">Offline</div></div>
            <div class="noDataMessage"><i class="fas fa-exclamation-triangle"></i><p>Unable to fetch metrics: ${escapeHtml(m.error || 'Connection failed')}</p><p style="font-size:0.875rem">Ensure the Watcher plugin is installed and collectd is enabled.</p></div>
        </div>`;
  }

  if (m.noWatcher) {
    return `<div class="systemCard" data-system="${index}">
            <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}</div></div>
            <div class="systemStatus online">Online</div></div>
            <div class="noDataMessage"><i class="fas fa-info-circle"></i><p>Watcher plugin not installed or metrics not available</p><p style="font-size:0.875rem">Install the Watcher plugin on this system to view metrics.</p></div>
        </div>`;
  }

  const watcherInfo = m.watcherVersion ? ` | Watcher ${escapeHtml(m.watcherVersion)}` : '';
  const metricLabels = {
    cpu: 'CPU Usage',
    memory: 'Free Memory',
    bufferCache: 'Buffer Cache',
    disk: 'Free Disk',
    load: 'Load (1min)',
  };
  const metrics = ['cpu', 'memory', 'bufferCache', 'disk', 'load']
    .filter((k) => k !== 'bufferCache' || m.bufferCache)
    .map((k) => {
      const d = getMetricDisplay(m, k);
      const tooltip =
        k === 'bufferCache'
          ? ' title="Memory used by Linux to cache frequently accessed files. This memory is automatically released when needed - high usage is good!"'
          : '';
      return `<div class="metricItem" data-metric="${k}"${tooltip}><div class="metricLabel">${metricLabels[k]}${k === 'bufferCache' ? ' <i class="fas fa-info-circle" style="font-size:0.65rem;color:#6c757d;cursor:help;"></i>' : ''}</div><div class="metricValue ${d.class}">${d.value}</div></div>`;
    })
    .join('');

  const optMetrics = [
    ['temp', 'Temperature', m.temperature],
    ['wireless', 'WiFi Signal', m.wireless],
    ['ping', 'Ping Latency', m.ping],
    ['efuse', 'Total Current', m.efuse],
  ]
    .filter(([, , v]) => v)
    .map(([k, l]) => {
      const d = getMetricDisplay(m, k);
      return `<div class="metricItem" data-metric="${k}"><div class="metricLabel">${l}</div><div class="metricValue ${d.class}">${d.value}</div></div>`;
    })
    .join('');

  const chartWrappers = [
    `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-microchip"></i> CPU Usage</div><canvas id="cpuChart-${index}" height="150"></canvas></div>`,
    `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-memory"></i> Free Memory</div><canvas id="memoryChart-${index}" height="150"></canvas></div>`,
    m.temperature
      ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-thermometer-half"></i> Temperature</div><canvas id="tempChart-${index}" height="150"></canvas></div>`
      : '',
    m.wireless
      ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-wifi"></i> WiFi Signal</div><canvas id="wirelessChart-${index}" height="150"></canvas></div>`
      : '',
    m.ping
      ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-network-wired"></i> Ping Latency</div><canvas id="pingChart-${index}" height="150"></canvas></div>`
      : '',
    m.efuse
      ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-bolt"></i> Total Current</div><canvas id="efuseChart-${index}" height="150"></canvas></div>`
      : '',
  ].join('');

  return `<div class="systemCard" data-system="${index}">
        <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
        <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}${watcherInfo}</div></div>
        <div class="systemStatus online">Online</div></div>
        <div class="metricsGrid">${metrics}${optMetrics}</div>
        <div class="chartsContainer">${chartWrappers}</div>
    </div>`;
}

// =============================================================================
// Mini Chart Rendering
// =============================================================================

function renderMiniChart(canvasId, data, label, colorKey, valueKey) {
  const isTemp = valueKey === 'temperature';
  const chartData = data
    .map((e) => ({ x: e.timestamp * 1000, y: isTemp ? convertTemp(e[valueKey]) : e[valueKey] }))
    .filter((d) => d.y !== null);
  const color = CHART_COLORS[colorKey] || CHART_COLORS.purple;
  const formatters = {
    cpu_usage: (v) => v.toFixed(1) + '%',
    free_mb: (v) => v.toFixed(0) + ' MB',
    temperature: (v) => v.toFixed(1) + getTempUnit(state.useFahrenheit),
    signal_dbm: (v) => v.toFixed(0) + ' dBm',
    avg_latency: (v) => v.toFixed(1) + ' ms',
    total_ma: (v) => (v / 1000).toFixed(2) + ' A',
  };
  const formatValue = formatters[valueKey] || ((v) => v.toFixed(2));

  const datasets = [createDataset(label, chartData, color)];
  const options = {
    responsive: true,
    maintainAspectRatio: true,
    animation: false,
    interaction: { mode: 'nearest', axis: 'x', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          title: (ctx) => new Date(ctx[0].parsed.x).toLocaleString(),
          label: (ctx) => label + ': ' + formatValue(ctx.parsed.y),
        },
      },
    },
    scales: {
      x: {
        type: 'time',
        display: true,
        grid: { display: false },
        ticks: { maxTicksLimit: 6, font: { size: 10 }, color: '#6c757d' },
        time: { displayFormats: { hour: 'h:mm a', day: 'MMM d' } },
      },
      y: {
        beginAtZero: valueKey === 'cpu_usage',
        display: true,
        grid: { display: false },
      },
    },
  };

  updateOrCreateChart(state.charts, canvasId, canvasId, 'line', datasets, options);
}

// =============================================================================
// Summary Cards
// =============================================================================

function updateSummaryCards() {
  const systems = Object.values(state.systemMetrics);
  const online = systems.filter((s) => s.online);

  const setTextContent = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };

  setTextContent('totalSystems', systems.length);
  setTextContent('onlineSystems', `${online.length} online`);

  if (online.length) {
    const avg = (arr) => (arr.length ? arr.reduce((a, b) => a + b) / arr.length : null);
    const cpuAvg = avg(online.filter((s) => s.cpu).map((s) => s.cpu.current));
    const memAvg = avg(online.filter((s) => s.memory).map((s) => s.memory.current));
    const diskAvg = avg(online.filter((s) => s.disk).map((s) => s.disk.current));

    if (cpuAvg !== null) setTextContent('avgCpu', cpuAvg.toFixed(1) + '%');
    if (memAvg !== null) setTextContent('avgMemory', memAvg.toFixed(0) + ' MB');
    if (diskAvg !== null) setTextContent('avgDisk', diskAvg.toFixed(1) + ' GB');

    // eFuse summary
    const efuseSystems = online.filter((s) => s.efuse);
    const efuseCard = document.getElementById('efuseSummaryCard');
    if (efuseCard) {
      if (efuseSystems.length > 0) {
        const totalCurrent = efuseSystems.reduce((sum, s) => sum + (s.efuse.current || 0), 0);
        efuseCard.style.display = '';
        setTextContent('totalEfuse', (totalCurrent / 1000).toFixed(2) + ' A');
        setTextContent(
          'efuseSystems',
          `${efuseSystems.length} system${efuseSystems.length > 1 ? 's' : ''} with eFuse`
        );
      } else {
        efuseCard.style.display = 'none';
      }
    }
  }
}

function destroyAllCharts() {
  Object.keys(state.charts).forEach((k) => {
    if (state.charts[k]) {
      state.charts[k].destroy();
      delete state.charts[k];
    }
  });
}

// =============================================================================
// Async Pool for Parallel Fetching
// =============================================================================

async function asyncPool(concurrency, items, fn) {
  const results = [];
  const executing = new Set();
  for (const [i, item] of items.entries()) {
    const p = Promise.resolve().then(() => fn(item, i));
    results.push(p);
    executing.add(p);
    p.finally(() => executing.delete(p));
    if (executing.size >= concurrency) await Promise.race(executing);
  }
  return Promise.all(results);
}

// =============================================================================
// Main Refresh Function
// =============================================================================

async function refreshAllSystems() {
  if (state.isRefreshing) return;
  state.isRefreshing = true;

  const btn = document.querySelector('.refreshButton i');
  if (btn) btn.style.animation = 'spin 1s linear infinite';

  state.useFahrenheit = await loadTemperaturePreference();

  const container = document.getElementById('systemsContainer');
  const systems = window.remoteSystems || [];

  if (!systems.length) {
    destroyAllCharts();
    if (container) {
      container.innerHTML =
        '<div class="noDataMessage"><p>No remote systems found in multi-sync configuration.</p></div>';
    }
    hideElement('loadingIndicator');
    showElement('metricsContent');
    state.isRefreshing = false;
    return;
  }

  const isInitialLoad = !Object.keys(state.systemMetrics).length;
  if (isInitialLoad && container) {
    destroyAllCharts();
    state.systemMetrics = {};
    container.innerHTML = systems.map((s, i) => renderLoadingCard(s, i)).join('');
  }

  hideElement('loadingIndicator');
  showElement('metricsContent');

  await asyncPool(6, systems, async (system, index) => {
    try {
      const metrics = await fetchSystemMetrics(system);
      state.systemMetrics[index] = metrics;
      const card = container?.querySelector(`[data-system="${index}"]`);
      if (card) {
        // Destroy existing charts before replacing HTML
        ['cpu', 'memory', 'temp', 'wireless', 'ping', 'efuse'].forEach((key) => {
          const chartKey = `${key}Chart-${index}`;
          if (state.charts[chartKey]) {
            state.charts[chartKey].destroy();
            delete state.charts[chartKey];
          }
        });
        card.outerHTML = renderSystemCard(metrics, index);
        if (metrics.online) {
          [
            ['cpu', 'red', 'cpu_usage'],
            ['memory', 'purple', 'free_mb'],
            ['temp', 'orange', 'temperature'],
            ['wireless', 'teal', 'signal_dbm'],
            ['ping', 'indigo', 'avg_latency'],
            ['efuse', 'yellow', 'total_ma'],
          ].forEach(([key, color, field]) => {
            const data =
              key === 'cpu'
                ? metrics.cpu?.data
                : key === 'memory'
                  ? metrics.memory?.data
                  : key === 'temp'
                    ? metrics.temperature?.data
                    : key === 'wireless'
                      ? metrics.wireless?.data
                      : key === 'ping'
                        ? metrics.ping?.data
                        : metrics.efuse?.data;
            const label =
              key === 'cpu'
                ? 'CPU %'
                : key === 'memory'
                  ? 'Memory MB'
                  : key === 'temp'
                    ? formatThermalZoneName(metrics.temperature?.friendlyName || 'Temp') +
                      ' ' +
                      getTempUnit(state.useFahrenheit)
                    : key === 'wireless'
                      ? 'Signal dBm'
                      : key === 'ping'
                        ? 'Latency ms'
                        : 'Amps';
            if (data)
              renderMiniChart(
                `${key === 'temp' ? 'temp' : key}Chart-${index}`,
                data,
                label,
                color,
                field
              );
          });
        }
      }
      updateSummaryCards();
    } catch (e) {
      console.error(`Failed to fetch metrics for ${system.hostname}:`, e);
    }
  });

  updateLastUpdateTime();
  if (btn) btn.style.animation = '';
  state.isRefreshing = false;
}

// =============================================================================
// Public Interface
// =============================================================================

export const remoteMetrics = {
  pageId: 'remoteMetricsUI',

  /**
   * Initialize the remote metrics page
   * @param {Object} config - Configuration from PHP
   */
  init(config = {}) {
    state.config = config;

    // Load on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', refreshAllSystems);
    } else {
      refreshAllSystems();
    }

    // Auto-refresh every 60 seconds
    state.refreshInterval = setInterval(() => {
      if (!state.isRefreshing) refreshAllSystems();
    }, 60000);

    // Cleanup on page unload
    window.addEventListener('beforeunload', destroyAllCharts);
  },

  /**
   * Cleanup and reset state
   */
  destroy() {
    if (state.refreshInterval) {
      clearInterval(state.refreshInterval);
    }
    destroyAllCharts();
    state = {
      charts: {},
      isRefreshing: false,
      useFahrenheit: false,
      refreshInterval: null,
      systemMetrics: {},
      config: {},
    };
  },

  // Public methods for onclick handlers
  refresh: refreshAllSystems,
};
