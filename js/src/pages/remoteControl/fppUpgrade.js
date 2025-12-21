/**
 * Remote Control Page - FPP Upgrade Module
 *
 * Streaming FPP upgrade modal with accordion UI and parallel upgrade support.
 * Supports both cross-version upgrades (e.g., v9.2 -> v9.3) and same-branch updates.
 */

import {
  escapeId,
  hostsWithFPPUpdates,
  latestFPPRelease,
  invalidateCache
} from './state.js';

// =============================================================================
// Module State
// =============================================================================

/** FPP upgrade states by address */
export const fppUpgradeStates = new Map();

/** Flag indicating if FPP upgrade is running */
export let fppUpgradeIsRunning = false;

/** Currently selected upgrade type: 'crossVersion' or 'branchUpdate' */
export let fppSelectedUpgradeType = 'crossVersion';

// Setters
export function setFppUpgradeIsRunning(value) {
  fppUpgradeIsRunning = value;
}

export function setFppSelectedUpgradeType(value) {
  fppSelectedUpgradeType = value;
}

// =============================================================================
// Host Filtering by Upgrade Type
// =============================================================================

/**
 * Get hosts filtered by upgrade type
 * @param {string} upgradeType - 'crossVersion' or 'branchUpdate'
 * @returns {Map} - Filtered hosts map with blockedByBranch flag for crossVersion
 */
export function getHostsForUpgradeType(upgradeType) {
  const result = new Map();
  hostsWithFPPUpdates.forEach((info, addr) => {
    if (upgradeType === 'crossVersion' && info.crossVersion) {
      // Check if this host also has a pending branch update
      const blockedByBranch = !!info.branchUpdate;
      result.set(addr, {
        hostname: info.hostname,
        localVersion: info.crossVersion.localVersion,
        remoteVersion: info.crossVersion.remoteVersion,
        isCrossVersion: true,
        blockedByBranch
      });
    } else if (upgradeType === 'branchUpdate' && info.branchUpdate) {
      const branchDisplay = info.branch ? info.branch.replace(/^v/, '') : '';
      result.set(addr, {
        hostname: info.hostname,
        localVersion: info.branchUpdate.localVersion.substring(0, 7),
        remoteVersion: info.branchUpdate.remoteVersion.substring(0, 7),
        branch: branchDisplay,
        isCrossVersion: false,
        blockedByBranch: false
      });
    }
  });
  return result;
}

/**
 * Count hosts by upgrade type
 * @returns {Object} - { crossVersionCount, branchUpdateCount }
 */
export function countHostsByUpgradeType() {
  let crossVersionCount = 0, branchUpdateCount = 0;
  hostsWithFPPUpdates.forEach(info => {
    if (info.crossVersion) crossVersionCount++;
    if (info.branchUpdate) branchUpdateCount++;
  });
  return { crossVersionCount, branchUpdateCount };
}

// =============================================================================
// Accordion UI
// =============================================================================

/**
 * Switch upgrade type and rebuild accordion
 * @param {string} upgradeType - 'crossVersion' or 'branchUpdate'
 */
export function switchFPPUpgradeType(upgradeType) {
  if (fppUpgradeIsRunning) return;
  fppSelectedUpgradeType = upgradeType;
  buildFPPAccordion();
}

/**
 * Build FPP accordion UI for selected upgrade type
 */
