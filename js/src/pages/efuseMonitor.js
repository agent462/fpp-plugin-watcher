/**
 * eFuse Monitor Page Module
 *
 * Provides interactive heatmap visualization for eFuse current monitoring.
 * Extracted from efuseHeatmap.js and converted to ES module format.
 */

import { escapeHtml, updateLastUpdateTime } from '../core/utils.js';
import { updateOrCreateChart } from '../core/charts.js';
import { fetchJson } from '../core/api.js';

// =============================================================================
// Constants
// =============================================================================

// Color thresholds in mA (0-6A gradient)
const EFUSE_COLORS = {
  0:    '#1a1a2e',  // Dark (off/zero)
  500:  '#16213e',  // Cool blue
  1000: '#1e5128',  // Green
  2000: '#4e9f3d',  // Light green
  3000: '#ffc107',  // Yellow (warning)
  4000: '#fd7e14',  // Orange
  5000: '#dc3545',  // Red (high)
  6000: '#c82333'   // Dark red (max)
};

const MAX_CURRENT_MA = 6000;
const PORTS_PER_CHART = 16;
const MAX_CHART_POINTS = 200;
const CONTROL_COOLDOWN_MS = 500;
const AUTO_REFRESH_INTERVAL_MS = 3000;

// Port chart colors (16 distinct colors)
const PORT_CHART_COLORS = [
  '#4e9f3d', '#3498db', '#9b59b6', '#e67e22',
  '#1abc9c', '#e74c3c', '#34495e', '#f1c40f',
  '#16a085', '#2ecc71', '#8e44ad', '#d35400',
  '#00bcd4', '#ff5722', '#607d8b', '#795548'
];

// =============================================================================
// Private State
// =============================================================================

let state = {
  charts: {},
  currentPortData: {},
  selectedPort: null,
  selectedPortConfig: null,
  refreshInterval: null,
  chartSkeletonsCreated: false,
  lastControlAction: 0,
  confirmModalCallback: null,
  controlCapabilities: null,
  config: {}
};

// =============================================================================
// Utility Functions
// =============================================================================

/**
 * Downsample data array to target number of points using LTTB algorithm (simplified)
 */
export function downsampleData(data, targetPoints) {
  if (!data || data.length <= targetPoints) return data;

  const result = [];
  const bucketSize = (data.length - 2) / (targetPoints - 2);

  result.push(data[0]); // Always keep first point

  for (let i = 1; i < targetPoints - 1; i++) {
    const bucketStart = Math.floor((i - 1) * bucketSize) + 1;
    const bucketEnd = Math.min(Math.floor(i * bucketSize) + 1, data.length - 1);

    // Find point with max value in bucket (preserves peaks)
    let maxIdx = bucketStart;
    let maxVal = data[bucketStart].y;
    for (let j = bucketStart + 1; j < bucketEnd; j++) {
      if (data[j].y > maxVal) {
        maxVal = data[j].y;
        maxIdx = j;
      }
    }
    result.push(data[maxIdx]);
  }

  result.push(data[data.length - 1]); // Always keep last point
  return result;
}

/**
 * Get chart color by index
 */
export function getEfuseChartColor(index) {
  return PORT_CHART_COLORS[index % PORT_CHART_COLORS.length];
}

/**
 * Get color for current value based on thresholds
 */
export function getEfuseColor(mA) {
  if (mA <= 0) return EFUSE_COLORS[0];

  const thresholds = Object.keys(EFUSE_COLORS).map(Number).sort((a, b) => a - b);

  for (let i = thresholds.length - 1; i >= 0; i--) {
    if (mA >= thresholds[i]) {
      return EFUSE_COLORS[thresholds[i]];
    }
  }

  return EFUSE_COLORS[0];
}

/**
 * Format current value for display
 */
export function formatCurrent(mA, showUnit = true) {
  if (mA === null || mA === undefined) return '--';
  if (mA === 0) return showUnit ? '0 mA' : '0';
  if (mA >= 1000) {
    return (mA / 1000).toFixed(2) + (showUnit ? ' A' : '');
  }
  return mA + (showUnit ? ' mA' : '');
}

/**
 * Check rate limiting for control actions
 */
function canDoControlAction() {
  const now = Date.now();
  if (now - state.lastControlAction < CONTROL_COOLDOWN_MS) {
    return false;
  }
  state.lastControlAction = now;
  return true;
}

// =============================================================================
// Toast Notifications
// =============================================================================

/**
 * Show toast notification
 */
