/**
 * Remote Control Page - Card Renderer Module
 *
 * Handles updating the UI for control cards based on cached data.
 * Updates status indicators, info grid, updates container, and bulk buttons.
 */

import {
  escapeId,
  getHostname,
  config,
  hostsWithWatcherUpdates,
  hostsWithOtherPluginUpdates,
  hostsNeedingRestart,
  hostsWithFPPUpdates,
  hostsWithConnectivityFailure
} from './state.js';
import { checkCrossVersionUpgrade } from './api.js';

// =============================================================================
// Bulk Button Updates
// =============================================================================

/**
 * Update bulk action button visibility and count badge
 * @param {string} buttonId - Button element ID
 * @param {string} countId - Badge element ID
 * @param {Map} map - Host tracking map
 * @param {number} minCount - Minimum count to show button
 */
export function updateBulkButton(buttonId, countId, map, minCount = 1) {
  const btn = document.getElementById(buttonId);
  const badge = document.getElementById(countId);
  if (!btn || !badge) return;

  const count = map.size;
  btn.classList.toggle('visible', count >= minCount);
  badge.textContent = count;
}

/**
 * Update all bulk buttons based on current tracking maps
 */
export function updateAllBulkButtons() {
  updateBulkButton('upgradeAllBtn', 'upgradeAllCount', hostsWithWatcherUpdates);
  updateBulkButton('upgradeOtherPluginsBtn', 'upgradeOtherPluginsCount', hostsWithOtherPluginUpdates);
  updateBulkButton('restartAllBtn', 'restartAllCount', hostsNeedingRestart);
  updateBulkButton('fppUpgradeAllBtn', 'fppUpgradeAllCount', hostsWithFPPUpdates);
  updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);
}

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Build HTML for host list in bulk modal
 * @param {Map} hostsMap - Host tracking map
 * @param {string} idPrefix - ID prefix for elements
 * @param {Function} extraInfoFn - Optional function to add extra info
 * @returns {string} - HTML string
 */
export function buildHostListHtml(hostsMap, idPrefix, extraInfoFn = null) {
  let html = '';
  hostsMap.forEach((info, address) => {
    const extra = extraInfoFn ? extraInfoFn(info) : '';
    html += `
      <div class="host-item" id="${idPrefix}-${escapeId(address)}">
        <div class="host-name">${info.hostname} (${address})${extra}</div>
        <div class="host-status pending" id="${idPrefix}-status-${escapeId(address)}">
          <i class="fas fa-clock"></i> Pending
        </div>
      </div>`;
  });
  return html;
}

// =============================================================================
// Card UI Update
// =============================================================================

/**
 * Update a single card's UI based on data
 * @param {string} address - Host address
 * @param {Object} data - Card data from getRemoteCardData/getLocalCardData
 */
