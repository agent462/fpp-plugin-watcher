/**
 * Core module exports
 *
 * Re-exports all core utilities for convenient importing
 */

// Utils module
export {
  utils,
  escapeHtml,
  showElement,
  hideElement,
  setLoading,
  formatBytes,
  formatLatency,
  formatPercent,
  formatDuration,
  toFahrenheit,
  getTempUnit,
  formatTemp,
  getTempStatus,
  formatThermalZoneName,
  updateLastUpdateTime,
} from './utils.js';

// Charts module
export {
  charts,
  CHART_COLORS,
  getChartColor,
  getTimeUnit,
  getTimeFormats,
  createDataset,
  mapChartData,
  buildChartOptions,
  updateOrCreateChart,
} from './charts.js';

// API module
export {
  api,
  fetchJson,
  withButtonLoading,
  createRefreshController,
  loadTemperaturePreference,
} from './api.js';