export function buildFPPAccordion() {
  const accordion = document.getElementById('fppAccordion');
  if (!accordion) return;

  const hostsForType = getHostsForUpgradeType(fppSelectedUpgradeType);

  // Reset state for new type
  fppUpgradeStates.clear();

  // Build accordion items
  let html = '';
  if (hostsForType.size === 0) {
    html = '<div style="text-align: center; padding: 2rem; color: #6c757d;"><i class="fas fa-info-circle"></i> No hosts available for this upgrade type</div>';
  } else {
    hostsForType.forEach((info, addr) => {
      const safeId = escapeId(addr);
      const isBlocked = info.blockedByBranch;
      // Store upgrade info at the time modal is opened to prevent race conditions
      fppUpgradeStates.set(addr, {
        status: isBlocked ? 'blocked' : 'pending',
        abortController: null,
        expanded: false,
        selected: !isBlocked, // Don't select blocked hosts
        upgradeInfo: info,
        blockedByBranch: isBlocked
      });
      const versionDisplay = info.branch
        ? `${info.branch}: ${info.localVersion} → ${info.remoteVersion}`
        : `v${info.localVersion} → v${info.remoteVersion}`;

      // Blocked hosts get different styling and disabled checkbox
      const itemClass = isBlocked ? 'fpp-accordion-item blocked excluded' : 'fpp-accordion-item';
      const checkboxAttrs = isBlocked ? 'disabled' : 'checked';
      const statusClass = isBlocked ? 'blocked' : 'pending';
      const statusIcon = isBlocked ? 'fa-ban' : 'fa-clock';
      const statusText = isBlocked ? 'Branch Update Required' : 'Pending';

      html += `
        <div class="${itemClass}" id="fpp-item-${safeId}" data-address="${addr}">
          <div class="fpp-accordion-header" onclick="page.toggleFPPAccordion('${addr}')">
            <input type="checkbox" class="fpp-accordion-checkbox" id="fpp-check-${safeId}" ${checkboxAttrs} onclick="event.stopPropagation(); page.toggleFPPSelection('${addr}')"${isBlocked ? ' title="Complete branch update first"' : ''}>
            <div class="fpp-accordion-toggle"><i class="fas fa-chevron-right"></i></div>
            <div class="fpp-accordion-info">
              <span class="fpp-accordion-hostname">${info.hostname}</span>
              <span class="fpp-accordion-address">${addr}</span>
              <span class="fpp-accordion-version">${versionDisplay}</span>
            </div>
            <div class="fpp-accordion-status ${statusClass}" id="fpp-status-${safeId}" onclick="event.stopPropagation()">
              <i class="fas ${statusIcon}"></i> ${statusText}
            </div>
          </div>
          ${isBlocked ? '<div class="fpp-accordion-blocked-note"><i class="fas fa-info-circle"></i> Complete the branch update before upgrading to a new version</div>' : ''}
          <div class="fpp-accordion-body">
            <div class="fpp-accordion-log" id="fpp-log-${safeId}"></div>
          </div>
        </div>`;
    });
  }

  accordion.innerHTML = html;
  updateFPPSummary();
}

// =============================================================================
// Modal Functions
// =============================================================================

/**
 * Show FPP upgrade modal
 */
export function showFPPUpgradeModal() {
  if (hostsWithFPPUpdates.size < 1) return;

  const modal = document.getElementById('fppUpgradeModal');
  const startBtn = document.getElementById('fppUpgradeStartBtn');
  const closeBtn = document.getElementById('fppUpgradeCloseBtn');

  if (!modal || !startBtn || !closeBtn) return;

  // Reset state
  fppUpgradeIsRunning = false;

  // Count hosts by upgrade type
  const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

  // Update type selector counts
  const crossVersionCountEl = document.getElementById('fppCrossVersionCount');
  const branchUpdateCountEl = document.getElementById('fppBranchUpdateCount');
  if (crossVersionCountEl) {
    crossVersionCountEl.textContent = crossVersionCount;
    crossVersionCountEl.setAttribute('data-count', crossVersionCount);
  }
  if (branchUpdateCountEl) {
    branchUpdateCountEl.textContent = branchUpdateCount;
    branchUpdateCountEl.setAttribute('data-count', branchUpdateCount);
  }

  // Update cross-version description with actual version
  const descEl = document.getElementById('fppCrossVersionDesc');
  if (descEl && latestFPPRelease && latestFPPRelease.latestVersion) {
    descEl.textContent = `Upgrade to v${latestFPPRelease.latestVersion}`;
  }

  // Enable/disable type options based on availability
  const crossVersionRadio = document.querySelector('input[name="fppUpgradeType"][value="crossVersion"]');
  const branchUpdateRadio = document.querySelector('input[name="fppUpgradeType"][value="branchUpdate"]');
  if (crossVersionRadio) crossVersionRadio.disabled = crossVersionCount === 0;
  if (branchUpdateRadio) branchUpdateRadio.disabled = branchUpdateCount === 0;

  // Only auto-select if current selection has no available hosts
  const currentTypeHasHosts = (fppSelectedUpgradeType === 'crossVersion' && crossVersionCount > 0)
    || (fppSelectedUpgradeType === 'branchUpdate' && branchUpdateCount > 0);

  if (!currentTypeHasHosts) {
    if (crossVersionCount > 0) {
      fppSelectedUpgradeType = 'crossVersion';
    } else if (branchUpdateCount > 0) {
      fppSelectedUpgradeType = 'branchUpdate';
    }
  }

  // Update radio button to match selection
  const selectedRadio = document.querySelector(`input[name="fppUpgradeType"][value="${fppSelectedUpgradeType}"]`);
  if (selectedRadio) selectedRadio.checked = true;

  // Build accordion for selected type
  buildFPPAccordion();

  startBtn.disabled = false;
  startBtn.style.display = '';
  startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
  closeBtn.disabled = false;
  closeBtn.classList.remove('btn-success');
  closeBtn.classList.add('btn-muted');
  closeBtn.innerHTML = 'Close';
  modal.style.display = 'flex';
}