export function updateCardUI(address, data) {
  const card = document.getElementById(`card-${address}`);
  const statusEl = document.getElementById(`status-${address}`);
  const platformEl = document.getElementById(`platform-${address}`);
  const versionEl = document.getElementById(`version-${address}`);
  const modeEl = document.getElementById(`mode-${address}`);
  const watcherEl = document.getElementById(`watcher-${address}`);
  const testModeToggle = document.getElementById(`testmode-${address}`);
  const restartBtn = document.getElementById(`restart-btn-${address}`);
  const rebootBtn = document.getElementById(`reboot-btn-${address}`);
  const updatesContainer = document.getElementById(`updates-container-${address}`);
  const upgradesList = document.getElementById(`upgrades-list-${address}`);

  // FPP update rows
  const fppMajorRow = document.getElementById(`fpp-major-row-${address}`);
  const fppMajorVersion = document.getElementById(`fpp-major-version-${address}`);
  const fppCrossVersionRow = document.getElementById(`fpp-crossversion-row-${address}`);
  const fppCrossVersionVersion = document.getElementById(`fpp-crossversion-version-${address}`);
  const fppBranchRow = document.getElementById(`fpp-branch-row-${address}`);
  const fppBranchVersion = document.getElementById(`fpp-branch-version-${address}`);

  if (!card) return;

  // Clear all status classes
  card.classList.remove('offline', 'status-ok', 'status-warning', 'status-restart', 'status-update', 'status-testing');

  if (!data.success) {
    renderOfflineCard(card, statusEl, platformEl, versionEl, modeEl, watcherEl,
      testModeToggle, restartBtn, rebootBtn, updatesContainer, fppMajorRow,
      fppCrossVersionRow, fppBranchRow, address);
    return;
  }

  const { status, testMode, pluginUpdates = [], fppLocalVersion, fppRemoteVersion,
    connectivityState, diskUtilization, cpuUtilization, memoryUtilization, ipAddress } = data;

  const isTestMode = testMode?.enabled === 1;
  const needsReboot = status.rebootFlag === 1;
  const needsRestart = status.restartFlag === 1;

  // Update localhost address display with actual IP
  if (address === 'localhost' && ipAddress) {
    const addrEl = document.getElementById('localhost-address');
    if (addrEl) addrEl.innerHTML = `<a href="http://${ipAddress}/" target="_blank">${ipAddress} (This System)</a>`;
  }

  // Check for updates
  const sameBranchUpdate = fppLocalVersion && fppRemoteVersion &&
    fppRemoteVersion !== 'Unknown' && fppRemoteVersion !== '' &&
    fppLocalVersion !== fppRemoteVersion;
  const crossVersionUpgrade = checkCrossVersionUpgrade(status.branch);
  const fppUpdateAvailable = sameBranchUpdate || (crossVersionUpgrade && crossVersionUpgrade.available);

  // Check for issues
  const hasConnectivityFailure = connectivityState && connectivityState.hasResetAdapter;
  const hasPluginUpdates = pluginUpdates.length > 0;
  const hasLowStorage = diskUtilization !== null && diskUtilization >= 90;
  const hasHighCpu = cpuUtilization !== null && cpuUtilization >= 80;
  const hasLowMemory = memoryUtilization !== null && memoryUtilization >= 90;

  // Set status class for left border accent
  if (isTestMode) card.classList.add('status-testing');
  else if (needsReboot || needsRestart) card.classList.add('status-restart');
  else if (fppUpdateAvailable || hasPluginUpdates) card.classList.add('status-update');
  else if (hasConnectivityFailure || hasLowStorage || hasHighCpu || hasLowMemory) card.classList.add('status-warning');
  else card.classList.add('status-ok');

  // Build status indicators
  const indicators = buildStatusIndicators(status, isTestMode, needsReboot, needsRestart,
    hasConnectivityFailure, hasHighCpu, hasLowMemory, hasLowStorage,
    cpuUtilization, memoryUtilization, diskUtilization,
    crossVersionUpgrade, sameBranchUpdate);
  statusEl.innerHTML = '<div class="status-indicators">' + indicators.join('') + '</div>';

  // Update info
  platformEl.textContent = status.platform || '--';
  versionEl.innerHTML = buildVersionHtml(status.branch, crossVersionUpgrade, sameBranchUpdate);
  modeEl.textContent = status.mode_name || '--';
  watcherEl.textContent = data.watcherVersion || 'Not installed';

  // Enable controls
  testModeToggle.disabled = false;
  testModeToggle.checked = isTestMode;
  restartBtn.disabled = false;
  rebootBtn.disabled = false;

  // Show/hide MultiSync test mode toggle for localhost when in player mode
  if (address === 'localhost') {
    const multiSyncRow = document.getElementById('multisync-test-row-localhost');
    const multiSyncToggle = document.getElementById('testmode-multisync-localhost');
    const isPlayer = status.mode_name && status.mode_name.toLowerCase() === 'player';
    if (multiSyncRow) {
      multiSyncRow.style.display = isPlayer ? 'flex' : 'none';
    }
    if (multiSyncToggle) {
      multiSyncToggle.disabled = !isPlayer;
    }
  }

  // Track hosts in maps and update bulk buttons
  trackHostUpdates(address, pluginUpdates, needsReboot, needsRestart,
    crossVersionUpgrade, sameBranchUpdate, fppLocalVersion, fppRemoteVersion,
    status.branch, hasConnectivityFailure, connectivityState);

  // Update FPP update rows
  updateFPPRows(address, crossVersionUpgrade, sameBranchUpdate, status.branch,
    fppLocalVersion, fppRemoteVersion, fppMajorRow, fppMajorVersion,
    fppCrossVersionRow, fppCrossVersionVersion, fppBranchRow, fppBranchVersion);

  // Update connectivity alert
  updateConnectivityAlert(address, hasConnectivityFailure, connectivityState);

  // Update plugin upgrades list
  updatePluginsList(address, pluginUpdates, upgradesList);

  // Show/hide updates container
  if (fppUpdateAvailable || pluginUpdates.length > 0) {
    updatesContainer.classList.add('visible');
  } else {
    updatesContainer.classList.remove('visible');
  }
}

