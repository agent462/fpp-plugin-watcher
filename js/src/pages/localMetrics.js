/**
 * Local Metrics Page Module
 *
 * System metrics dashboard showing CPU, memory, disk, network, thermal, and wireless stats.
 * Extracted from localMetricsUI.php
 */

import {
  escapeHtml,
  showElement,
  hideElement,
  setLoading,
  formatBytes,
  toFahrenheit,
  formatTemp,
  getTempStatus,
  getTempUnit,
  formatThermalZoneName,
  updateLastUpdateTime,
} from '../core/utils.js';
import {
  CHART_COLORS,
  buildChartOptions,
  createDataset,
  mapChartData,
  updateOrCreateChart,
} from '../core/charts.js';
import { fetchJson, loadTemperaturePreference } from '../core/api.js';

// =============================================================================
// Module State
// =============================================================================

let state = {
  charts: {},
  isRefreshing: false,
  useFahrenheit: false,
  refreshInterval: null,
  config: {},
};

// =============================================================================
// Helpers
// =============================================================================

const getSelectedHours = () => document.getElementById('timeRange')?.value || '12';
const getVoltageHours = () => document.getElementById('voltageTimeRange')?.value || '12';
const getDefaultAdapter = () => state.config?.defaultAdapter || 'default';
const getSelectedInterface = () =>
  document.getElementById('interfaceSelect')?.value || getDefaultAdapter();
const getSelectedWirelessInterface = () =>
  document.getElementById('wirelessInterfaceSelect')?.value || 'wlan0';

// =============================================================================
// System Status Functions
// =============================================================================

async function loadSystemStatus() {
  try {
    state.useFahrenheit = await loadTemperaturePreference();
    const status = await fetchJson('/api/system/status');
    updateTemperatureStatus(status);
    updateDiskStatus(status);
  } catch (e) {
    console.error('Error loading system status:', e);
  }
}

function updateTemperatureStatus(status) {
  const container = document.getElementById('temperatureStatusBar');
  if (!container) return;

  const sensors = status?.sensors?.filter((s) => s.valueType === 'Temperature') || [];
  if (!sensors.length) {
    container.style.display = 'none';
    return;
  }

  container.innerHTML = sensors
    .map((sensor, i) => {
      const temp = parseFloat(sensor.value) || 0;
      const st = getTempStatus(temp);
      const pct = Math.min(temp, 100);
      const label = sensor.label.replace(':', '').trim();
      const display = formatTemp(temp, state.useFahrenheit);
      return `<div${i < sensors.length - 1 ? ' style="margin-bottom: 1.5rem;"' : ''}>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-thermometer-half" style="color: ${st.color};"></i><strong>${escapeHtml(label)}</strong>
                </div>
                <span style="font-size: 1.5rem; font-weight: bold; color: ${st.color};">${display}</span>
            </div>
            <div class="progressBar" style="background-color: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
                <div style="width: ${pct}%; height: 100%; background: ${st.color}; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.875rem; font-weight: 500;">${display}</div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.75rem; color: #6c757d;">
                <span>${formatTemp(0, state.useFahrenheit)}</span><span>Status: ${st.icon} ${st.text}</span><span>${formatTemp(100, state.useFahrenheit)}</span>
            </div>
        </div>`;
    })
    .join('');
  container.style.display = 'block';
}

function updateDiskStatus(status) {
  const container = document.getElementById('diskStatusBar');
  if (!container) return;

  const disk = status?.advancedView?.Utilization?.Disk?.Root;
  if (!disk) {
    container.style.display = 'none';
    return;
  }

  const { Free: free = 0, Total: total = 1 } = disk;
  const used = total - free;
  const pct = (used / total) * 100;
  const color = pct > 90 ? '#f5576c' : pct > 75 ? '#ffc107' : '#38ef7d';

  container.innerHTML = `<div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
            <strong>Root Filesystem</strong><span style="font-weight: 500;">${formatBytes(used)} / ${formatBytes(total)}</span>
        </div>
        <div class="progressBar" style="background-color: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
            <div style="width: ${pct}%; height: 100%; background: ${color}; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.875rem; font-weight: 500;">${pct.toFixed(1)}% Used</div>
        </div>
        <div style="margin-top: 0.5rem; font-size: 0.875rem; color: #6c757d;">
            <i class="fas fa-check-circle" style="color: #28a745;"></i> Available: ${formatBytes(free)}
        </div>
    </div>`;
  container.style.display = 'block';
}