export function showToast(message, type = 'info', duration = 3000) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;

  const icons = {
    success: 'fa-check-circle',
    error: 'fa-exclamation-circle',
    warning: 'fa-exclamation-triangle',
    info: 'fa-info-circle'
  };

  toast.innerHTML = `
    <i class="fas ${icons[type] || icons.info}"></i>
    <span>${escapeHtml(message)}</span>
  `;

  container.appendChild(toast);

  // Trigger animation
  requestAnimationFrame(() => {
    toast.classList.add('show');
  });

  // Auto-remove after duration
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// =============================================================================
// Confirmation Modal
// =============================================================================

/**
 * Show confirmation modal
 */
export function showConfirmModal(title, message, actionText, callback) {
  const modal = document.getElementById('confirmModal');
  if (!modal) return;

  document.getElementById('confirmModalTitle').textContent = title;
  document.getElementById('confirmModalMessage').textContent = message;
  document.getElementById('confirmModalAction').textContent = actionText;

  state.confirmModalCallback = callback;
  modal.style.display = 'flex';
}

/**
 * Hide confirmation modal
 */
export function hideConfirmModal() {
  const modal = document.getElementById('confirmModal');
  if (modal) {
    modal.style.display = 'none';
  }
  state.confirmModalCallback = null;
}

/**
 * Execute confirmed action
 */
export function executeConfirmAction() {
  if (state.confirmModalCallback) {
    state.confirmModalCallback();
  }
  hideConfirmModal();
}

// =============================================================================
// Chart Creation
// =============================================================================

/**
 * Create chart skeleton containers based on port count
 */
function createChartSkeletons(portCount) {
  const container = document.getElementById('efuseHistoryChartsContainer');
  if (!container || state.chartSkeletonsCreated) return;

  const numCharts = Math.ceil(portCount / PORTS_PER_CHART);
  let html = '';

  for (let i = 0; i < numCharts; i++) {
    const startPort = i * PORTS_PER_CHART + 1;
    const endPort = Math.min((i + 1) * PORTS_PER_CHART, portCount);
    const chartId = `efuseHistoryChart_${i}`;

    html += `
      <div class="efuseChartCard" id="chartCard_${i}">
        <div class="efuseChartTitle">
          <span><i class="fas fa-chart-area"></i> Current History - Ports ${startPort}-${endPort}</span>
        </div>
        <div class="chartLoading" id="chartLoading_${i}">
          <i class="fas fa-spinner fa-spin"></i> Loading chart data...
        </div>
        <canvas id="${chartId}" style="max-height: 400px;"></canvas>
      </div>
    `;
  }

  container.innerHTML = html;
  state.chartSkeletonsCreated = true;

  // Create empty charts immediately so structure is visible
  for (let i = 0; i < numCharts; i++) {
    createEmptyChart(i);
  }
}

/**
 * Create an empty chart with axes but no data
 */
function createEmptyChart(groupIndex) {
  const chartId = `efuseHistoryChart_${groupIndex}`;
  const chartKey = `history_${groupIndex}`;

  // Skip if chart already exists
  if (state.charts[chartKey]) return;

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: {
      mode: 'index',
      intersect: false
    },
    scales: {
      x: {
        type: 'time',
        time: {
          displayFormats: { hour: 'HH:mm', minute: 'HH:mm' }
        }
      },
      y: {
        min: 0,
        title: { display: true, text: 'mA' }
      }
    },
    plugins: {
      legend: {
        position: 'top',
        labels: { boxWidth: 12, usePointStyle: true }
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            return context.dataset.label + ': ' + formatCurrent(context.raw.y);
          }
        }
      }
    }
  };

  updateOrCreateChart(state.charts, chartKey, chartId, 'line', [], options);
}

// =============================================================================
// Port Control Functions
// =============================================================================

/**
 * Toggle a port on/off
 */
export async function togglePort(portName, newState = null) {
  if (!canDoControlAction()) {
    showToast('Please wait before trying again', 'warning');
    return;
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/efuse/port/toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ port: portName, state: newState })
    });

    const data = await response.json();

    if (data.success) {
      const action = data.newState === 'on' ? 'enabled' : 'disabled';
      showToast(`${portName} ${action}`, 'success');
      // Refresh data to show new state
      setTimeout(() => loadCurrentData(), 500);
    } else {
      showToast(data.error || 'Failed to toggle port', 'error');
    }
  } catch (error) {
    console.error('Error toggling port:', error);
    showToast('Network error', 'error');
  }
}

/**
 * Reset a tripped fuse
 */
export async function resetFuse(portName) {
  if (!canDoControlAction()) {
    showToast('Please wait before trying again', 'warning');
    return;
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/efuse/port/reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ port: portName })
    });

    const data = await response.json();

    if (data.success) {
      showToast(`${portName} fuse reset`, 'success');
      setTimeout(() => loadCurrentData(), 500);
    } else {
      showToast(data.error || 'Failed to reset fuse', 'error');
    }
  } catch (error) {
    console.error('Error resetting fuse:', error);
    showToast('Network error', 'error');
  }
}