// =============================================================================
// Private Helper Functions
// =============================================================================

function renderOfflineCard(card, statusEl, platformEl, versionEl, modeEl, watcherEl,
  testModeToggle, restartBtn, rebootBtn, updatesContainer, fppMajorRow,
  fppCrossVersionRow, fppBranchRow, address) {

  card.classList.add('offline');
  statusEl.innerHTML = '<div class="status-indicators"><span class="status-indicator status-indicator--offline"><span class="dot"></span>Offline</span></div>';
  platformEl.textContent = '--';
  versionEl.textContent = '--';
  modeEl.textContent = '--';
  watcherEl.textContent = '--';
  testModeToggle.disabled = true;
  testModeToggle.checked = false;
  restartBtn.disabled = true;
  rebootBtn.disabled = true;
  updatesContainer.classList.remove('visible');
  fppMajorRow?.classList.remove('visible');
  fppCrossVersionRow?.classList.remove('visible');
  fppBranchRow?.classList.remove('visible');

  const connectivityAlert = document.getElementById(`connectivity-alert-${address}`);
  connectivityAlert?.classList.remove('visible');

  hostsWithConnectivityFailure.delete(address);
  updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);
}

function buildStatusIndicators(status, isTestMode, needsReboot, needsRestart,
  hasConnectivityFailure, hasHighCpu, hasLowMemory, hasLowStorage,
  cpuUtilization, memoryUtilization, diskUtilization,
  crossVersionUpgrade, sameBranchUpdate) {

  let indicators = ['<span class="status-indicator status-indicator--online"><span class="dot"></span>Online</span>'];

  const playbackStatus = status.status_name || 'idle';
  if (playbackStatus === 'playing') {
    indicators.push('<span class="status-indicator status-indicator--playing"><span class="dot"></span>Playing</span>');
  } else if (!isTestMode && playbackStatus === 'idle') {
    indicators.push('<span class="status-indicator status-indicator--idle"><span class="dot"></span>Idle</span>');
  }

  if (hasConnectivityFailure) indicators.push('<span class="status-indicator status-indicator--connectivity"><span class="dot"></span>Conn. Failed</span>');
  if (hasHighCpu) indicators.push(`<span class="status-indicator status-indicator--high-cpu"><span class="dot"></span>High CPU (${cpuUtilization}%)</span>`);
  if (hasLowMemory) indicators.push(`<span class="status-indicator status-indicator--low-memory"><span class="dot"></span>Low Memory (${memoryUtilization}%)</span>`);
  if (hasLowStorage) indicators.push(`<span class="status-indicator status-indicator--low-storage"><span class="dot"></span>Low Storage (${diskUtilization}%)</span>`);
  if (isTestMode) indicators.push('<span class="status-indicator status-indicator--testing"><span class="dot"></span>Test Mode</span>');

  if (crossVersionUpgrade && crossVersionUpgrade.available) {
    if (crossVersionUpgrade.isMajorUpgrade) {
      indicators.push(`<span class="status-indicator status-indicator--major-upgrade" title="Major version upgrade requires OS Upgrade"><span class="dot"></span>FPP v${crossVersionUpgrade.latestVersion}</span>`);
    } else {
      indicators.push(`<span class="status-indicator status-indicator--update"><span class="dot"></span>FPP v${crossVersionUpgrade.latestVersion}</span>`);
    }
  }
  if (sameBranchUpdate) {
    const branchDisplay = status.branch ? status.branch.replace(/^v/, '') : '';
    indicators.push(`<span class="status-indicator status-indicator--update"><span class="dot"></span>${branchDisplay} Update</span>`);
  }
  if (needsReboot) indicators.push('<span class="status-indicator status-indicator--reboot"><span class="dot"></span>Reboot Req</span>');
  else if (needsRestart) indicators.push('<span class="status-indicator status-indicator--restart"><span class="dot"></span>Restart Req</span>');

  return indicators;
}

