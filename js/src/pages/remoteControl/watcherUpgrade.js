/**
 * Remote Control Page - Watcher Upgrade Module
 *
 * Streaming Watcher plugin upgrade modal with accordion UI and parallel upgrade support.
 */

import {
  escapeId,
  hostsWithWatcherUpdates,
  invalidateCache
} from './state.js';

// =============================================================================
// Module State
// =============================================================================

/** Watcher upgrade states by address */
export const watcherUpgradeStates = new Map();

/** Flag indicating if Watcher upgrade is running */
export let watcherUpgradeIsRunning = false;

// Setter
export function setWatcherUpgradeIsRunning(value) {
  watcherUpgradeIsRunning = value;
}

// =============================================================================
// Accordion UI
// =============================================================================

/**
 * Build Watcher accordion UI
 */
export function buildWatcherAccordion() {
  const accordion = document.getElementById('watcherAccordion');
  if (!accordion) return;

  // Reset state
  watcherUpgradeStates.clear();

  // Build accordion items from hostsWithWatcherUpdates
  let html = '';
  if (hostsWithWatcherUpdates.size === 0) {
    html = '<div style="text-align: center; padding: 2rem; color: #6c757d;"><i class="fas fa-info-circle"></i> No hosts need Watcher updates</div>';
  } else {
    hostsWithWatcherUpdates.forEach((info, addr) => {
      const safeId = escapeId(addr);
      watcherUpgradeStates.set(addr, {
        status: 'pending',
        abortController: null,
        expanded: false,
        selected: true
      });
      const versionDisplay = `${info.installedVersion || '?'} → ${info.latestVersion || '?'}`;
      html += `
        <div class="fpp-accordion-item" id="watcher-item-${safeId}" data-address="${addr}">
          <div class="fpp-accordion-header" onclick="page.toggleWatcherAccordion('${addr}')">
            <input type="checkbox" class="fpp-accordion-checkbox" id="watcher-check-${safeId}" checked onclick="event.stopPropagation(); page.toggleWatcherSelection('${addr}')">
            <div class="fpp-accordion-toggle"><i class="fas fa-chevron-right"></i></div>
            <div class="fpp-accordion-info">
              <span class="fpp-accordion-hostname">${info.hostname}</span>
              <span class="fpp-accordion-address">${addr}</span>
              <span class="fpp-accordion-version">${versionDisplay}</span>
            </div>
            <div class="fpp-accordion-status pending" id="watcher-status-${safeId}" onclick="event.stopPropagation()">
              <i class="fas fa-clock"></i> Pending
            </div>
          </div>
          <div class="fpp-accordion-body">
            <div class="fpp-accordion-log" id="watcher-log-${safeId}"></div>
          </div>
        </div>`;
    });
  }

  accordion.innerHTML = html;
  updateWatcherSummary();
}

// =============================================================================
// Modal Functions
// =============================================================================

/**
 * Show Watcher upgrade modal
 */
