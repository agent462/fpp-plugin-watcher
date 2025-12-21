/**
 * multiSyncMetrics/index.js - Main entry point for Multi-Sync Dashboard
 *
 * Provides the public interface for the multi-sync metrics page module.
 * Handles initialization, cleanup, and exposes all public functions.
 */

import { state, initState, resetState, FAST_POLL_INTERVAL, SLOW_POLL_INTERVAL } from './state.js';
import { toggleHelpTooltip, handleClickOutside } from './utils.js';
import { loadFastData, loadSlowData, loadAllData, resetMetrics, loadQualityCharts } from './api.js';

/**
 * Multi-Sync Metrics Page Module
 *
 * Provides a comprehensive multi-sync dashboard showing:
 * - Local sync metrics from C++ plugin
 * - Player vs remote comparison
 * - Real-time sync status and issues
 * - Network quality charts
 */
export const multiSyncMetrics = {
  pageId: 'multiSyncMetricsUI',

  /**
   * Initialize the page
   * @param {Object} config - Configuration from window.watcherConfig
   */
  init(config) {
    // Initialize state with config
    initState(config);

    // Setup click-outside handler for help tooltip
    document.addEventListener('click', handleClickOutside);

    // Initial load - loads both slow and fast data
    loadAllData();

    // Start dual polling intervals
    state.fastRefreshInterval = setInterval(loadFastData, FAST_POLL_INTERVAL);
    state.slowRefreshInterval = setInterval(loadSlowData, SLOW_POLL_INTERVAL);
  },

  /**
   * Cleanup on page unload
   */
  destroy() {
    document.removeEventListener('click', handleClickOutside);
    resetState();
  },

  /**
   * Manual refresh - reload all data
   */
  refresh: loadAllData,

  /**
   * Reset all metrics on local and remote systems
   */
  resetMetrics,

  /**
   * Toggle help tooltip visibility
   */
  toggleHelpTooltip,

  /**
   * Reload quality charts with current time range
   */
  loadQualityCharts
};

export default multiSyncMetrics;