function buildVersionHtml(branch, crossVersionUpgrade, sameBranchUpdate) {
  let versionHtml = branch || '--';
  let versionNotes = [];

  if (crossVersionUpgrade && crossVersionUpgrade.available) {
    if (crossVersionUpgrade.isMajorUpgrade) {
      versionNotes.push(`<span class="version-major" title="Major version upgrade requires OS Upgrade">v${crossVersionUpgrade.latestVersion} Upgrade</span>`);
    } else {
      versionNotes.push(`<span class="version-update">v${crossVersionUpgrade.latestVersion}</span>`);
    }
  }
  if (sameBranchUpdate) {
    versionNotes.push(`<span class="version-update">branch update</span>`);
  }
  if (versionNotes.length > 0) {
    versionHtml += ` (${versionNotes.join(', ')})`;
  }

  return versionHtml;
}

function trackHostUpdates(address, pluginUpdates, needsReboot, needsRestart,
  crossVersionUpgrade, sameBranchUpdate, fppLocalVersion, fppRemoteVersion, branch,
  hasConnectivityFailure, connectivityState) {

  const hostname = getHostname(address);

  // Track Watcher updates
  const watcherUpdate = pluginUpdates.find(p => p.repoName === 'fpp-plugin-watcher');
  if (watcherUpdate) {
    hostsWithWatcherUpdates.set(address, {
      hostname,
      installedVersion: watcherUpdate.installedVersion,
      latestVersion: watcherUpdate.latestVersion
    });
  } else {
    hostsWithWatcherUpdates.delete(address);
  }
  updateBulkButton('upgradeAllBtn', 'upgradeAllCount', hostsWithWatcherUpdates);

  // Track non-Watcher plugin updates
  const otherPluginUpdates = pluginUpdates.filter(p => p.repoName !== 'fpp-plugin-watcher');
  if (otherPluginUpdates.length > 0) {
    hostsWithOtherPluginUpdates.set(address, { hostname, plugins: otherPluginUpdates });
  } else {
    hostsWithOtherPluginUpdates.delete(address);
  }
  updateBulkButton('upgradeOtherPluginsBtn', 'upgradeOtherPluginsCount', hostsWithOtherPluginUpdates);

  // Track restart/reboot needed
  if (needsReboot) {
    hostsNeedingRestart.set(address, { hostname, type: 'reboot' });
  } else if (needsRestart) {
    hostsNeedingRestart.set(address, { hostname, type: 'restart' });
  } else {
    hostsNeedingRestart.delete(address);
  }
  updateBulkButton('restartAllBtn', 'restartAllCount', hostsNeedingRestart);

  // Track FPP updates (not major version upgrades - those require OS Upgrade)
  const isMajorUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && crossVersionUpgrade.isMajorUpgrade;
  const hasCrossVersionUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && !isMajorUpgrade;

  if (hasCrossVersionUpgrade || sameBranchUpdate) {
    hostsWithFPPUpdates.set(address, {
      hostname,
      branch,
      crossVersion: hasCrossVersionUpgrade ? {
        localVersion: crossVersionUpgrade.currentVersion,
        remoteVersion: crossVersionUpgrade.latestVersion
      } : null,
      branchUpdate: sameBranchUpdate ? {
        localVersion: fppLocalVersion,
        remoteVersion: fppRemoteVersion
      } : null
    });
  } else {
    hostsWithFPPUpdates.delete(address);
  }
  updateBulkButton('fppUpgradeAllBtn', 'fppUpgradeAllCount', hostsWithFPPUpdates);

  // Track connectivity failures
  if (hasConnectivityFailure) {
    const resetTime = connectivityState.resetTime || 'Unknown time';
    const adapter = connectivityState.adapter || 'Unknown';
    hostsWithConnectivityFailure.set(address, { hostname, resetTime, adapter });
  } else {
    hostsWithConnectivityFailure.delete(address);
  }
  updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);
}

