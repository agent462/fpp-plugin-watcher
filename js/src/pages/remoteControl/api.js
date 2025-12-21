/**
 * Remote Control Page - API Module
 *
 * Data fetching functions for bulk status, updates, and local system data.
 * Includes FPP version parsing and comparison utilities.
 */

import {
  shouldFetch,
  markFetched,
  bulkStatusCache,
  bulkUpdatesCache,
  localCache,
  config,
  latestFPPRelease,
  setLatestFPPRelease,
  DATA_SOURCES
} from './state.js';

// =============================================================================
// FPP Version Utilities
// =============================================================================

/**
 * Parse FPP version string into [major, minor] array
 * @param {string} version - Version string like "v9.2" or "9.2"
 * @returns {number[]} - [major, minor]
 */
export function parseFPPVersion(version) {
  if (!version) return [0, 0];
  const v = version.replace(/^v/, '');
  const match = v.match(/^(\d+)\.(\d+)/);
  return match ? [parseInt(match[1]), parseInt(match[2])] : [0, 0];
}

/**
 * Compare two FPP versions
 * @param {string} current - Current version
 * @param {string} latest - Latest version
 * @returns {number} - -1 if current < latest, 0 if equal, 1 if current > latest
 */
export function compareFPPVersions(current, latest) {
  const [curMajor, curMinor] = parseFPPVersion(current);
  const [latMajor, latMinor] = parseFPPVersion(latest);
  if (curMajor < latMajor) return -1;
  if (curMajor > latMajor) return 1;
  if (curMinor < latMinor) return -1;
  if (curMinor > latMinor) return 1;
  return 0;
}

/**
 * Check if a cross-version upgrade is available
 * @param {string} branch - Current branch/version
 * @returns {Object|null} - Upgrade info or null
 */
export function checkCrossVersionUpgrade(branch) {
  if (!latestFPPRelease || !latestFPPRelease.latestVersion) return null;
  const comparison = compareFPPVersions(branch, latestFPPRelease.latestVersion);
  if (comparison < 0) {
    const [curMajor] = parseFPPVersion(branch);
    const [latMajor] = parseFPPVersion(latestFPPRelease.latestVersion);
    const isMajorUpgrade = latMajor > curMajor;

    return {
      available: true,
      currentVersion: branch ? branch.replace(/^v/, '') : 'unknown',
      latestVersion: latestFPPRelease.latestVersion,
      isMajorUpgrade
    };
  }
  return null;
}

// =============================================================================
// System Status Parsing
// =============================================================================

/**
 * Parse system status response for utilization metrics and IP
 * @param {Object} sysStatus - System status response
 * @returns {Object} - Parsed status info
 */
export function parseSystemStatus(sysStatus) {
  const result = {
    fppLocalVersion: null,
    fppRemoteVersion: null,
    diskUtilization: null,
    cpuUtilization: null,
    memoryUtilization: null,
    ipAddress: null
  };

  if (!sysStatus?.advancedView) return result;

  result.fppLocalVersion = sysStatus.advancedView.LocalGitVersion || null;
  result.fppRemoteVersion = sysStatus.advancedView.RemoteGitVersion || null;

  // Get primary IP address
  const ips = sysStatus.advancedView.IPs;
  if (ips && typeof ips === 'object') {
    result.ipAddress = ips.eth0 || ips.wlan0 || Object.values(ips)[0] || null;
  }

  const utilization = sysStatus.advancedView.Utilization;
  if (utilization) {
    const diskInfo = utilization.Disk?.Root;
    if (diskInfo?.Total > 0) {
      result.diskUtilization = Math.round(((diskInfo.Total - diskInfo.Free) / diskInfo.Total) * 100);
    }
    if (typeof utilization.CPU === 'number') result.cpuUtilization = Math.round(utilization.CPU);
    if (typeof utilization.Memory === 'number') result.memoryUtilization = Math.round(utilization.Memory);
  }

  return result;
}

// =============================================================================
// FPP Release Fetching
// =============================================================================

/**
 * Fetch latest FPP release from GitHub (cached)
 * @returns {Promise<Object|null>} - Release info or null
 */