// =============================================================================
// Metric Definitions
// =============================================================================

const METRIC_DEFS = [
  {
    key: 'memory',
    canvasId: 'memoryChart',
    loadingId: 'memoryLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=${h}`,
    prepare: (p) => {
      if (!p?.success || !p.data?.length) return null;
      const freeVals = p.data.filter((d) => d.free_mb !== null).map((d) => d.free_mb);
      const cacheVals = p.data
        .filter((d) => d.buffer_cache_mb !== null)
        .map((d) => d.buffer_cache_mb);
      if (!freeVals.length) return null;

      const currentMemEl = document.getElementById('currentMemory');
      const avgMemEl = document.getElementById('avgMemory');
      const currentCacheEl = document.getElementById('currentBufferCache');
      const avgCacheEl = document.getElementById('avgBufferCache');

      if (currentMemEl) currentMemEl.textContent = freeVals.at(-1).toFixed(1) + ' MB';
      if (avgMemEl)
        avgMemEl.textContent =
          (freeVals.reduce((a, b) => a + b) / freeVals.length).toFixed(1) + ' MB';
      if (cacheVals.length && currentCacheEl) {
        currentCacheEl.textContent = cacheVals.at(-1).toFixed(1) + ' MB';
        if (avgCacheEl)
          avgCacheEl.textContent =
            (cacheVals.reduce((a, b) => a + b) / cacheVals.length).toFixed(1) + ' MB';
      }

      const datasets = [createDataset('Free Memory', mapChartData(p, 'free_mb'), 'purple')];
      if (cacheVals.length)
        datasets.push(
          createDataset('Buffer Cache', mapChartData(p, 'buffer_cache_mb'), 'teal', { fill: false })
        );
      return {
        datasets,
        opts: {
          yLabel: 'Memory (MB)',
          yTickFormatter: (v) => v.toFixed(0) + ' MB',
          tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(1) + ' MB',
        },
      };
    },
  },
  {
    key: 'cpu',
    canvasId: 'cpuChart',
    loadingId: 'cpuLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=${h}`,
    prepare: (p) =>
      !p?.success || !p.data?.length
        ? null
        : {
            datasets: [createDataset('CPU Usage (%)', mapChartData(p, 'cpu_usage'), 'red')],
            opts: {
              yLabel: 'CPU Usage (%)',
              beginAtZero: true,
              yMax: 100,
              yTickFormatter: (v) => v.toFixed(0) + '%',
              tooltipLabel: (c) => 'CPU Usage: ' + c.parsed.y.toFixed(2) + '%',
            },
          },
  },
  {
    key: 'load',
    canvasId: 'loadChart',
    loadingId: 'loadLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=${h}`,
    prepare: (p) =>
      !p?.success || !p.data?.length
        ? null
        : {
            datasets: [
              createDataset('1 min', mapChartData(p, 'shortterm'), 'coral', { fill: false }),
              createDataset('5 min', mapChartData(p, 'midterm'), 'orange', { fill: false }),
              createDataset('15 min', mapChartData(p, 'longterm'), 'teal', { fill: false }),
            ],
            opts: {
              yLabel: 'Load Average',
              beginAtZero: true,
              yTickFormatter: (v) => v.toFixed(2),
              tooltipLabel: (c) => c.dataset.label + ' Load: ' + c.parsed.y.toFixed(2),
            },
          },
  },
  {
    key: 'disk',
    canvasId: 'diskChart',
    loadingId: 'diskLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=${h}`,
    prepare: (p) =>
      !p?.success || !p.data?.length
        ? null
        : {
            datasets: [createDataset('Free Space (GB)', mapChartData(p, 'free_gb'), 'green')],
            opts: {
              yLabel: 'Free Space (GB)',
              yTickFormatter: (v) => v.toFixed(1) + ' GB',
              tooltipLabel: (c) => 'Free Space: ' + c.parsed.y.toFixed(2) + ' GB',
            },
          },
  },
  {
    key: 'network',
    canvasId: 'networkChart',
    loadingId: 'networkLoading',
    url: (h) =>
      `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?interface=${getSelectedInterface()}&hours=${h}`,
    prepare: (p) =>
      !p?.success || !p.data?.length
        ? null
        : {
            datasets: [
              createDataset('Download (RX)', mapChartData(p, 'rx_kbps'), 'blue'),
              createDataset('Upload (TX)', mapChartData(p, 'tx_kbps'), 'pink'),
            ],
            opts: {
              yLabel: 'Bandwidth (Kbps)',
              beginAtZero: true,
              yTickFormatter: (v) => v.toFixed(0) + ' Kbps',
              tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(2) + ' Kbps',
            },
          },
  },
  {
    key: 'thermal',
    canvasId: 'thermalChart',
    cardId: 'thermalCard',
    loadingId: 'thermalLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/thermal?hours=${h}`,
    prepare: (p) => {
      if (!p?.success || !p.data?.length || !p.zones?.length) return { hidden: true };
      const colorKeys = ['coral', 'blue', 'yellow', 'teal', 'indigo', 'orange', 'green', 'pink'];
      const unit = getTempUnit(state.useFahrenheit);
      const convertData = (response, key) =>
        mapChartData(response, key).map((d) => ({
          x: d.x,
          y: state.useFahrenheit ? toFahrenheit(d.y) : d.y,
        }));
      const getZoneLabel = (z) => formatThermalZoneName(p.zone_names?.[z] || z);
      return {
        datasets: p.zones.map((z, i) =>
          createDataset(getZoneLabel(z), convertData(p, z), colorKeys[i % colorKeys.length], {
            fill: false,
          })
        ),
        opts: {
          yLabel: `Temperature (${unit})`,
          yTickFormatter: (v) => v.toFixed(0) + unit,
          tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(1) + unit,
        },
      };
    },
  },
  {
    key: 'voltage',
    canvasId: 'voltageChart',
    cardId: 'voltageCard',
    loadingId: 'voltageLoading',
    statsBarId: 'voltageStatsBar',
    getHours: getVoltageHours, // Use voltage-specific time selector
    url: () => `/api/plugin/fpp-plugin-watcher/voltage/history?hours=${getVoltageHours()}`,
    prepare: (p) => {
      // Check if data exists - if not, hide the card
      if (!p?.success || !p.data?.length) {
        hideElement('voltageCard');
        hideElement('voltageStatsBar');
        return { hidden: true };
      }

      // Show card and stats bar (stats bar needs flex for horizontal layout)
      showElement('voltageCard');
      const voltageStatsBar = document.getElementById('voltageStatsBar');
      if (voltageStatsBar) voltageStatsBar.style.display = 'flex';

      // Get available rails and labels from response
      const rails = p.rails || [];
      const labels = p.labels || {};

      // Color mapping for different voltage rails
      const railColors = {
        // Pi 5 PMIC rails (13 rails)
        EXT5V_V: 'green',
        VDD_CORE_V: 'coral',
        HDMI_V: 'orange',
        '3V7_WL_SW_V': 'pink',
        '3V3_SYS_V': 'blue',
        '3V3_DAC_V': 'indigo',
        '3V3_ADC_V': 'cyan',
        '1V8_SYS_V': 'purple',
        '1V1_SYS_V': 'magenta',
        DDR_VDD2_V: 'teal',
        DDR_VDDQ_V: 'lime',
        '0V8_SW_V': 'yellow',
        '0V8_AON_V': 'brown',
        // Legacy rails (Pi 4 and earlier)
        core: 'coral',
        sdram_c: 'blue',
        sdram_i: 'purple',
        sdram_p: 'teal',
      };

      // Determine platform type and key rails to display in stats bar
      // Pi 5 PMIC rails (13 rails) vs Legacy Pi (4 rails: core, sdram_c, sdram_i, sdram_p)
      const isPi5 = rails.includes('EXT5V_V');

      // Define 4 key rails to show in stats bar for each platform
      const keyRails = isPi5
        ? ['EXT5V_V', 'VDD_CORE_V', '3V3_SYS_V', '1V8_SYS_V']
        : ['core', 'sdram_c', 'sdram_i', 'sdram_p'];

      // Helper to update stat element
      const updateStat = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
      };

      // Update stats bar labels and values dynamically
      const lastEntry = p.data.at(-1);
      keyRails.forEach((rail, index) => {
        // Update label using labels from API response
        const labelEl = document.getElementById(`voltageStat${index}Label`);
        if (labelEl) {
          labelEl.textContent = labels[rail] || rail;
        }

        // Update value
        if (lastEntry) {
          const voltages = lastEntry.voltages || {};
          if (voltages[rail] !== undefined) {
            const value =
              typeof voltages[rail] === 'object' ? voltages[rail].avg : voltages[rail];
            updateStat(`voltageStat${index}`, value.toFixed(3) + ' V');
          }
        }
      });

      // Check for 5V input voltage drop (critical for power supply monitoring)
      // Only monitor EXT5V_V rail (Pi 5) - core voltage fluctuations are normal CPU behavior
      const statusBar = document.getElementById('voltageStatusBar');
      if (statusBar) {
        const ext5vValues = p.data
          .map((d) => {
            // Only use EXT5V_V rail - do NOT fall back to core (different voltage level)
            const v = d.voltages?.EXT5V_V;
            if (v === undefined) return null;
            return typeof v === 'object' ? v.avg : v;
          })
          .filter((v) => v != null);

        // Only show warning if we have EXT5V_V data (Pi 5 with PMIC)
        if (ext5vValues.length >= 10) {
          const avg = ext5vValues.reduce((a, b) => a + b) / ext5vValues.length;
          const min = Math.min(...ext5vValues);
          const dropPercent = ((avg - min) / avg) * 100;

          if (dropPercent > 3) {
            // 3% drop threshold for 5V rail
            statusBar.style.display = 'block';
            statusBar.innerHTML = `<div style="display: flex; align-items: center; gap: 0.5rem;">
              <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
              <span><strong>Power Supply Issue:</strong> ${dropPercent.toFixed(1)}% voltage drop on 5V input (${min.toFixed(2)}V min vs ${avg.toFixed(2)}V avg). Check your power supply.</span>
            </div>`;
          } else {
            statusBar.style.display = 'none';
          }
        } else {
          // No EXT5V_V data available (legacy Pi or insufficient data) - hide warning
          statusBar.style.display = 'none';
        }
      }

      // Create a dataset for each voltage rail
      const datasets = [];
      let globalMin = Infinity;
      let globalMax = -Infinity;

      for (const rail of rails) {
        const chartData = p.data
          .map((d) => {
            const v = d.voltages?.[rail];
            if (v === undefined) return null;
            const value = typeof v === 'object' ? v.avg : v;
            return { x: d.timestamp * 1000, y: value };
          })
          .filter((d) => d !== null);

        if (chartData.length === 0) continue;

        // Track global min/max for Y-axis
        const values = chartData.map((d) => d.y);
        globalMin = Math.min(globalMin, ...values);
        globalMax = Math.max(globalMax, ...values);

        const color = railColors[rail] || 'yellow';
        const label = labels[rail] || rail;
        datasets.push(createDataset(label, chartData, color, { fill: false }));
      }

      if (datasets.length === 0) return { hidden: true };

      // Dynamic Y-axis with padding
      const range = globalMax - globalMin;
      const padding = Math.max(range * 0.1, 0.1); // At least 0.1V padding
      const yMin = Math.floor((globalMin - padding) * 10) / 10; // Round to 0.1V
      const yMax = Math.ceil((globalMax + padding) * 10) / 10;

      return {
        datasets,
        opts: {
          yLabel: 'Voltage (V)',
          yMin: Math.max(0, yMin),
          yMax: yMax,
          yTickFormatter: (v) => v.toFixed(1) + ' V',
          tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(3) + ' V',
        },
      };
    },
  },
  {
    key: 'wireless',
    canvasId: 'wirelessChart',
    cardId: 'wirelessCard',
    loadingId: 'wirelessLoading',
    url: (h) =>
      `/api/plugin/fpp-plugin-watcher/metrics/wireless?interface=${getSelectedWirelessInterface()}&hours=${h}`,
    prepare: (p) => {
      if (!p?.success || !p.data?.length || !p.interfaces?.length) return { hidden: true };
      const selectedInterface = getSelectedWirelessInterface();
      const iface = p.interfaces.includes(selectedInterface) ? selectedInterface : p.interfaces[0];
      const colorMap = { signal_quality: 'teal', signal_power: 'coral', signal_noise: 'orange' };
      const datasets = [];
      (p.available_metrics || {})[iface]?.forEach((metric) => {
        const key = `${iface}_${metric}`;
        const label = metric
          .replace('signal_', '')
          .replace('_', ' ')
          .replace(/^./, (c) => c.toUpperCase());
        datasets.push(
          createDataset(`${iface} - ${label}`, mapChartData(p, key), colorMap[metric] || 'teal', {
            fill: false,
          })
        );
      });
      return datasets.length
        ? {
            datasets,
            opts: {
              yLabel: 'Signal Metrics',
              yTickFormatter: (v) => v.toFixed(0),
              tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(1),
            },
          }
        : { hidden: true };
    },
  },
  {
    key: 'apache',
    canvasId: 'apacheChart',
    cardId: 'apacheCard',
    loadingId: 'apacheLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/apache?hours=${h}`,
    prepare: (p) => {
      if (!p?.success || !p.data?.length) return { hidden: true };
      const hasRequests = p.data.some((d) => d.requests_per_sec !== null);
      const hasConnections = p.data.some((d) => d.connections !== null);
      if (!hasRequests && !hasConnections) return { hidden: true };
      return {
        datasets: [
          createDataset('Requests/sec', mapChartData(p, 'requests_per_sec'), 'blue', { fill: false }),
          createDataset('Connections', mapChartData(p, 'connections'), 'coral', {
            fill: false,
            yAxisID: 'y1',
          }),
          createDataset('Idle Workers', mapChartData(p, 'idle_workers'), 'teal', {
            fill: false,
            yAxisID: 'y1',
          }),
        ],
        opts: {
          yLabel: 'Requests/sec',
          beginAtZero: true,
          yTickFormatter: (v) => v.toFixed(1),
          tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(2),
          extraScales: {
            y1: {
              type: 'linear',
              display: true,
              position: 'right',
              title: { display: true, text: 'Connections / Workers' },
              grid: { drawOnChartArea: false },
              beginAtZero: true,
            },
          },
        },
      };
    },
  },
  {
    key: 'apacheWorkers',
    canvasId: 'apacheWorkersChart',
    cardId: 'apacheWorkersCard',
    loadingId: 'apacheWorkersLoading',
    url: (h) => `/api/plugin/fpp-plugin-watcher/metrics/apache?hours=${h}`,
    prepare: (p) => {
      if (!p?.success || !p.data?.length) return { hidden: true };
      const hasSb = p.data.some((d) => d.sb_sending !== null || d.sb_waiting !== null);
      if (!hasSb) return { hidden: true };
      // Stacked chart: order matters (bottom to top)
      const workerStates = [
        { key: 'sb_sending', label: 'Sending', color: 'coral' },
        { key: 'sb_reading', label: 'Reading', color: 'orange' },
        { key: 'sb_keepalive', label: 'Keepalive', color: 'yellow' },
        { key: 'sb_waiting', label: 'Waiting', color: 'teal' },
        { key: 'sb_open', label: 'Open Slots', color: 'blue' },
      ];
      return {
        datasets: workerStates.map((s) =>
          createDataset(s.label, mapChartData(p, s.key), s.color, { fill: true })
        ),
        opts: {
          yLabel: 'Workers',
          beginAtZero: true,
          yTickFormatter: (v) => v.toFixed(0),
          tooltipLabel: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(0),
        },
        chartType: 'line',
        stacked: true,
      };
    },
  },
];

