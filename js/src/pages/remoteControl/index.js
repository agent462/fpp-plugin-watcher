/**
 * Remote Control Page Module
 *
 * Main entry point for the remote control dashboard.
 * Provides a unified public interface for all sub-modules.
 *
 * Extracted from remoteControlUI.php embedded JavaScript (~2,100 lines).
 */

// State management
import {
  initConfig,
  resetState,
  config,
  setIsRefreshing,
  isRefreshing,
  shouldFetch,
  markFetched
} from './state.js';

// API functions
import {
  fetchLatestFPPRelease,
  fetchBulkStatus,
  fetchBulkUpdates,
  fetchLocalStatus,
  getRemoteCardData,
  getLocalCardData,
  fetchSystemStatus
} from './api.js';

// Card rendering
import { updateCardUI } from './cardRenderer.js';

// Actions
import {
  toggleTestMode,
  toggleMultiSyncTestMode,
  restartFppd,
  confirmReboot,
  closeConfirmDialog,
  executeReboot,
  clearResetState,
  upgradePlugin,
  restartLocalFppd,
  confirmLocalReboot,
  clearLocalResetState
} from './actions.js';

// Bulk operations
import { showBulkModal, closeBulkModal } from './bulkOps.js';

// FPP upgrade
import {
  showFPPUpgradeModal,
  closeFPPUpgradeModal,
  toggleFPPAccordion,
  toggleFPPSelection,
  fppSelectAll,
  fppSelectNone,
  fppExpandAll,
  fppCollapseAll,
  switchFPPUpgradeType,
  startAllFPPUpgrades,
  upgradeFPPSingle,
  upgradeFPPCrossVersion,
  upgradeFPPBranch
} from './fppUpgrade.js';

// Watcher upgrade
import {
  showWatcherUpgradeModal,
  closeWatcherUpgradeModal,
  toggleWatcherAccordion,
  toggleWatcherSelection,
  watcherSelectAll,
  watcherSelectNone,
  watcherExpandAll,
  watcherCollapseAll,
  startAllWatcherUpgrades,
  upgradeWatcherSingle
} from './watcherUpgrade.js';

// Issues banner
import {
  fetchIssues,
  renderIssues,
  toggleIssuesDetails,
  resetIssuesState
} from './issues.js';

// =============================================================================
// Module State
// =============================================================================

/** Auto-refresh interval ID */
let refreshIntervalId = null;

// =============================================================================
// Refresh Functions
// =============================================================================

/**
 * Refresh all status data and update UI
 */
async function refreshAllStatus() {
  if (isRefreshing) return;
  setIsRefreshing(true);

  const refreshBtn = document.getElementById('refreshBtn');
  if (refreshBtn) {
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;
  }

  const loadingIndicator = document.getElementById('loadingIndicator');
  const controlContent = document.getElementById('controlContent');
  if (loadingIndicator) loadingIndicator.style.display = 'none';
  if (controlContent) controlContent.style.display = 'block';

  try {
    // Fetch FPP release info (60s interval)
    if (shouldFetch('fppRelease')) {
      await fetchLatestFPPRelease();
      markFetched('fppRelease');
    }

    // Fetch all data in parallel using bulk endpoints for remotes
    await Promise.all([
      fetchLocalStatus(),
      fetchBulkStatus(),
      fetchBulkUpdates(),
      fetchIssues().then(data => renderIssues(data))
    ]);

    // Update UI from cached data
    updateCardUI('localhost', getLocalCardData());
    for (const addr of config.remoteAddresses) {
      updateCardUI(addr, getRemoteCardData(addr));
    }

    const lastUpdateTime = document.getElementById('lastUpdateTime');
    if (lastUpdateTime) {
      lastUpdateTime.textContent = new Date().toLocaleTimeString();
    }
  } finally {
    if (refreshBtn) {
      refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh All';
      refreshBtn.disabled = false;
    }
    setIsRefreshing(false);
  }
}