export async function fetchLatestFPPRelease() {
  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/fpp/release');
    if (!response.ok) return null;
    const data = await response.json();
    if (data.success) {
      setLatestFPPRelease(data);
      return data;
    }
  } catch (e) {
    console.log('Failed to fetch latest FPP release:', e);
  }
  return null;
}

// =============================================================================
// Bulk Data Fetching
// =============================================================================

/**
 * Fetch bulk status for all remote hosts
 * Includes: fppd/status + system/status + connectivity
 */
export async function fetchBulkStatus() {
  if (!shouldFetch('bulkStatus')) return;
  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/bulk/status');
    if (!response.ok) return;
    const data = await response.json();
    if (data.success && data.hosts) {
      for (const [address, hostData] of Object.entries(data.hosts)) {
        bulkStatusCache.set(address, hostData);
      }
    }
    markFetched('bulkStatus');
  } catch (e) {
    console.log('Failed to fetch bulk status:', e);
  }
}

/**
 * Fetch bulk updates for all remote hosts
 * Includes: watcher version + plugin updates
 */
export async function fetchBulkUpdates() {
  if (!shouldFetch('bulkUpdates')) return;
  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/bulk/updates');
    if (!response.ok) return;
    const data = await response.json();
    if (data.success && data.hosts) {
      for (const [address, hostData] of Object.entries(data.hosts)) {
        bulkUpdatesCache.set(address, hostData);
      }
    }
    markFetched('bulkUpdates');
  } catch (e) {
    console.log('Failed to fetch bulk updates:', e);
  }
}

// =============================================================================
// Local Status Fetching
// =============================================================================

/**
 * Fetch all local system status data
 * Uses individual endpoints with different intervals
 */
export async function fetchLocalStatus() {
  try {
    // Fetch status and test mode (always at 10s interval)
    if (shouldFetch('localStatus')) {
      const [statusResponse, testModeResponse] = await Promise.all([
        fetch('/api/fppd/status'),
        fetch('/api/testmode')
      ]);

      if (statusResponse.ok) {
        const fppStatus = await statusResponse.json();
        localCache.status = {
          platform: fppStatus.platform || '--',
          branch: fppStatus.branch || '--',
          mode_name: fppStatus.mode_name || '--',
          status_name: fppStatus.status_name || 'idle',
          rebootFlag: fppStatus.rebootFlag || 0,
          restartFlag: fppStatus.restartFlag || 0,
          warnings: fppStatus.warnings || []
        };
      }

      if (testModeResponse.ok) {
        const testModeData = await testModeResponse.json();
        localCache.testMode = { enabled: testModeData.enabled ? 1 : 0 };
      } else {
        localCache.testMode = { enabled: 0 };
      }
      markFetched('localStatus');
    }

    // Fetch sysStatus (30s interval)
    if (shouldFetch('localSysStatus')) {
      const sysResponse = await fetch('/api/system/status');
      if (sysResponse.ok) {
        const sysData = await sysResponse.json();
        localCache.sysStatus = parseSystemStatus(sysData);
      }
      markFetched('localSysStatus');
    }

    // Fetch connectivity (30s interval)
    if (shouldFetch('localConnectivity')) {
      const connResponse = await fetch('/api/plugin/fpp-plugin-watcher/connectivity/state');
      if (connResponse.ok) {
        const connData = await connResponse.json();
        localCache.connectivity = (connData.success && connData.hasResetAdapter) ? connData : null;
      }
      markFetched('localConnectivity');
    }

    // Fetch version (60s interval)
    if (shouldFetch('localVersion')) {
      const versionResponse = await fetch('/api/plugin/fpp-plugin-watcher/version');
      if (versionResponse.ok) {
        const versionData = await versionResponse.json();
        localCache.version = versionData.version || null;
      }
      markFetched('localVersion');
    }

    // Fetch updates (60s interval)
    if (shouldFetch('localUpdates')) {
      const updatesResponse = await fetch('/api/plugin/fpp-plugin-watcher/plugins/updates');
      if (updatesResponse.ok) {
        const updatesData = await updatesResponse.json();
        localCache.updates = (updatesData.success && updatesData.updatesAvailable) ? updatesData.updatesAvailable : [];
      }
      markFetched('localUpdates');
    }
  } catch (e) {
    console.log('Failed to fetch local status:', e);
  }
}