/**
 * Close FPP upgrade modal
 * @param {Function} refreshAllStatus - Callback to refresh all cards
 */
export function closeFPPUpgradeModal(refreshAllStatus) {
  // Abort all running upgrades
  fppUpgradeStates.forEach(state => {
    if (state.abortController) {
      state.abortController.abort();
    }
  });
  fppUpgradeStates.clear();
  fppUpgradeIsRunning = false;

  const modal = document.getElementById('fppUpgradeModal');
  if (modal) modal.style.display = 'none';

  // Invalidate version cache so banners update immediately after upgrade
  invalidateCache('bulkUpdates');
  invalidateCache('localVersion');

  refreshAllStatus();
}

// =============================================================================
// Accordion Controls
// =============================================================================

/**
 * Toggle accordion item expansion
 * @param {string} address - Host address
 */
export function toggleFPPAccordion(address) {
  const safeId = escapeId(address);
  const item = document.getElementById(`fpp-item-${safeId}`);
  const state = fppUpgradeStates.get(address);
  if (!item || !state) return;

  state.expanded = !state.expanded;
  item.classList.toggle('expanded', state.expanded);
}

/**
 * Expand FPP accordion item
 * @param {string} address - Host address
 */
export function expandFPPItem(address) {
  const state = fppUpgradeStates.get(address);
  if (state) {
    state.expanded = true;
    const item = document.getElementById(`fpp-item-${escapeId(address)}`);
    item?.classList.add('expanded');
  }
}

/**
 * Expand all accordion items
 */
export function fppExpandAll() {
  fppUpgradeStates.forEach((state, address) => {
    state.expanded = true;
    document.getElementById(`fpp-item-${escapeId(address)}`)?.classList.add('expanded');
  });
}

/**
 * Collapse all accordion items
 */
export function fppCollapseAll() {
  fppUpgradeStates.forEach((state, address) => {
    state.expanded = false;
    document.getElementById(`fpp-item-${escapeId(address)}`)?.classList.remove('expanded');
  });
}

// =============================================================================
// Selection Controls
// =============================================================================

/**
 * Toggle selection for a host
 * @param {string} address - Host address
 */
export function toggleFPPSelection(address) {
  const safeId = escapeId(address);
  const checkbox = document.getElementById(`fpp-check-${safeId}`);
  const item = document.getElementById(`fpp-item-${safeId}`);
  const state = fppUpgradeStates.get(address);
  if (!state || !checkbox || !item) return;

  // Prevent selection of blocked hosts
  if (state.blockedByBranch) {
    checkbox.checked = false;
    return;
  }

  state.selected = checkbox.checked;
  item.classList.toggle('excluded', !state.selected);
  updateFPPSummary();
}

/**
 * Select all pending hosts (skips blocked hosts)
 */
export function fppSelectAll() {
  fppUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending' && !state.blockedByBranch) {
      state.selected = true;
      const safeId = escapeId(address);
      const checkbox = document.getElementById(`fpp-check-${safeId}`);
      const item = document.getElementById(`fpp-item-${safeId}`);
      if (checkbox) checkbox.checked = true;
      item?.classList.remove('excluded');
    }
  });
  updateFPPSummary();
}

/**
 * Deselect all pending hosts
 */
export function fppSelectNone() {
  fppUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending') {
      state.selected = false;
      const safeId = escapeId(address);
      const checkbox = document.getElementById(`fpp-check-${safeId}`);
      const item = document.getElementById(`fpp-item-${safeId}`);
      if (checkbox) checkbox.checked = false;
      item?.classList.add('excluded');
    }
  });
  updateFPPSummary();
}

// =============================================================================
// Status Updates
// =============================================================================