/**
 * Master control - turn all ports on or off
 */
export async function masterControl(controlState) {
  if (!canDoControlAction()) {
    showToast('Please wait before trying again', 'warning');
    return;
  }

  // Require confirmation for "All Off"
  if (controlState === 'off') {
    const portCount = state.config?.ports || 16;
    showConfirmModal(
      'Disable All Ports?',
      `This will turn off all ${portCount} eFuse ports immediately. Your show will stop outputting to all connected pixels.`,
      'DISABLE ALL',
      () => doMasterControl(controlState)
    );
    return;
  }

  doMasterControl(controlState);
}

/**
 * Execute master control after confirmation
 */
async function doMasterControl(controlState) {
  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/efuse/ports/master', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ state: controlState })
    });

    const data = await response.json();

    if (data.success) {
      const action = controlState === 'on' ? 'enabled' : 'disabled';
      showToast(`All ports ${action}`, 'success');
      setTimeout(() => loadCurrentData(), 500);
    } else {
      showToast(data.error || 'Failed to set all ports', 'error');
    }
  } catch (error) {
    console.error('Error with master control:', error);
    showToast('Network error', 'error');
  }
}

/**
 * Reset all tripped fuses
 */
export async function resetAllTripped() {
  if (!canDoControlAction()) {
    showToast('Please wait before trying again', 'warning');
    return;
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/efuse/ports/reset-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });

    const data = await response.json();

    if (data.success) {
      const count = data.resetCount || 0;
      if (count > 0) {
        showToast(`Reset ${count} tripped fuse${count > 1 ? 's' : ''}`, 'success');
      } else {
        showToast('No fuses to reset', 'info');
      }
      setTimeout(() => loadCurrentData(), 500);
    } else {
      showToast(data.error || 'Failed to reset fuses', 'error');
    }
  } catch (error) {
    console.error('Error resetting fuses:', error);
    showToast('Network error', 'error');
  }
}

/**
 * Toggle the currently selected port
 */
export function toggleSelectedPort() {
  if (!state.selectedPort) return;
  const portData = state.currentPortData[state.selectedPort];
  const isEnabled = portData?.portEnabled !== false;
  togglePort(state.selectedPort, isEnabled ? 'off' : 'on');
}

/**
 * Reset the currently selected port's fuse
 */
export function resetSelectedPort() {
  if (!state.selectedPort) return;
  resetFuse(state.selectedPort);
}

/**
 * Handle power button click on port tile
 */
export function portPowerClick(portName, isEnabled, isTripped) {
  if (isTripped) {
    resetFuse(portName);
  } else {
    togglePort(portName, isEnabled ? 'off' : 'on');
  }
}

// =============================================================================
// UI Update Functions
// =============================================================================

/**
 * Update tripped fuse banner visibility and content
 */
function updateTrippedBanner(ports) {
  const banner = document.getElementById('trippedBanner');
  const resetBtn = document.getElementById('resetTrippedBtn');
  if (!banner) return;

  const trippedPorts = Object.entries(ports || {})
    .filter(([_, p]) => p.fuseTripped)
    .map(([name]) => name);

  const count = trippedPorts.length;

  if (count > 0) {
    banner.style.display = 'flex';
    document.getElementById('trippedCount').textContent = count;
    document.getElementById('trippedPortList').textContent = trippedPorts.join(', ');

    if (resetBtn) {
      resetBtn.style.display = 'inline-flex';
      resetBtn.querySelector('.badge').textContent = count;
    }
  } else {
    banner.style.display = 'none';
    if (resetBtn) {
      resetBtn.style.display = 'none';
    }
  }
}

/**
 * Update port output configuration display (with integrated controls)
 */
