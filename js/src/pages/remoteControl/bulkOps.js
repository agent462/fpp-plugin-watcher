/**
 * Remote Control Page - Bulk Operations Module
 *
 * Generic bulk modal for connectivity failures, restart, and other plugins.
 * FPP and Watcher upgrades have their own specialized modals.
 */

import {
  escapeId,
  currentBulkType,
  setCurrentBulkType,
  hostsWithConnectivityFailure,
  hostsWithOtherPluginUpdates,
  hostsNeedingRestart
} from './state.js';
import { buildHostListHtml } from './cardRenderer.js';

// =============================================================================
// Bulk Operation Configuration
// =============================================================================

/**
 * Configuration for each bulk operation type
 */
export const bulkConfig = {
  connectivity: {
    title: '<i class="fas fa-network-wired" style="color: #ffc107;"></i> Clearing Connectivity Failures',
    getHosts: () => hostsWithConnectivityFailure,
    extraInfo: (info) => `<span style="font-size: 0.8em; color: #666;"> - ${info.adapter} at ${info.resetTime}</span>`,
    operation: async ([address]) => {
      const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: address })
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.error);
    },
    refreshDelay: 1000
  },
  restart: {
    title: '<i class="fas fa-sync" style="color: #fd7e14;"></i> Restarting Systems',
    getHosts: () => hostsNeedingRestart,
    extraInfo: (info) => `<span class="restart-type ${info.type}">${info.type === 'reboot' ? 'Reboot' : 'FPPD Restart'}</span>`,
    operation: async ([address, info]) => {
      const endpoint = info.type === 'reboot'
        ? '/api/plugin/fpp-plugin-watcher/remote/reboot'
        : '/api/plugin/fpp-plugin-watcher/remote/restart';
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: address })
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.error);
    },
    refreshDelay: 3000
  },
  otherPlugins: {
    title: '<i class="fas fa-puzzle-piece" style="color: #17a2b8;"></i> Upgrading Plugins',
    getHosts: () => hostsWithOtherPluginUpdates,
    extraInfo: (info) => `<span style="font-size: 0.8em; color: #666;"> - ${info.plugins.map(p => p.name).join(', ')}</span>`,
    parallel: true,
    operation: async ([address, info], updateStatus) => {
      const plugins = info.plugins;
      for (let i = 0; i < plugins.length; i++) {
        const plugin = plugins[i];
        updateStatus('spinner fa-spin', `Upgrading ${plugin.name} (${i + 1}/${plugins.length})...`);
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/upgrade', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ host: address, plugin: plugin.repoName })
        });
        const data = await response.json();
        if (!data.success) throw new Error(`Failed to upgrade ${plugin.name}: ${data.error}`);
      }
    },
    refreshDelay: 3000
  }
};

// =============================================================================
// Bulk Processing
// =============================================================================

/**
 * Process a bulk operation across multiple hosts
 * @param {Array} hostsArray - Array of [address, info] tuples
 * @param {Function} operationFn - Async function to run on each host
 * @param {string} idPrefix - ID prefix for status elements
 * @param {Element} progressEl - Progress text element
 * @param {boolean} parallel - Run operations in parallel
 * @returns {Promise<Object>} - { completed, failed, total }
 */