// =============================================================================
// Card Data Building
// =============================================================================

/**
 * Build card data from cached bulk data for a remote host
 * @param {string} address - Host address
 * @returns {Object} - Card data object
 */
export function getRemoteCardData(address) {
  const statusData = bulkStatusCache.get(address);
  const updatesData = bulkUpdatesCache.get(address);

  if (!statusData || !statusData.success) {
    return { success: false, address, error: statusData?.error || 'No data available' };
  }

  const sysInfo = statusData.sysStatus ? parseSystemStatus(statusData.sysStatus) : {
    fppLocalVersion: null,
    fppRemoteVersion: null,
    diskUtilization: null,
    cpuUtilization: null,
    memoryUtilization: null,
    ipAddress: null
  };

  const connectivityState = (statusData.connectivity && statusData.connectivity.hasResetAdapter)
    ? statusData.connectivity : null;

  return {
    success: true,
    address,
    status: statusData.status,
    testMode: statusData.testMode,
    watcherVersion: updatesData?.version || null,
    pluginUpdates: updatesData?.updates || [],
    connectivityState,
    ...sysInfo
  };
}

/**
 * Build card data for localhost from cache
 * @returns {Object} - Card data object
 */
export function getLocalCardData() {
  if (!localCache.status) {
    return { success: false, address: 'localhost', error: 'No data available' };
  }

  return {
    success: true,
    address: 'localhost',
    status: localCache.status,
    testMode: localCache.testMode,
    watcherVersion: localCache.version,
    pluginUpdates: localCache.updates,
    connectivityState: localCache.connectivity,
    ...(localCache.sysStatus || {})
  };
}

// =============================================================================
// Single Host Refresh (for after actions)
// =============================================================================

/**
 * Fetch system status for a single host (bypasses bulk cache)
 * Used after actions like restart/reboot to get fresh data
 * @param {string} address - Host address
 * @returns {Promise<Object>} - Card data object
 */
export async function fetchSystemStatus(address) {
  const isLocal = address === 'localhost';

  try {
    if (isLocal) {
      // Force refresh all local data
      DATA_SOURCES.localStatus.lastFetch = 0;
      DATA_SOURCES.localSysStatus.lastFetch = 0;
      DATA_SOURCES.localConnectivity.lastFetch = 0;
      await fetchLocalStatus();
      return getLocalCardData();
    } else {
      // For remote, fetch directly (used after actions like restart)
      const [statusResponse, sysResponse, connResponse] = await Promise.all([
        fetch(`/api/plugin/fpp-plugin-watcher/remote/status?host=${encodeURIComponent(address)}`),
        fetch(`/api/plugin/fpp-plugin-watcher/remote/sysStatus?host=${encodeURIComponent(address)}`).catch(() => null),
        fetch(`/api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=${encodeURIComponent(address)}`).catch(() => null)
      ]);

      if (!statusResponse.ok) return { success: false, address, error: 'Failed to fetch status' };
      const statusData = await statusResponse.json();
      if (!statusData.success) return { success: false, address, error: statusData.error || 'Failed' };

      let sysInfo = {
        fppLocalVersion: null,
        fppRemoteVersion: null,
        diskUtilization: null,
        cpuUtilization: null,
        memoryUtilization: null,
        ipAddress: null
      };
      if (sysResponse?.ok) {
        const sysData = await sysResponse.json();
        sysInfo = parseSystemStatus(sysData.data || sysData);
      }

      let connectivityState = null;
      if (connResponse?.ok) {
        const connData = await connResponse.json();
        connectivityState = (connData.success && connData.hasResetAdapter) ? connData : null;
      }

      // Get cached updates data
      const updatesData = bulkUpdatesCache.get(address);

      // Update bulk cache with fresh data
      bulkStatusCache.set(address, {
        success: true,
        status: statusData.status,
        testMode: statusData.testMode,
        sysStatus: sysInfo,
        connectivity: connectivityState
      });

      return {
        success: true,
        address,
        status: statusData.status,
        testMode: statusData.testMode,
        watcherVersion: updatesData?.version || null,
        pluginUpdates: updatesData?.updates || [],
        connectivityState,
        ...sysInfo
      };
    }
  } catch (error) {
    return { success: false, address, error: error.message };
  }
}