function updatePortOutputConfig(portData) {
  const container = document.getElementById('portOutputConfig');
  if (!container) return;

  // Build control section
  const isEnabled = portData?.portEnabled !== false;
  const isTripped = portData?.fuseTripped === true;

  let statusClass = 'enabled';
  let statusText = 'ENABLED';
  if (isTripped) {
    statusClass = 'tripped';
    statusText = 'TRIPPED';
  } else if (!isEnabled) {
    statusClass = 'disabled';
    statusText = 'DISABLED';
  }

  const toggleBtnClass = isEnabled ? 'danger' : 'primary';
  const toggleBtnText = isEnabled ? 'Disable' : 'Enable';
  const resetBtnDisplay = isTripped ? 'inline-flex' : 'none';

  const controlHtml = `
    <div class="portControlRow">
      <div class="portStatusIndicator">
        <span class="statusDot ${statusClass}"></span>
        <span>${statusText}</span>
      </div>
      <div class="portControlButtons">
        <button class="efuseControlBtn ${toggleBtnClass}" onclick="page.toggleSelectedPort()">
          <i class="fas fa-power-off"></i> ${toggleBtnText}
        </button>
        <button class="efuseControlBtn warning" onclick="page.resetSelectedPort()" style="display: ${resetBtnDisplay};">
          <i class="fas fa-redo"></i> Reset
        </button>
      </div>
    </div>
  `;

  if (!portData || !portData.pixelCount) {
    container.innerHTML = `
      <div class="outputConfigTitle">Output Configuration</div>
      <div class="noConfig">No output configuration found</div>
      ${controlHtml}
    `;
    return;
  }

  const brightnessDisplay = (portData.brightness !== null && portData.brightness !== undefined)
    ? portData.brightness + '%'
    : '--';

  container.innerHTML = `
    <div class="outputConfigTitle">Output Configuration</div>
    <div class="outputConfigGrid">
      <div class="configItem">
        <span class="configLabel">Type:</span>
        <span class="configValue">${escapeHtml(portData.protocol || 'Unknown')}</span>
      </div>
      <div class="configItem">
        <span class="configLabel">Pixels:</span>
        <span class="configValue">${portData.pixelCount || 0}</span>
      </div>
      <div class="configItem">
        <span class="configLabel">Brightness:</span>
        <span class="configValue">${brightnessDisplay}</span>
      </div>
      <div class="configItem">
        <span class="configLabel">Color Order:</span>
        <span class="configValue">${escapeHtml(portData.colorOrder || 'RGB')}</span>
      </div>
      ${portData.description ? `
      <div class="configItem configDesc">
        <span class="configLabel">Description:</span>
        <span class="configValue">${escapeHtml(portData.description)}</span>
      </div>` : ''}
    </div>
    <div class="expectedInfo">
      Expected: ~${formatCurrent(portData.expectedCurrentMa)} typical / ${formatCurrent(portData.maxCurrentMa)} max
    </div>
    ${controlHtml}
  `;
}

/**
 * Update port detail panel control buttons
 */
function updatePortDetailControls(portData) {
  if (portData) {
    const mergedData = state.selectedPortConfig
      ? { ...portData, ...state.selectedPortConfig }
      : portData;
    updatePortOutputConfig(mergedData);
  }
}

/**
 * Update the port detail panel's current reading
 */
function updatePortDetailCurrent(portData) {
  const currentElem = document.getElementById('portDetailCurrent');
  if (currentElem) {
    currentElem.textContent = formatCurrent(portData.currentMa);
  }

  const expectedElem = document.getElementById('portDetailExpected');
  if (expectedElem) {
    expectedElem.textContent = formatCurrent(portData.expectedCurrentMa);
  }
}

/**
 * Update stats bar with totals
 */
function updateStatsBar(data) {
  const totals = data.totals || {};

  const totalElem = document.getElementById('totalCurrent');
  if (totalElem) {
    totalElem.textContent = (totals.totalAmps || 0).toFixed(2) + ' A';
  }

  const activeElem = document.getElementById('activePorts');
  if (activeElem) {
    activeElem.textContent = (totals.activePortCount || 0) + ' / ' + (totals.portCount || 0);
  }
}

/**
 * Update the port grid with current values
 */
function updatePortGrid(ports) {
  const grid = document.getElementById('efuseGrid');
  if (!grid) return;

  // Sort ports by number
  const sortedPorts = Object.entries(ports || {}).sort((a, b) => {
    const numA = parseInt(a[0].replace(/\D/g, '')) || 0;
    const numB = parseInt(b[0].replace(/\D/g, '')) || 0;
    return numA - numB;
  });

  if (sortedPorts.length === 0) {
    grid.innerHTML = '<div class="empty-message"><i class="fas fa-plug"></i><h3>No Port Data</h3><p>No port data available</p></div>';
    return;
  }

  // Calculate grid layout (max 8 columns)
  const portCount = sortedPorts.length;
  const cols = Math.min(8, portCount);

  grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;

  let html = '';
  for (const [portName, portData] of sortedPorts) {
    const current = portData.currentMa || 0;
    const isEnabled = portData.portEnabled !== false;
    const isTripped = portData.fuseTripped === true;
    const color = isTripped ? '#c82333' : (isEnabled ? getEfuseColor(current) : '#333');
    const status = isTripped ? 'tripped' : (portData.status || 'normal');
    const percent = Math.min(100, (current / MAX_CURRENT_MA) * 100);
    const isSelected = state.selectedPort === portName ? 'selected' : '';
    const disabledClass = !isEnabled ? 'disabled' : '';

    // Power button icon based on state
    const powerIcon = isTripped ? 'fa-exclamation-triangle' : 'fa-power-off';
    const powerClass = isTripped ? 'tripped' : (isEnabled ? 'on' : 'off');
    const powerTitle = isTripped ? 'Reset fuse' : (isEnabled ? 'Disable port' : 'Enable port');

    html += `
      <div class="efusePort ${status} ${isSelected} ${disabledClass}"
           style="background: ${color};" title="Click to view ${escapeHtml(portName)} details">
        <button class="portPowerBtn ${powerClass}" onclick="event.stopPropagation(); page.portPowerClick('${escapeHtml(portName)}', ${isEnabled}, ${isTripped})" title="${powerTitle}">
          <i class="fas ${powerIcon}"></i>
        </button>
        <div class="portClickArea" onclick="page.showPortDetail('${escapeHtml(portName)}')">
          <div class="portName">${escapeHtml(portName.replace('Port', 'P'))}</div>
          <div class="portValue">${formatCurrent(current, false)}</div>
          <div class="portBar">
            <div class="portBarFill" style="width: ${percent}%;"></div>
          </div>
        </div>
      </div>
    `;
  }

  grid.innerHTML = html;
}