export async function processBulkOperation(hostsArray, operationFn, idPrefix, progressEl, parallel = false) {
  let completed = 0, failed = 0;
  const total = hostsArray.length;

  if (parallel) {
    progressEl.textContent = `Processing ${total} hosts in parallel...`;

    // Mark all as in-progress
    hostsArray.forEach(item => {
      const address = Array.isArray(item) ? item[0] : item;
      const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);
      if (statusEl) {
        statusEl.className = 'host-status in-progress';
        statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      }
    });

    // Run all operations in parallel
    const results = await Promise.allSettled(hostsArray.map(async (item) => {
      const address = Array.isArray(item) ? item[0] : item;
      const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);
      const updateStatus = (icon, text, className = 'in-progress') => {
        if (statusEl) {
          statusEl.className = `host-status ${className}`;
          statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
        }
      };
      try {
        await operationFn(item, updateStatus);
        if (statusEl) {
          statusEl.className = 'host-status success';
          statusEl.innerHTML = '<i class="fas fa-check"></i> Done';
        }
        return { success: true };
      } catch (error) {
        console.error(`Error processing ${address}:`, error);
        if (statusEl) {
          statusEl.className = 'host-status error';
          statusEl.innerHTML = '<i class="fas fa-times"></i> Failed';
        }
        return { success: false };
      }
    }));

    results.forEach(r => r.value?.success ? completed++ : failed++);
  } else {
    progressEl.textContent = `Processing 0 of ${total} hosts...`;

    for (const item of hostsArray) {
      const address = Array.isArray(item) ? item[0] : item;
      const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);

      if (statusEl) {
        statusEl.className = 'host-status in-progress';
        statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      }

      const updateStatus = (icon, text, className = 'in-progress') => {
        if (statusEl) {
          statusEl.className = `host-status ${className}`;
          statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
        }
      };

      try {
        await operationFn(item, updateStatus);
        if (statusEl) {
          statusEl.className = 'host-status success';
          statusEl.innerHTML = '<i class="fas fa-check"></i> Done';
        }
        completed++;
      } catch (error) {
        console.error(`Error processing ${address}:`, error);
        if (statusEl) {
          statusEl.className = 'host-status error';
          statusEl.innerHTML = '<i class="fas fa-times"></i> Failed';
        }
        failed++;
      }
      progressEl.textContent = `Processed ${completed + failed} of ${total} hosts...`;
    }
  }

  return { completed, failed, total };
}

// =============================================================================
// Bulk Modal Functions
// =============================================================================

/**
 * Show bulk operations modal
 * @param {string} type - Operation type (connectivity, restart, otherPlugins)
 * @param {Function} showFPPUpgradeModal - Callback for FPP upgrade modal
 * @param {Function} showWatcherUpgradeModal - Callback for Watcher upgrade modal
 */
export async function showBulkModal(type, showFPPUpgradeModal, showWatcherUpgradeModal) {
  // Redirect FPP and Watcher upgrades to their specialized modals
  if (type === 'fpp') {
    showFPPUpgradeModal();
    return;
  }
  if (type === 'upgrade') {
    showWatcherUpgradeModal();
    return;
  }

  const config = bulkConfig[type];
  if (!config) return;

  const hostsMap = config.getHosts();
  if (hostsMap.size < 1) return;

  setCurrentBulkType(type);

  const modal = document.getElementById('bulkModal');
  const titleEl = document.getElementById('bulkModalTitle');
  const hostList = document.getElementById('bulkModalHostList');
  const progressEl = document.getElementById('bulkModalProgress');
  const closeBtn = document.getElementById('bulkModalCloseBtn');

  if (!modal || !titleEl || !hostList || !progressEl || !closeBtn) return;

  titleEl.innerHTML = config.title;
  hostList.innerHTML = buildHostListHtml(hostsMap, 'bulk', config.extraInfo);
  modal.style.display = 'flex';
  closeBtn.disabled = true;

  const hostsArray = Array.from(hostsMap.entries());
  const { completed, failed } = await processBulkOperation(
    hostsArray,
    config.operation,
    'bulk',
    progressEl,
    config.parallel || false
  );

  progressEl.textContent = failed === 0
    ? `Successfully processed ${completed} hosts!`
    : `Completed: ${completed} succeeded, ${failed} failed`;
  closeBtn.disabled = false;
}

/**
 * Close bulk modal and refresh
 * @param {Function} refreshAllStatus - Callback to refresh all cards
 */
export function closeBulkModal(refreshAllStatus) {
  const modal = document.getElementById('bulkModal');
  if (modal) {
    modal.style.display = 'none';
  }

  const delay = bulkConfig[currentBulkType]?.refreshDelay || 0;
  setTimeout(refreshAllStatus, delay);
  setCurrentBulkType(null);
}