export function showWatcherUpgradeModal() {
  if (hostsWithWatcherUpdates.size < 1) return;

  const modal = document.getElementById('watcherUpgradeModal');
  const startBtn = document.getElementById('watcherUpgradeStartBtn');
  const closeBtn = document.getElementById('watcherUpgradeCloseBtn');

  if (!modal || !startBtn || !closeBtn) return;

  // Reset state
  watcherUpgradeIsRunning = false;

  // Build accordion
  buildWatcherAccordion();

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
 * Close Watcher upgrade modal
 * @param {Function} refreshAllStatus - Callback to refresh all cards
 */
export function closeWatcherUpgradeModal(refreshAllStatus) {
  // Abort all running upgrades
  watcherUpgradeStates.forEach(state => {
    if (state.abortController) {
      state.abortController.abort();
    }
  });
  watcherUpgradeStates.clear();
  watcherUpgradeIsRunning = false;

  const modal = document.getElementById('watcherUpgradeModal');
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
export function toggleWatcherAccordion(address) {
  const safeId = escapeId(address);
  const item = document.getElementById(`watcher-item-${safeId}`);
  const state = watcherUpgradeStates.get(address);
  if (!item || !state) return;

  state.expanded = !state.expanded;
  item.classList.toggle('expanded', state.expanded);
}

/**
 * Expand Watcher accordion item
 * @param {string} address - Host address
 */
export function expandWatcherItem(address) {
  const safeId = escapeId(address);
  const item = document.getElementById(`watcher-item-${safeId}`);
  const state = watcherUpgradeStates.get(address);
  if (item && state) {
    state.expanded = true;
    item.classList.add('expanded');
  }
}

/**
 * Expand all accordion items
 */
export function watcherExpandAll() {
  watcherUpgradeStates.forEach((state, address) => {
    state.expanded = true;
    document.getElementById(`watcher-item-${escapeId(address)}`)?.classList.add('expanded');
  });
}

/**
 * Collapse all accordion items
 */
export function watcherCollapseAll() {
  watcherUpgradeStates.forEach((state, address) => {
    state.expanded = false;
    document.getElementById(`watcher-item-${escapeId(address)}`)?.classList.remove('expanded');
  });
}

// =============================================================================
// Selection Controls
// =============================================================================

/**
 * Toggle selection for a host
 * @param {string} address - Host address
 */
export function toggleWatcherSelection(address) {
  const safeId = escapeId(address);
  const checkbox = document.getElementById(`watcher-check-${safeId}`);
  const item = document.getElementById(`watcher-item-${safeId}`);
  const state = watcherUpgradeStates.get(address);
  if (!state || !checkbox || !item) return;

  state.selected = checkbox.checked;
  item.classList.toggle('excluded', !state.selected);
  updateWatcherSummary();
}

/**
 * Select all pending hosts
 */
export function watcherSelectAll() {
  watcherUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending') {
      state.selected = true;
      const safeId = escapeId(address);
      const checkbox = document.getElementById(`watcher-check-${safeId}`);
      const item = document.getElementById(`watcher-item-${safeId}`);
      if (checkbox) checkbox.checked = true;
      item?.classList.remove('excluded');
    }
  });
  updateWatcherSummary();
}

/**
 * Deselect all pending hosts
 */
export function watcherSelectNone() {
  watcherUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending') {
      state.selected = false;
      const safeId = escapeId(address);
      const checkbox = document.getElementById(`watcher-check-${safeId}`);
      const item = document.getElementById(`watcher-item-${safeId}`);
      if (checkbox) checkbox.checked = false;
      item?.classList.add('excluded');
    }
  });
  updateWatcherSummary();
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
export function updateWatcherStatus(address, status, icon, text) {
  const safeId = escapeId(address);
  const statusEl = document.getElementById(`watcher-status-${safeId}`);
  const state = watcherUpgradeStates.get(address);
  if (statusEl && state) {
    state.status = status;
    statusEl.className = `fpp-accordion-status ${status}`;
    statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
  }
}

/**
 * Update summary count and start button state
 */
export function updateWatcherSummary() {
  const countEl = document.getElementById('watcherUpgradeCount');
  const startBtn = document.getElementById('watcherUpgradeStartBtn');

  let pending = 0, upgrading = 0, success = 0, error = 0, selected = 0;
  watcherUpgradeStates.forEach(state => {
    if (state.status === 'pending') {
      pending++;
      if (state.selected) selected++;
    }
    else if (state.status === 'upgrading') upgrading++;
    else if (state.status === 'success') success++;
    else if (state.status === 'error') error++;
  });

  const total = watcherUpgradeStates.size;
  if (countEl) {
    if (upgrading > 0) {
      countEl.textContent = `${upgrading} upgrading, ${success + error} of ${total} complete`;
    } else if (success + error > 0) {
      countEl.textContent = `${success} succeeded, ${error} failed of ${total}`;
    } else {
      countEl.textContent = `${selected} of ${total} selected`;
    }
  }

  // Disable start button if nothing selected
  if (startBtn && !watcherUpgradeIsRunning) {
    startBtn.disabled = selected === 0;
  }
}

// =============================================================================
// Upgrade Execution
// =============================================================================