// =============================================================================
// Chart Update Functions
// =============================================================================

async function updateMetric(def, hours) {
  const isInitialLoad = !state.charts[def.key];
  if (isInitialLoad) setLoading(def.loadingId, true);

  // Use metric-specific hours if defined, otherwise use passed hours
  const metricHours = def.getHours ? parseFloat(def.getHours()) : hours;

  try {
    const prepared = def.prepare(await fetchJson(def.url(metricHours)));

    if (!prepared || prepared.hidden) {
      if (def.cardId) {
        const card = document.getElementById(def.cardId);
        if (card) card.style.display = 'none';
      }
    } else {
      if (def.cardId) {
        const card = document.getElementById(def.cardId);
        if (card) card.style.display = 'block';
      }
      const chartOpts = buildChartOptions(metricHours, prepared.opts);
      // Handle stacked charts
      if (prepared.stacked) {
        chartOpts.scales.y.stacked = true;
        chartOpts.scales.x.stacked = true;
      }
      updateOrCreateChart(
        state.charts,
        def.key,
        def.canvasId,
        prepared.chartType || 'line',
        prepared.datasets,
        chartOpts
      );
    }
  } catch (e) {
    console.error(`Error updating ${def.key}:`, e);
    if (def.cardId) {
      const card = document.getElementById(def.cardId);
      if (card) card.style.display = 'none';
    }
  }

  if (isInitialLoad) setLoading(def.loadingId, false);
}