// =============================================================================
// Data Loading Functions
// =============================================================================

/**
 * Load current readings
 */
export async function loadCurrentData() {
  try {
    const data = await fetchJson('/api/plugin/fpp-plugin-watcher/efuse/current');

    if (!data.success) {
      console.error('Failed to load current data:', data.error);
      return;
    }

    state.currentPortData = data.ports || {};
    updateLastUpdateTime('lastUpdate');
    updateStatsBar(data);
    updatePortGrid(data.ports);
    updateTrippedBanner(data.ports);

    // Update port detail panel if a port is selected
    if (state.selectedPort && state.currentPortData[state.selectedPort]) {
      updatePortDetailCurrent(state.currentPortData[state.selectedPort]);
      updatePortDetailControls(state.currentPortData[state.selectedPort]);
    }

  } catch (error) {
    console.error('Error loading current data:', error);
  }
}

/**
 * Show error message in the history charts container
 */
function showHistoryChartsError(message) {
  const container = document.getElementById('efuseHistoryChartsContainer');
  if (!container) return;

  container.innerHTML = `
    <div class="efuseChartCard">
      <div class="efuseChartTitle">
        <span><i class="fas fa-chart-area"></i> Current History</span>
      </div>
      <div class="empty-message">
        <i class="fas fa-exclamation-triangle"></i>
        <h3>Error Loading Data</h3>
        <p>${escapeHtml(message)}</p>
      </div>
    </div>
  `;
}

/**
 * Update peak statistics from heatmap data
 */
function updatePeakStats(data) {
  const totalHistory = data.totalHistory || [];

  // Update labels to reflect selected time range
  const hours = document.getElementById('timeRange')?.value || 24;
  const timeLabel = hours == 1 ? '1h' : hours + 'h';

  const peakLabel = document.getElementById('peakLabel');
  if (peakLabel) {
    peakLabel.textContent = `Peak (${timeLabel})`;
  }

  const avgLabel = document.getElementById('avgLabel');
  if (avgLabel) {
    avgLabel.textContent = `Average (${timeLabel})`;
  }

  // Use total history if available (more accurate)
  if (totalHistory.length > 0) {
    const peakTotal = Math.max(...totalHistory.map(p => p.max || p.value));
    const avgTotal = totalHistory.reduce((sum, p) => sum + p.value, 0) / totalHistory.length;

    const peakElem = document.getElementById('peakCurrent');
    if (peakElem) {
      peakElem.textContent = formatCurrent(peakTotal);
    }

    const avgElem = document.getElementById('avgCurrent');
    if (avgElem) {
      avgElem.textContent = formatCurrent(Math.round(avgTotal));
    }
    return;
  }

  // Fallback: calculate from per-port data
  const peaks = data.peaks || {};
  let maxPeak = 0;
  let totalAvg = 0;
  let avgCount = 0;

  for (const portName in peaks) {
    if (peaks[portName] > maxPeak) {
      maxPeak = peaks[portName];
    }
  }

  const timeSeries = data.timeSeries || {};
  for (const portName in timeSeries) {
    const series = timeSeries[portName];
    if (series.length > 0) {
      const sum = series.reduce((acc, p) => acc + (p.value || 0), 0);
      totalAvg += sum / series.length;
      avgCount++;
    }
  }

  const peakElem = document.getElementById('peakCurrent');
  if (peakElem) {
    peakElem.textContent = formatCurrent(maxPeak);
  }

  const avgElem = document.getElementById('avgCurrent');
  if (avgElem) {
    avgElem.textContent = avgCount > 0 ? formatCurrent(Math.round(totalAvg / avgCount)) : '-- mA';
  }
}

/**
 * Update total current history chart
 */
