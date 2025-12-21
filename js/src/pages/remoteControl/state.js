/**
 * Remote Control Page - State Management
 *
 * Centralized state management for the remote control dashboard.
 * Includes data source configuration, caches, and host tracking maps.
 */

// =============================================================================
// Data Source Configuration
// =============================================================================

/**
 * Data source intervals and last fetch timestamps.
 * Bulk endpoints reduce API calls by ~90%.
 */
export const DATA_SOURCES = {
  // Bulk status: fppd/status + system/status + connectivity (all remotes)
  bulkStatus: { interval: 10000, lastFetch: 0 },
  // Bulk updates: watcher version + plugin updates (all remotes)
  bulkUpdates: { interval: 60000, lastFetch: 0 },
  // Local-only data sources
  localStatus: { interval: 10000, lastFetch: 0 },
  localSysStatus: { interval: 30000, lastFetch: 0 },
  localConnectivity: { interval: 30000, lastFetch: 0 },
  localVersion: { interval: 60000, lastFetch: 0 },
  localUpdates: { interval: 60000, lastFetch: 0 },
  // Global sources
  discrepancies: { interval: 60000, lastFetch: 0 },
  fppRelease: { interval: 60000, lastFetch: 0 }
};

// =============================================================================
// Caches
// =============================================================================

/** Cached data from bulk status endpoint (keyed by address) */
export const bulkStatusCache = new Map();

/** Cached data from bulk updates endpoint (keyed by address) */
export const bulkUpdatesCache = new Map();

/** Local host cache */
export const localCache = {
  status: null,
  testMode: null,
  sysStatus: null,
  connectivity: null,
  version: null,
  updates: []
};

// =============================================================================
// Host Tracking Maps
// =============================================================================

/** Hosts with Watcher plugin updates available */
export const hostsWithWatcherUpdates = new Map();

/** Hosts with non-Watcher plugin updates available */
export const hostsWithOtherPluginUpdates = new Map();

/** Hosts needing restart or reboot */
export const hostsNeedingRestart = new Map();

/** Hosts with FPP version updates available */
export const hostsWithFPPUpdates = new Map();

/** Hosts with connectivity failures */
export const hostsWithConnectivityFailure = new Map();

// =============================================================================
// Global State
// =============================================================================

/** Flag to prevent concurrent refresh operations */
export let isRefreshing = false;

/** Pending reboot confirmation data */
export let pendingReboot = null;

/** Current bulk operation type */
export let currentBulkType = null;

/** Cached latest FPP release from GitHub */
export let latestFPPRelease = null;

// Setters for global state (needed because ES module exports are read-only bindings)
export function setIsRefreshing(value) {
  isRefreshing = value;
}

export function setPendingReboot(value) {
  pendingReboot = value;
}

export function setCurrentBulkType(value) {
  currentBulkType = value;
}

export function setLatestFPPRelease(value) {
  latestFPPRelease = value;
}

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Check if a data source should be fetched based on its interval
 * @param {string} source - Data source name
 * @returns {boolean} - True if enough time has passed since last fetch
 */
export function shouldFetch(source) {
  const now = Date.now();
  const config = DATA_SOURCES[source];
  if (!config) return true;
  return now - config.lastFetch >= config.interval;
}

/**
 * Mark a data source as fetched (update lastFetch timestamp)
 * @param {string} source - Data source name
 */
export function markFetched(source) {
  if (DATA_SOURCES[source]) {
    DATA_SOURCES[source].lastFetch = Date.now();
  }
}

/**
 * Invalidate cache for a data source (force next fetch)
 * @param {string} source - Data source name
 */
export function invalidateCache(source) {
  if (DATA_SOURCES[source]) {
    DATA_SOURCES[source].lastFetch = 0;
  }
}

// =============================================================================
// Configuration from PHP
// =============================================================================

/** Configuration passed from PHP via window.watcherConfig */
export let config = {
  remoteAddresses: [],
  remoteHostnames: {},
  localHostname: 'localhost'
};

/**
 * Initialize config from window.watcherConfig
 * @param {Object} cfg - Configuration object from PHP
 */
export function initConfig(cfg) {
  // Update properties instead of reassigning to preserve exported reference
  config.remoteAddresses = cfg?.remoteAddresses || [];
  config.remoteHostnames = cfg?.remoteHostnames || {};
  config.localHostname = cfg?.localHostname || 'localhost';
}

// =============================================================================
// Reset Functions
// =============================================================================

/**
 * Reset all state to initial values.
 * Called when the page module is destroyed.
 */
export function resetState() {
  // Reset data source timestamps
  Object.keys(DATA_SOURCES).forEach(key => {
    DATA_SOURCES[key].lastFetch = 0;
  });

  // Clear caches
  bulkStatusCache.clear();
  bulkUpdatesCache.clear();
  localCache.status = null;
  localCache.testMode = null;
  localCache.sysStatus = null;
  localCache.connectivity = null;
  localCache.version = null;
  localCache.updates = [];

  // Clear host tracking maps
  hostsWithWatcherUpdates.clear();
  hostsWithOtherPluginUpdates.clear();
  hostsNeedingRestart.clear();
  hostsWithFPPUpdates.clear();
  hostsWithConnectivityFailure.clear();

  // Reset global state
  isRefreshing = false;
  pendingReboot = null;
  currentBulkType = null;
  latestFPPRelease = null;
}

// =============================================================================
// Utility Functions
// =============================================================================

/**
 * Escape address for use in DOM IDs (replace dots with dashes)
 * @param {string} address - IP address or hostname
 * @returns {string} - Safe ID string
 */
export function escapeId(address) {
  return address.replace(/\./g, '-');
}

/**
 * Get hostname for an address
 * @param {string} address - IP address or 'localhost'
 * @returns {string} - Hostname
 */
export function getHostname(address) {
  if (address === 'localhost') {
    return config.localHostname;
  }
  return config.remoteHostnames[address] || address;
}
