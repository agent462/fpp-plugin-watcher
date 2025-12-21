/**
 * multiSyncMetrics/charts.js - Network quality charts
 *
 * Handles rendering and updating Chart.js charts for:
 * - Latency and jitter over time
 * - Packet loss over time
 * - Network quality summary card
 */

import { state, isMultiSyncPingEnabled } from './state.js';
import { applyQualityClass } from './utils.js';
import { updateOrCreateChart } from '../../core/charts.js';

/**
 * Render or update the latency/jitter dual-axis chart
 * @param {Object} chartData - Chart data from API
 */
export function renderLatencyJitterChart(chartData) {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    return;
  }

  const labels = chartData.labels.map(ts => new Date(ts));

  const datasets = [
    {
      label: 'Latency (ms)',
      data: labels.map((ts, i) => ({ x: ts, y: chartData.latency[i] })),
      borderColor: '#17a2b8',
      backgroundColor: 'rgba(23, 162, 184, 0.1)',
      fill: true,
      tension: 0.3
    },
    {
      label: 'Jitter (ms)',
      data: labels.map((ts, i) => ({ x: ts, y: chartData.jitter[i] })),
      borderColor: '#ffc107',
      backgroundColor: 'rgba(255, 193, 7, 0.1)',
      fill: false,
      tension: 0.3
    }
  ];

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: {
      mode: 'index',
      intersect: false
    },
    plugins: {
      legend: {
        display: true,
        position: 'top'
      }
    },
    scales: {
      x: {
        type: 'time',
        time: {
          displayFormats: {
            minute: 'HH:mm',
            hour: 'HH:mm'
          }
        },
        ticks: {
          maxTicksLimit: 8
        }
      },
      y: {
        beginAtZero: true,
        title: {
          display: true,
          text: 'Milliseconds'
        }
      }
    }
  };

  updateOrCreateChart(state.charts, 'latencyJitter', 'latencyJitterChart', 'line', datasets, options);
}

/**
 * Render or update the packet loss chart
 * @param {Object} chartData - Chart data from API
 */
export function renderPacketLossChart(chartData) {
  // Check if Chart.js is available
  if (typeof Chart === 'undefined') {
    console.error('Chart.js is not loaded');
    return;
  }

  const labels = chartData.labels.map(ts => new Date(ts));
  const lossData = chartData.packetLoss;

  // Calculate appropriate Y-axis max based on data
  // Filter out nulls and find max value
  const validValues = lossData.filter(v => v !== null && v !== undefined);
  const maxValue = validValues.length > 0 ? Math.max(...validValues) : 0;
  // Round up to nearest 5 or 10 for cleaner axis, minimum of 5
  const yMax = maxValue <= 5 ? 5 : Math.ceil(maxValue / 5) * 5;

  const datasets = [
    {
      label: 'Packet Loss (%)',
      data: labels.map((ts, i) => ({ x: ts, y: lossData[i] })),
      borderColor: '#dc3545',
      backgroundColor: 'rgba(220, 53, 69, 0.1)',
      fill: true,
      tension: 0.3
    }
  ];

  const options = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
      legend: {
        display: true,
        position: 'top'
      }
    },
    scales: {
      x: {
        type: 'time',
        time: {
          displayFormats: {
            minute: 'HH:mm',
            hour: 'HH:mm'
          }
        },
        ticks: {
          maxTicksLimit: 8
        }
      },
      y: {
        beginAtZero: true,
        max: yMax,
        title: {
          display: true,
          text: 'Packet Loss %'
        }
      }
    }
  };

  updateOrCreateChart(state.charts, 'packetLoss', 'packetLossChart', 'line', datasets, options);
}

/**
 * Update the network quality summary card
 * @param {Object} data - Network quality data from API
 */
export function updateQualityCard(data) {
  const summary = data.summary || {};

  // Update quality indicator
  const qualityEl = document.getElementById('overallQuality');
  if (qualityEl) {
    const quality = summary.overallQuality || 'unknown';
    qualityEl.textContent = quality.charAt(0).toUpperCase() + quality.slice(1);
    qualityEl.className = 'msm-quality-indicator msm-quality-' + quality;
  }

  // Update metrics
  const latency = summary.avgLatency;
  const latencyEl = document.getElementById('qualityLatency');
  if (latencyEl) {
    latencyEl.textContent = latency !== null ? latency + 'ms' : '--';
  }

  const jitter = summary.avgJitter;
  const jitterEl = document.getElementById('qualityJitter');
  if (jitterEl) {
    jitterEl.textContent = jitter !== null ? jitter + 'ms' : '--';
  }

  const packetLoss = summary.avgPacketLoss;
  const packetLossEl = document.getElementById('qualityPacketLoss');
  if (packetLossEl) {
    packetLossEl.textContent = packetLoss !== null ? packetLoss + '%' : '--';
  }

  // Apply quality colors to values
  if (data.hosts && data.hosts.length > 0) {
    const host = data.hosts[0]; // Use first host for now
    applyQualityClass('qualityLatency', host.latency_quality);
    applyQualityClass('qualityJitter', host.jitter_quality);
    applyQualityClass('qualityPacketLoss', host.packet_loss_quality);
  }
}

/**
 * Show "Remote Ping Disabled" message in the network quality section
 * Replaces the chart canvases with an informational message
 */
export function showPingDisabledMessage() {
  const message = `
    <div class="msm-ping-disabled">
      <i class="fas fa-info-circle"></i>
      <div>
        <strong>Remote Ping is disabled</strong>
        <p>Enable "Remote Ping" in <a href="plugin.php?plugin=fpp-plugin-watcher&page=configUI.php">Plugin Settings</a> to collect network quality metrics.</p>
      </div>
    </div>
  `;

  // Replace latency/jitter chart canvas with message
  const latencyCanvas = document.getElementById('latencyJitterChart');
  if (latencyCanvas) {
    const parent = latencyCanvas.parentElement;
    if (parent) {
      parent.innerHTML = message;
    }
  }

  // Replace packet loss chart canvas with message
  const packetLossCanvas = document.getElementById('packetLossChart');
  if (packetLossCanvas) {
    const parent = packetLossCanvas.parentElement;
    if (parent) {
      parent.innerHTML = message;
    }
  }

  // Also update quality card to show disabled state
  const qualityEl = document.getElementById('overallQuality');
  if (qualityEl) {
    qualityEl.textContent = 'Disabled';
    qualityEl.className = 'msm-quality-indicator msm-quality-unknown';
  }

  // Clear metric values
  ['qualityLatency', 'qualityJitter', 'qualityPacketLoss'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = '--';
      el.className = el.className.replace(/msm-quality-\w+/g, '').trim();
    }
  });
}

/**
 * Check if ping is enabled and return status
 * @returns {boolean} True if ping is enabled
 */
export function isPingEnabled() {
  return isMultiSyncPingEnabled();
}