function updateTotalHistoryChart(data) {
  const totalHistory = data.totalHistory || [];
  const card = document.getElementById('totalHistoryCard');

  if (totalHistory.length === 0) {
    if (card) card.style.display = 'none';
    return;
  }

  if (card) card.style.display = 'block';

  // Convert mA to Amps for display
  const avgData = totalHistory.map(p => ({
    x: new Date(p.timestamp * 1000),
    y: p.value / 1000
  }));

  const minData = totalHistory.map(p => ({
    x: new Date(p.timestamp * 1000),
    y: p.min / 1000
  }));

  const maxData = totalHistory.map(p => ({
    x: new Date(p.timestamp * 1000),
    y: p.max / 1000
  }));

  // Downsample if needed
  const downsampledAvg = downsampleData(avgData, MAX_CHART_POINTS);
  const downsampledMin = downsampleData(minData, MAX_CHART_POINTS);
  const downsampledMax = downsampleData(maxData, MAX_CHART_POINTS);

  const datasets = [
    {
      label: 'Max',
      data: downsampledMax,
      borderColor: 'rgba(220, 53, 69, 0.8)',
      backgroundColor: 'rgba(220, 53, 69, 0.1)',
      fill: '+1',
      borderWidth: 1,
      pointRadius: 0,
      tension: 0
    },
    {
      label: 'Average',
      data: downsampledAvg,
      borderColor: '#3498db',
      backgroundColor: 'rgba(52, 152, 219, 0.1)',
      fill: false,
      borderWidth: 2,
      pointRadius: 0,
      tension: 0
    },
    {
      label: 'Min',
      data: downsampledMin,
      borderColor: 'rgba(40, 167, 69, 0.8)',
      backgroundColor: 'transparent',
      fill: false,
      borderWidth: 1,
      pointRadius: 0,
      tension: 0
    }
  ];

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: {
      mode: 'index',
      intersect: false
    },
    scales: {
      x: {
        type: 'time',
        time: {
          displayFormats: {
            hour: 'HH:mm',
            minute: 'HH:mm'
          }
        }
      },
      y: {
        min: 0,
        title: {
          display: true,
          text: 'Amps'
        }
      }
    },
    plugins: {
      legend: {
        position: 'top',
        labels: { boxWidth: 12, usePointStyle: true }
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            return context.dataset.label + ': ' + context.raw.y.toFixed(2) + ' A';
          }
        }
      }
    }
  };

  updateOrCreateChart(state.charts, 'totalHistory', 'totalHistoryChart', 'line', datasets, chartOptions);
}

/**
 * Update main history charts with all ports (split into groups of 16)
 */
function updateHistoryChart(data) {
  const container = document.getElementById('efuseHistoryChartsContainer');
  if (!container) return;

  const timeSeries = data.timeSeries || {};
  const portCount = state.config?.ports || 16;

  // Ensure skeletons exist
  if (!state.chartSkeletonsCreated) {
    createChartSkeletons(portCount);
  }

  // Get list of ports with data, sorted by port number
  const portsWithData = Object.keys(timeSeries)
    .filter(p => timeSeries[p].length > 0)
    .sort((a, b) => {
      const numA = parseInt(a.replace(/\D/g, '')) || 0;
      const numB = parseInt(b.replace(/\D/g, '')) || 0;
      return numA - numB;
    });

  if (portsWithData.length === 0) {
    const numCharts = Math.ceil(portCount / PORTS_PER_CHART);
    for (let i = 0; i < numCharts; i++) {
      const loading = document.getElementById(`chartLoading_${i}`);
      if (loading) loading.style.display = 'none';
      const chartKey = `history_${i}`;
      if (state.charts[chartKey]) {
        state.charts[chartKey].data.datasets = [];
        state.charts[chartKey].update('none');
      }
    }
    return;
  }

  // Split ports into groups of PORTS_PER_CHART
  const portGroups = [];
  for (let i = 0; i < portsWithData.length; i += PORTS_PER_CHART) {
    portGroups.push(portsWithData.slice(i, i + PORTS_PER_CHART));
  }

  // Update charts progressively using requestAnimationFrame
  let currentGroup = 0;

  function updateNextChart() {
    if (currentGroup >= portGroups.length) return;

    const group = portGroups[currentGroup];
    const groupIndex = currentGroup;
    const chartKey = `history_${groupIndex}`;

    const loading = document.getElementById(`chartLoading_${groupIndex}`);
    if (loading) loading.style.display = 'none';

    const datasets = group.map((portName, index) => {
      const series = timeSeries[portName];
      const color = getEfuseChartColor(index);

      let chartData = series.map(p => ({
        x: new Date(p.timestamp * 1000),
        y: p.value ?? 0
      }));
      chartData = downsampleData(chartData, MAX_CHART_POINTS);

      return {
        label: portName,
        data: chartData,
        borderColor: color,
        backgroundColor: color + '20',
        fill: false,
        tension: 0,
        pointRadius: 0
      };
    });

    const chartId = `efuseHistoryChart_${groupIndex}`;
    const options = {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: 'index', intersect: false },
      scales: {
        x: {
          type: 'time',
          time: { displayFormats: { hour: 'HH:mm', minute: 'HH:mm' } },
          title: { display: false }
        },
        y: {
          min: 0,
          title: { display: true, text: 'mA' }
        }
      },
      plugins: {
        legend: {
          position: 'top',
          labels: { boxWidth: 12, usePointStyle: true }
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              return context.dataset.label + ': ' + formatCurrent(context.raw.y);
            }
          }
        }
      }
    };

    updateOrCreateChart(state.charts, chartKey, chartId, 'line', datasets, options);

    currentGroup++;
    if (currentGroup < portGroups.length) {
      requestAnimationFrame(updateNextChart);
    }
  }

  requestAnimationFrame(updateNextChart);
}