/**
 * Update status for a single host
 * @param {string} address - Host address
 * @param {string} status - Status value (pending, upgrading, success, error)
 * @param {string} icon - Font Awesome icon name
 * @param {string} text - Status text
 */
export function updateFPPStatus(address, status, icon, text) {
  const safeId = escapeId(address);
  const statusEl = document.getElementById(`fpp-status-${safeId}`);
  const state = fppUpgradeStates.get(address);
  if (statusEl && state) {
    state.status = status;
    statusEl.className = `fpp-accordion-status ${status}`;
    statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
  }
}

/**
 * Update summary count and start button state
 */
export function updateFPPSummary() {
  const countEl = document.getElementById('fppUpgradeCount');
  const startBtn = document.getElementById('fppUpgradeStartBtn');

  let pending = 0, upgrading = 0, success = 0, error = 0, selected = 0, blocked = 0;
  fppUpgradeStates.forEach(state => {
    if (state.status === 'blocked') {
      blocked++;
    } else if (state.status === 'pending') {
      pending++;
      if (state.selected) selected++;
    }
    else if (state.status === 'upgrading') upgrading++;
    else if (state.status === 'success') success++;
    else if (state.status === 'error') error++;
  });

  const total = fppUpgradeStates.size;
  const availableTotal = total - blocked;
  if (countEl) {
    if (upgrading > 0) {
      countEl.textContent = `${upgrading} upgrading, ${success + error} of ${availableTotal} complete`;
    } else if (success + error > 0) {
      countEl.textContent = `${success} succeeded, ${error} failed of ${availableTotal}`;
    } else if (blocked > 0 && availableTotal === 0) {
      countEl.textContent = `${blocked} blocked (branch update required)`;
    } else if (blocked > 0) {
      countEl.textContent = `${selected} of ${availableTotal} selected (${blocked} blocked)`;
    } else {
      countEl.textContent = `${selected} of ${total} selected`;
    }
  }

  // Disable start button if nothing selected
  if (startBtn && !fppUpgradeIsRunning) {
    startBtn.disabled = selected === 0;
  }
}

// =============================================================================
// Upgrade Execution
// =============================================================================

/**
 * Start single FPP upgrade with streaming output
 * @param {string} address - Host address
 */
export async function startSingleFPPUpgrade(address) {
  const safeId = escapeId(address);
  const logEl = document.getElementById(`fpp-log-${safeId}`);
  const item = document.getElementById(`fpp-item-${safeId}`);
  const state = fppUpgradeStates.get(address);
  if (!logEl || !state) return;

  // Use upgrade info stored when modal was opened
  const upgradeInfo = state.upgradeInfo;
  const isCrossVersion = fppSelectedUpgradeType === 'crossVersion';

  state.abortController = new AbortController();
  updateFPPStatus(address, 'upgrading', 'spinner fa-spin', 'Upgrading...');
  updateFPPSummary();
  logEl.textContent = '';

  // Build request body - include version for cross-version upgrades
  const requestBody = { host: address };
  if (isCrossVersion && upgradeInfo) {
    requestBody.version = 'v' + upgradeInfo.remoteVersion;
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/fpp/upgrade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(requestBody),
      signal: state.abortController.signal
    });

    if (!response.ok) throw new Error(`HTTP error: ${response.status}`);

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let fullOutput = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const chunk = decoder.decode(value, { stream: true });
      fullOutput += chunk;
      logEl.textContent += chunk;
      logEl.scrollTop = logEl.scrollHeight;
    }

    // Check if output contains error marker from backend
    const hasError = fullOutput.includes('=== ERROR:');
    if (hasError) {
      updateFPPStatus(address, 'error', 'times', 'Failed');
      state.expanded = true;
      item?.classList.add('expanded');
      return;
    }

    logEl.textContent += '\n\n=== Upgrade complete ===';

    // Auto-reboot after cross-version upgrade
    if (isCrossVersion) {
      logEl.textContent += '\n\n=== Initiating reboot for cross-version upgrade ===';
      updateFPPStatus(address, 'success', 'sync fa-spin', 'Rebooting...');

      try {
        if (address === 'localhost' || address === '127.0.0.1') {
          await fetch('/api/system/reboot', { method: 'GET' });
        } else {
          await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ host: address })
          });
        }
        logEl.textContent += '\nReboot command sent. System will restart shortly.';
      } catch (rebootErr) {
        // Reboot may cause connection loss - this is expected
        logEl.textContent += '\nReboot initiated (connection closed as expected).';
      }
      updateFPPStatus(address, 'success', 'check', 'Rebooting');
    } else {
      updateFPPStatus(address, 'success', 'check', 'Complete');
    }
  } catch (error) {
    if (error.name === 'AbortError') {
      logEl.textContent += '\n\n=== Upgrade cancelled ===';
      updateFPPStatus(address, 'error', 'ban', 'Cancelled');
    } else {
      logEl.textContent += `\n\n=== ERROR: ${error.message} ===`;
      updateFPPStatus(address, 'error', 'times', 'Failed');
      // Auto-expand on error
      state.expanded = true;
      item?.classList.add('expanded');
    }
  } finally {
    state.abortController = null;
    updateFPPSummary();
  }
}