function refreshMetric(key) {
  const def = METRIC_DEFS.find((d) => d.key === key);
  if (def) updateMetric(def, getSelectedHours());
}

async function updateAllCharts() {
  const hours = getSelectedHours();
  await Promise.all(METRIC_DEFS.map((d) => updateMetric(d, hours)));
}

// =============================================================================
// Interface Loading
// =============================================================================

async function loadInterfaces() {
  try {
    const { success, interfaces = [] } = await fetchJson(
      '/api/plugin/fpp-plugin-watcher/metrics/interface/list'
    );
    if (!success || !interfaces.length) return;

    const select = document.getElementById('interfaceSelect');
    if (!select) return;

    const current = select.options.length === 1 ? getDefaultAdapter() : select.value;
    select.innerHTML = interfaces
      .map((i) => `<option value="${escapeHtml(i)}">${escapeHtml(i)}</option>`)
      .join('');
    select.value = interfaces.includes(current)
      ? current
      : interfaces.includes(getDefaultAdapter())
        ? getDefaultAdapter()
        : interfaces[0];
  } catch (e) {
    console.error('Error loading interfaces:', e);
  }
}

async function loadWirelessInterfaces() {
  try {
    const { success, interfaces = [] } = await fetchJson(
      '/api/plugin/fpp-plugin-watcher/metrics/wireless/interfaces'
    );
    if (!success || !interfaces.length) return;

    const select = document.getElementById('wirelessInterfaceSelect');
    if (!select) return;

    const current = select.value;
    select.innerHTML = interfaces
      .map((i) => `<option value="${escapeHtml(i)}">${escapeHtml(i)}</option>`)
      .join('');
    select.value = interfaces.includes(current) ? current : interfaces[0];
  } catch (e) {
    console.error('Error loading wireless interfaces:', e);
  }
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
    loadInterfaces();
    loadWirelessInterfaces();
    await loadSystemStatus();
    await updateAllCharts();
    updateLastUpdateTime();
  } catch (e) {
    console.error('Error loading metrics:', e);
  }

  if (btn) btn.style.animation = '';
  state.isRefreshing = false;
}

// =============================================================================
// Public Interface
// =============================================================================

export const localMetrics = {
  pageId: 'localMetricsUI',

  /**
   * Initialize the local metrics page
   * @param {Object} config - Configuration from PHP
   * @param {string} config.defaultAdapter - Default network adapter
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
      useFahrenheit: false,
      refreshInterval: null,
      config: {},
    };
  },

  // Public methods for onclick handlers
  refresh: loadAllMetrics,
  refreshMetric,
  updateAllCharts,
};