/**
 * Load heatmap/history data
 */
export async function loadHeatmapData() {
  const hours = document.getElementById('timeRange')?.value || 24;

  try {
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/efuse/heatmap?hours=${hours}`);

    if (!data.success) {
      console.error('Failed to load heatmap data:', data.error);
      showHistoryChartsError('Failed to load data');
      return;
    }

    try {
      updateHistoryChart(data);
      updateTotalHistoryChart(data);
      updatePeakStats(data);
    } catch (chartError) {
      console.error('Error updating chart:', chartError);
      showHistoryChartsError('Error rendering chart');
    }

  } catch (error) {
    console.error('Error loading heatmap data:', error);
    showHistoryChartsError('Network error');
  }
}

/**
 * Update port history chart
 */
function updatePortHistoryChart(data) {
  const history = data.history || [];

  const chartData = history.map(h => ({
    x: new Date(h.timestamp * 1000),
    y: h.avg ?? h.value ?? 0
  }));

  const maxData = history.map(h => ({
    x: new Date(h.timestamp * 1000),
    y: h.max ?? h.value ?? 0
  }));

  const datasets = [
    {
      label: 'Average',
      data: chartData,
      borderColor: '#4e9f3d',
      backgroundColor: 'rgba(78, 159, 61, 0.1)',
      fill: true,
      tension: 0,
      pointRadius: 0
    },
    {
      label: 'Max',
      data: maxData,
      borderColor: '#dc3545',
      borderDash: [5, 5],
      fill: false,
      tension: 0,
      pointRadius: 0
    }
  ];

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: {
      mode: 'index',
      intersect: false
    },
    scales: {
      x: {
        type: 'time',
        time: {
          displayFormats: {
            hour: 'HH:mm',
            minute: 'HH:mm'
          }
        },
        title: { display: false }
      },
      y: {
        min: 0,
        title: {
          display: true,
          text: 'mA'
        }
      }
    },
    plugins: {
      legend: {
        position: 'top'
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            return context.dataset.label + ': ' + formatCurrent(context.raw.y);
          }
        }
      }
    }
  };

  updateOrCreateChart(state.charts, 'portHistory', 'portHistoryChart', 'line', datasets, chartOptions);
}

/**
 * Load port history for detail view
 */
async function loadPortHistory(portName) {
  const hours = document.getElementById('timeRange')?.value || 24;

  try {
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/efuse/history?port=${encodeURIComponent(portName)}&hours=${hours}`);

    if (!data.success) {
      console.error('Failed to load port history:', data.error);
      return;
    }

    const history = data.history || [];
    if (history.length > 0) {
      let peak = 0;
      let sum = 0;

      history.forEach(h => {
        const val = h.max || h.value || 0;
        if (val > peak) peak = val;
        sum += h.avg || h.value || 0;
      });

      document.getElementById('portDetailPeak').textContent = formatCurrent(peak);
      document.getElementById('portDetailAvg').textContent = formatCurrent(Math.round(sum / history.length));
    }

    updatePortHistoryChart(data);

    if (data.config) {
      state.selectedPortConfig = data.config;
      const portData = state.currentPortData[portName] || {};
      const mergedData = { ...portData, ...data.config };
      updatePortOutputConfig(mergedData);
    }

  } catch (error) {
    console.error('Error loading port history:', error);
  }
}

// =============================================================================
// Port Detail Panel
// =============================================================================

/**
 * Show port detail panel
 */