function updateFPPRows(address, crossVersionUpgrade, sameBranchUpdate, branch,
  fppLocalVersion, fppRemoteVersion, fppMajorRow, fppMajorVersion,
  fppCrossVersionRow, fppCrossVersionVersion, fppBranchRow, fppBranchVersion) {

  const isMajorUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && crossVersionUpgrade.isMajorUpgrade;
  const hasCrossVersionUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && !isMajorUpgrade;

  // Show/hide major upgrade row (informational only)
  if (isMajorUpgrade && fppMajorRow) {
    fppMajorRow.classList.add('visible');
    if (fppMajorVersion) {
      fppMajorVersion.textContent = `v${crossVersionUpgrade.currentVersion} → v${crossVersionUpgrade.latestVersion}`;
    }
  } else if (fppMajorRow) {
    fppMajorRow.classList.remove('visible');
  }

  // Show/hide cross-version upgrade row
  // When branch update is also available, disable cross-version until branch is done
  if (hasCrossVersionUpgrade && fppCrossVersionRow) {
    fppCrossVersionRow.classList.add('visible');
    if (fppCrossVersionVersion) {
      fppCrossVersionVersion.textContent = `v${crossVersionUpgrade.currentVersion} → v${crossVersionUpgrade.latestVersion}`;
    }
    // Disable cross-version button if branch update is pending
    const crossVersionBtn = document.getElementById(`fpp-crossversion-btn-${address}`);
    if (crossVersionBtn) {
      if (sameBranchUpdate) {
        crossVersionBtn.disabled = true;
        crossVersionBtn.title = 'Complete branch update first';
        fppCrossVersionRow.classList.add('blocked-by-branch');
      } else {
        crossVersionBtn.disabled = false;
        crossVersionBtn.title = '';
        fppCrossVersionRow.classList.remove('blocked-by-branch');
      }
    }
  } else if (fppCrossVersionRow) {
    fppCrossVersionRow.classList.remove('visible');
    fppCrossVersionRow.classList.remove('blocked-by-branch');
  }

  // Show/hide same-branch update row
  if (sameBranchUpdate && fppBranchRow) {
    const branchDisplay = branch ? branch.replace(/^v/, '') : '';
    fppBranchRow.classList.add('visible');
    if (fppBranchVersion) {
      fppBranchVersion.textContent = `${branchDisplay}: ${fppLocalVersion.substring(0, 7)} → ${fppRemoteVersion.substring(0, 7)}`;
    }
  } else if (fppBranchRow) {
    fppBranchRow.classList.remove('visible');
  }
}

function updateConnectivityAlert(address, hasConnectivityFailure, connectivityState) {
  const connectivityAlert = document.getElementById(`connectivity-alert-${address}`);
  const connectivityDetails = document.getElementById(`connectivity-details-${address}`);

  if (!connectivityAlert) return;

  if (hasConnectivityFailure) {
    const resetTime = connectivityState.resetTime || 'Unknown time';
    const adapter = connectivityState.adapter || 'Unknown';
    if (connectivityDetails) {
      connectivityDetails.textContent = `Adapter ${adapter} reset at ${resetTime}`;
    }
    connectivityAlert.classList.add('visible');
  } else {
    connectivityAlert.classList.remove('visible');
  }
}

function updatePluginsList(address, pluginUpdates, upgradesList) {
  if (!upgradesList) return;

  if (pluginUpdates.length > 0) {
    let html = '';
    pluginUpdates.forEach(plugin => {
      const versionDisplay = plugin.latestVersion
        ? `v${plugin.installedVersion} → v${plugin.latestVersion}`
        : `v${plugin.installedVersion}`;
      // Use streaming modal for Watcher plugin, standard upgrade for others
      const onclickHandler = plugin.repoName === 'fpp-plugin-watcher'
        ? `page.upgradeWatcherSingle('${address}')`
        : `page.upgradePlugin('${address}', '${plugin.repoName}')`;
      html += `
        <div class="upgrade-item" id="upgrade-item-${address}-${plugin.repoName}">
          <div class="update-info">
            <span class="update-name">${plugin.name}</span>
            <span class="update-version">${versionDisplay}</span>
          </div>
          <button class="banner-btn" onclick="${onclickHandler}" id="upgrade-btn-${address}-${plugin.repoName}">
            <i class="fas fa-download"></i> Upgrade
          </button>
        </div>`;
    });
    upgradesList.innerHTML = html;
  } else {
    upgradesList.innerHTML = '';
  }
}
