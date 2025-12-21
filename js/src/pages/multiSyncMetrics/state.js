/**
 * multiSyncMetrics/state.js - State management for Multi-Sync Dashboard
 *
 * Manages all module state including:
 * - Configuration from PHP
 * - Polling intervals
 * - Chart instances
 * - Cached data
 * - Consecutive failure tracking
 */

// Polling intervals
export const FAST_POLL_INTERVAL = 2000;  // Real-time data: 2 seconds
export const SLOW_POLL_INTERVAL = 30000; // Static data: 30 seconds

// Consecutive failure threshold (matches FPP's multisync.php which uses 4)
// We use 3 to prevent UI flickering from transient network issues
export const CONSECUTIVE_FAILURE_THRESHOLD = 3;

/**
 * Module state object
 * Contains all mutable state for the multi-sync dashboard
 */
export const state = {
  // Configuration from PHP
  config: {
    isPlayerMode: false,
    isRemoteMode: false,
    remoteSystems: [],
    localHostname: '',
    multiSyncPingEnabled: false
  },

  // Polling interval handles
  fastRefreshInterval: null,
  slowRefreshInterval: null,

  // Chart.js instances (keyed by chart name)
  charts: {},

  // Cached data
  localStatus: null,
  localFppStatus: null,
  fppSystems: [],
  systemsData: [],
  sequenceMeta: null,
  lastSequenceName: null,
  clockDriftData: {},

  // Table sorting state
  currentSort: { column: 'hostname', direction: 'asc' },

  // Slow data loading flag
  slowDataLoaded: false,

  // Remote mode packet rate tracking
  lastPacketCount: 0,
  lastPacketTime: null,
  packetRate: 0,

  // Consecutive failure tracking
  // Map of address -> failure count
  consecutiveFailures: {},
  // Map of address -> last good remote data
  lastKnownGoodState: {}
};

/**
 * Initialize state with configuration from PHP
 * @param {Object} config - Configuration object from window.watcherConfig
 */
export function initState(config) {
  state.config = {
    isPlayerMode: config.isPlayerMode || false,
    isRemoteMode: config.isRemoteMode || false,
    remoteSystems: config.remoteSystems || [],
    localHostname: config.localHostname || '',
    multiSyncPingEnabled: config.multiSyncPingEnabled || false
  };
}

/**
 * Reset all state to initial values
 * Called on destroy() to clean up
 */
export function resetState() {
  // Clear intervals
  if (state.fastRefreshInterval) {
    clearInterval(state.fastRefreshInterval);
    state.fastRefreshInterval = null;
  }
  if (state.slowRefreshInterval) {
    clearInterval(state.slowRefreshInterval);
    state.slowRefreshInterval = null;
  }

  // Destroy all charts
  Object.values(state.charts).forEach(chart => chart?.destroy?.());
  state.charts = {};

  // Reset cached data
  state.localStatus = null;
  state.localFppStatus = null;
  state.fppSystems = [];
  state.systemsData = [];
  state.sequenceMeta = null;
  state.lastSequenceName = null;
  state.clockDriftData = {};

  // Reset table sort
  state.currentSort = { column: 'hostname', direction: 'asc' };

  // Reset flags
  state.slowDataLoaded = false;

  // Reset remote mode tracking
  state.lastPacketCount = 0;
  state.lastPacketTime = null;
  state.packetRate = 0;

  // Reset failure tracking
  state.consecutiveFailures = {};
  state.lastKnownGoodState = {};
}

/**
 * Get whether we're in player mode
 * @returns {boolean}
 */
export function isPlayerMode() {
  return state.config.isPlayerMode;
}

/**
 * Get whether we're in remote mode
 * @returns {boolean}
 */
export function isRemoteMode() {
  return state.config.isRemoteMode;
}

/**
 * Get the local hostname
 * @returns {string}
 */
export function getLocalHostname() {
  return state.config.localHostname;
}

/**
 * Get whether multi-sync ping (remote ping) is enabled
 * @returns {boolean}
 */
export function isMultiSyncPingEnabled() {
  return state.config.multiSyncPingEnabled;
}