export async function showPortDetail(portName) {
  state.selectedPort = portName;

  // Update grid to show selected state
  updatePortGrid(state.currentPortData);

  const panel = document.getElementById('portDetailPanel');
  if (!panel) return;

  document.getElementById('portDetailName').textContent = portName;

  const portData = state.currentPortData[portName] || {};
  document.getElementById('portDetailCurrent').textContent = formatCurrent(portData.currentMa);
  document.getElementById('portDetailExpected').textContent = formatCurrent(portData.expectedCurrentMa);

  updatePortDetailControls(portData);
  panel.style.display = 'block';

  await loadPortHistory(portName);
}

/**
 * Close port detail panel
 */
export function closePortDetail() {
  const panel = document.getElementById('portDetailPanel');
  if (panel) {
    panel.style.display = 'none';
  }
  state.selectedPort = null;
  state.selectedPortConfig = null;
}

// =============================================================================
// Help Modals
// =============================================================================

/**
 * Show expected current help modal
 */
export function showExpectedHelp(event) {
  if (event) event.stopPropagation();
  const modal = document.getElementById('expectedHelpModal');
  if (modal) modal.style.display = 'flex';
}

/**
 * Hide expected current help modal
 */
export function hideExpectedHelp() {
  const modal = document.getElementById('expectedHelpModal');
  if (modal) modal.style.display = 'none';
}

/**
 * Show page help modal
 */
export function showPageHelp(event) {
  if (event) event.stopPropagation();
  const modal = document.getElementById('pageHelpModal');
  if (modal) modal.style.display = 'flex';
}

/**
 * Hide page help modal
 */
export function hidePageHelp() {
  const modal = document.getElementById('pageHelpModal');
  if (modal) modal.style.display = 'none';
}

// =============================================================================
// Initialization and Cleanup
// =============================================================================

/**
 * Load/refresh all data
 */
export function loadAllData() {
  loadCurrentData();
  loadHeatmapData();

  if (state.selectedPort) {
    loadPortHistory(state.selectedPort);
  }
}

/**
 * Initialize the eFuse monitor
 */
function initEfuseMonitor() {
  const portCount = state.config?.ports || 16;
  createChartSkeletons(portCount);

  loadCurrentData().then(() => {
    const portNames = Object.keys(state.currentPortData);
    if (portNames.length > 0) {
      const defaultPort = state.currentPortData['Port 1'] ? 'Port 1' : portNames.sort((a, b) => {
        const numA = parseInt(a.replace(/\D/g, '')) || 0;
        const numB = parseInt(b.replace(/\D/g, '')) || 0;
        return numA - numB;
      })[0];
      showPortDetail(defaultPort);
    }
  }).catch(err => {
    console.error('Error in initial load:', err);
  });
  loadHeatmapData();

  // Auto-refresh: current data every 10 seconds, history charts every 60 seconds
  let refreshCount = 0;
  state.refreshInterval = setInterval(() => {
    refreshCount++;
    loadCurrentData();
    if (refreshCount % 6 === 0) {
      loadHeatmapData();
      if (state.selectedPort) {
        loadPortHistory(state.selectedPort);
      }
    }
  }, AUTO_REFRESH_INTERVAL_MS);
}

// =============================================================================
// Public Page Interface
// =============================================================================

/**
 * eFuse Monitor page module
 * Follows the standard page interface pattern
 */
export const efuseMonitor = {
  pageId: 'efuseMonitorUI',

  /**
   * Initialize the page with config from PHP
   * @param {Object} config - Configuration from watcherConfig
   */
  init(config) {
    state.config = config;
    initEfuseMonitor();
  },

  /**
   * Cleanup and destroy the page
   */
  destroy() {
    if (state.refreshInterval) {
      clearInterval(state.refreshInterval);
    }
    Object.values(state.charts).forEach(c => c.destroy());
    state = {
      charts: {},
      currentPortData: {},
      selectedPort: null,
      selectedPortConfig: null,
      refreshInterval: null,
      chartSkeletonsCreated: false,
      lastControlAction: 0,
      confirmModalCallback: null,
      controlCapabilities: null,
      config: {}
    };
  },

  // Public methods for onclick handlers
  refresh: loadAllData,  // Alias for standard page interface
  loadAllData,
  loadCurrentData,
  loadHeatmapData,
  showPortDetail,
  closePortDetail,
  togglePort,
  resetFuse,
  masterControl,
  resetAllTripped,
  toggleSelectedPort,
  resetSelectedPort,
  portPowerClick,
  showExpectedHelp,
  hideExpectedHelp,
  showPageHelp,
  hidePageHelp,
  showConfirmModal,
  hideConfirmModal,
  executeConfirmAction,
  confirmModalCallback: executeConfirmAction,  // Alias for PHP onclick handler
  showToast,

  // Utility functions that tests might need
  formatCurrent,
  getEfuseColor,
  getEfuseChartColor,
  downsampleData
};

export default efuseMonitor;