/**
 * Start all selected FPP upgrades in parallel
 */
export async function startAllFPPUpgrades() {
  if (fppUpgradeIsRunning) return;

  // Get selected hosts
  const selectedHosts = [];
  fppUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending' && state.selected) {
      selectedHosts.push(address);
    }
  });

  if (selectedHosts.length === 0) return;

  fppUpgradeIsRunning = true;
  const startBtn = document.getElementById('fppUpgradeStartBtn');
  const closeBtn = document.getElementById('fppUpgradeCloseBtn');

  if (startBtn) {
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
  }
  if (closeBtn) closeBtn.disabled = true;

  // Disable checkboxes during upgrade
  fppUpgradeStates.forEach((state, address) => {
    const checkbox = document.getElementById(`fpp-check-${escapeId(address)}`);
    if (checkbox) checkbox.disabled = true;
  });

  // Expand first selected host
  if (selectedHosts[0]) expandFPPItem(selectedHosts[0]);

  // Start selected upgrades in parallel
  const upgradePromises = selectedHosts.map(address => startSingleFPPUpgrade(address));
  await Promise.allSettled(upgradePromises);

  fppUpgradeIsRunning = false;
  if (closeBtn) closeBtn.disabled = false;

  // Check if all are complete (no pending left)
  let hasPending = false;
  fppUpgradeStates.forEach(state => {
    if (state.status === 'pending') hasPending = true;
  });

  if (hasPending && startBtn) {
    // Some hosts weren't selected, allow restarting
    startBtn.disabled = false;
    startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
    // Re-enable checkboxes for remaining pending hosts
    fppUpgradeStates.forEach((state, address) => {
      if (state.status === 'pending') {
        const checkbox = document.getElementById(`fpp-check-${escapeId(address)}`);
        if (checkbox) checkbox.disabled = false;
      }
    });
  } else if (startBtn && closeBtn) {
    // All done - hide start button, make close green
    startBtn.style.display = 'none';
    closeBtn.classList.remove('btn-muted');
    closeBtn.classList.add('btn-success');
    closeBtn.innerHTML = '<i class="fas fa-check"></i> Done';
  }
  updateFPPSummary();
}

// =============================================================================
// Card Button Handlers
// =============================================================================

/**
 * Show FPP upgrade modal for a single host (from card button)
 * @param {string} address - Host address
 */
export function upgradeFPPSingle(address) {
  if (!hostsWithFPPUpdates.has(address)) {
    alert('No FPP update available.');
    return;
  }
  showFPPUpgradeModal();
  setTimeout(() => expandFPPItem(address), 100);
}

/**
 * Open FPP upgrade modal with cross-version type pre-selected
 * @param {string} address - Host address
 */
export function upgradeFPPCrossVersion(address) {
  const hostInfo = hostsWithFPPUpdates.get(address);
  if (!hostInfo || !hostInfo.crossVersion) {
    alert('No cross-version upgrade available for this host.');
    return;
  }

  fppSelectedUpgradeType = 'crossVersion';
  showFPPUpgradeModal();
  setTimeout(() => expandFPPItem(address), 100);
}

/**
 * Open FPP upgrade modal with branch update type pre-selected
 * @param {string} address - Host address
 */
export function upgradeFPPBranch(address) {
  const hostInfo = hostsWithFPPUpdates.get(address);
  if (!hostInfo || !hostInfo.branchUpdate) {
    alert('No branch update available for this host.');
    return;
  }

  fppSelectedUpgradeType = 'branchUpdate';
  showFPPUpgradeModal();
  setTimeout(() => expandFPPItem(address), 100);
}