// =============================================================================
// Event Handlers
// =============================================================================

/**
 * Set up event listeners for confirm dialog and keyboard shortcuts
 */
function setupEventListeners() {
  // Confirm reboot button
  const confirmRebootBtn = document.getElementById('confirmRebootBtn');
  if (confirmRebootBtn) {
    confirmRebootBtn.addEventListener('click', executeReboot);
  }

  // Escape key to close modals
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeConfirmDialog();
      closeBulkModalHandler();
      closeFPPUpgradeModalHandler();
      closeWatcherUpgradeModalHandler();
    }
  });
}

// =============================================================================
// Modal Wrapper Functions
// =============================================================================

/**
 * Show bulk modal with callbacks
 */
function showBulkModalHandler(type) {
  showBulkModal(type, showFPPUpgradeModal, showWatcherUpgradeModal);
}

/**
 * Close bulk modal with refresh callback
 */
function closeBulkModalHandler() {
  closeBulkModal(refreshAllStatus);
}

/**
 * Close FPP upgrade modal with refresh callback
 */
function closeFPPUpgradeModalHandler() {
  closeFPPUpgradeModal(refreshAllStatus);
}

/**
 * Close Watcher upgrade modal with refresh callback
 */
function closeWatcherUpgradeModalHandler() {
  closeWatcherUpgradeModal(refreshAllStatus);
}

/**
 * Toggle MultiSync test mode with refresh
 */
async function toggleMultiSyncTestModeHandler(enable) {
  await toggleMultiSyncTestMode(enable);
  // Refresh all cards after delay
  setTimeout(refreshAllStatus, 1000);
}

// =============================================================================
// Public Interface
// =============================================================================

/**
 * Remote Control page module
 */
export const remoteControl = {
  pageId: 'remoteControlUI',

  /**
   * Initialize the page module
   * @param {Object} cfg - Configuration from PHP
   */
  init(cfg) {
    initConfig(cfg);
    setupEventListeners();

    // Initial data load
    refreshAllStatus();

    // Auto-refresh every 10 seconds
    refreshIntervalId = setInterval(() => {
      if (!isRefreshing) refreshAllStatus();
    }, 10000);
  },

  /**
   * Clean up and reset state
   */
  destroy() {
    if (refreshIntervalId) {
      clearInterval(refreshIntervalId);
      refreshIntervalId = null;
    }
    resetState();
    resetIssuesState();
  },

  // Refresh
  refresh: refreshAllStatus,
  refreshAllStatus,

  // Test mode
  toggleTestMode,
  toggleMultiSyncTestMode: toggleMultiSyncTestModeHandler,

  // Restart/Reboot
  restartFppd,
  confirmReboot,
  closeConfirmDialog,
  restartLocalFppd,
  confirmLocalReboot,

  // Connectivity
  clearResetState,
  clearLocalResetState,

  // Plugin upgrade
  upgradePlugin,

  // Bulk modal
  showBulkModal: showBulkModalHandler,
  closeBulkModal: closeBulkModalHandler,

  // FPP upgrade modal
  showFPPUpgradeModal,
  closeFPPUpgradeModal: closeFPPUpgradeModalHandler,
  toggleFPPAccordion,
  toggleFPPSelection,
  fppSelectAll,
  fppSelectNone,
  fppExpandAll,
  fppCollapseAll,
  switchFPPUpgradeType,
  startAllFPPUpgrades,
  upgradeFPPSingle,
  upgradeFPPCrossVersion,
  upgradeFPPBranch,

  // Watcher upgrade modal
  showWatcherUpgradeModal,
  closeWatcherUpgradeModal: closeWatcherUpgradeModalHandler,
  toggleWatcherAccordion,
  toggleWatcherSelection,
  watcherSelectAll,
  watcherSelectNone,
  watcherExpandAll,
  watcherCollapseAll,
  startAllWatcherUpgrades,
  upgradeWatcherSingle,

  // Issues banner
  toggleIssuesDetails
};