/**
 * Start single Watcher upgrade with streaming output
 * @param {string} address - Host address
 */
export async function startSingleWatcherUpgrade(address) {
  const safeId = escapeId(address);
  const logEl = document.getElementById(`watcher-log-${safeId}`);
  const item = document.getElementById(`watcher-item-${safeId}`);
  const state = watcherUpgradeStates.get(address);
  if (!logEl || !state) return;

  state.abortController = new AbortController();
  updateWatcherStatus(address, 'upgrading', 'spinner fa-spin', 'Upgrading...');
  updateWatcherSummary();
  logEl.textContent = '';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/watcher/upgrade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: address }),
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
      updateWatcherStatus(address, 'error', 'times', 'Failed');
      state.expanded = true;
      item?.classList.add('expanded');
      return;
    }

    logEl.textContent += '\n\n=== Upgrade complete, restarting FPPD... ===';
    updateWatcherStatus(address, 'success', 'sync fa-spin', 'Restarting...');

    // Restart FPPD after successful upgrade
    try {
      await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: address })
      });
      logEl.textContent += '\nFPPD restart initiated.';
    } catch (restartErr) {
      logEl.textContent += '\nFPPD restart may have failed: ' + restartErr.message;
    }

    updateWatcherStatus(address, 'success', 'check', 'Complete');
  } catch (error) {
    if (error.name === 'AbortError') {
      logEl.textContent += '\n\n=== Upgrade cancelled ===';
      updateWatcherStatus(address, 'error', 'ban', 'Cancelled');
    } else {
      logEl.textContent += `\n\n=== ERROR: ${error.message} ===`;
      updateWatcherStatus(address, 'error', 'times', 'Failed');
      // Auto-expand on error
      state.expanded = true;
      item?.classList.add('expanded');
    }
  } finally {
    state.abortController = null;
    updateWatcherSummary();
  }
}

/**
 * Start all selected Watcher upgrades in parallel
 */
export async function startAllWatcherUpgrades() {
  if (watcherUpgradeIsRunning) return;

  // Get selected hosts
  const selectedHosts = [];
  watcherUpgradeStates.forEach((state, address) => {
    if (state.status === 'pending' && state.selected) {
      selectedHosts.push(address);
    }
  });

  if (selectedHosts.length === 0) return;

  watcherUpgradeIsRunning = true;
  const startBtn = document.getElementById('watcherUpgradeStartBtn');
  const closeBtn = document.getElementById('watcherUpgradeCloseBtn');

  if (startBtn) {
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
  }
  if (closeBtn) closeBtn.disabled = true;

  // Disable checkboxes during upgrade
  watcherUpgradeStates.forEach((state, address) => {
    const checkbox = document.getElementById(`watcher-check-${escapeId(address)}`);
    if (checkbox) checkbox.disabled = true;
  });

  // Start selected upgrades in parallel
  const upgradePromises = selectedHosts.map(address => startSingleWatcherUpgrade(address));
  await Promise.allSettled(upgradePromises);

  watcherUpgradeIsRunning = false;
  if (closeBtn) closeBtn.disabled = false;

  // Check if all are complete (no pending left)
  let hasPending = false;
  watcherUpgradeStates.forEach(state => {
    if (state.status === 'pending') hasPending = true;
  });

  if (hasPending && startBtn) {
    // Some hosts weren't selected, allow restarting
    startBtn.disabled = false;
    startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
    // Re-enable checkboxes for remaining pending hosts
    watcherUpgradeStates.forEach((state, address) => {
      if (state.status === 'pending') {
        const checkbox = document.getElementById(`watcher-check-${escapeId(address)}`);
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
  updateWatcherSummary();
}

// =============================================================================
// Card Button Handler
// =============================================================================

/**
 * Show Watcher upgrade modal for a single host (from card button)
 * @param {string} address - Host address
 */
export function upgradeWatcherSingle(address) {
  if (!hostsWithWatcherUpdates.has(address)) {
    alert('No Watcher update available.');
    return;
  }
  showWatcherUpgradeModal();
  setTimeout(() => expandWatcherItem(address), 100);
}
