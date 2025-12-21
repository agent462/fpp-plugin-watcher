/**
 * Remote Control Page - Actions Module
 *
 * User action handlers for test mode, restart, reboot, and plugin upgrades.
 */

import { pendingReboot, setPendingReboot, config } from './state.js';
import { fetchSystemStatus } from './api.js';
import { updateCardUI } from './cardRenderer.js';

// =============================================================================
// Test Mode Actions
// =============================================================================

/**
 * Toggle local channel test mode for a host
 * @param {string} address - Host address
 * @param {boolean} enable - Enable or disable test mode
 */
export async function toggleTestMode(address, enable) {
  const isLocal = address === 'localhost';
  const toggle = document.getElementById(`testmode-${address}`);
  if (!toggle) return;

  toggle.disabled = true;

  try {
    // Get channel range
    let channelRange = "1-8388608";
    if (enable) {
      try {
        const infoUrl = isLocal
          ? '/api/system/info'
          : `/api/plugin/fpp-plugin-watcher/remote/sysInfo?host=${encodeURIComponent(address)}`;
        const infoResponse = await fetch(infoUrl);
        if (infoResponse.ok) {
          const infoData = await infoResponse.json();
          const info = isLocal ? infoData : (infoData.data || infoData);
          if (info.channelRanges) {
            const parts = info.channelRanges.split('-');
            if (parts.length === 2) {
              channelRange = `${parseInt(parts[0]) + 1}-${parseInt(parts[1]) + 1}`;
            }
          }
        }
      } catch (e) {
        // Use default channel range
      }
    }

    const command = enable ? 'Test Start' : 'Test Stop';
    const args = enable ? ["1000", "RGB Cycle", channelRange, "R-G-B"] : [];

    if (isLocal) {
      const response = await fetch('/api/command', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ command, args })
      });
      if (!response.ok) throw new Error('Failed to toggle test mode');
    } else {
      const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/command', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          host: address,
          command,
          multisyncCommand: false,
          multisyncHosts: "",
          args
        })
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.error || 'Failed to toggle test mode');
    }

    // Refresh card after short delay
    setTimeout(async () => {
      const result = await fetchSystemStatus(address);
      updateCardUI(address, result);
    }, 500);
  } catch (error) {
    toggle.checked = !enable;
    alert(`Failed to toggle test mode: ${error.message}`);
  } finally {
    toggle.disabled = false;
  }
}

/**
 * Toggle MultiSync test mode (broadcasts to all sync'd systems)
 * Player mode only
 * @param {boolean} enable - Enable or disable test mode
 */
export async function toggleMultiSyncTestMode(enable) {
  const toggle = document.getElementById('testmode-multisync-localhost');
  const localToggle = document.getElementById('testmode-localhost');
  if (!toggle) return;

  toggle.disabled = true;

  try {
    // Get channel range from local system
    let channelRange = "1-8388608";
    if (enable) {
      try {
        const infoResponse = await fetch('/api/system/info');
        if (infoResponse.ok) {
          const info = await infoResponse.json();
          if (info.channelRanges) {
            const parts = info.channelRanges.split('-');
            if (parts.length === 2) {
              channelRange = `${parseInt(parts[0]) + 1}-${parseInt(parts[1]) + 1}`;
            }
          }
        }
      } catch (e) {
        // Use default channel range
      }
    }

    const command = enable ? 'Test Start' : 'Test Stop';
    const args = enable ? ["1000", "RGB Cycle", channelRange, "R-G-B"] : [];

    // Send command with multisyncCommand: true to broadcast to all sync'd systems
    const response = await fetch('/api/command', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ command, multisyncCommand: true, multisyncHosts: "", args })
    });

    if (!response.ok) throw new Error('Failed to toggle multisync test mode');

    // Update local toggle to match (since multisync affects local too)
    if (localToggle) {
      localToggle.checked = enable;
    }

    // Note: Caller should trigger refreshAllStatus after 1000ms delay
  } catch (error) {
    toggle.checked = !enable;
    alert(`Failed to toggle multisync test mode: ${error.message}`);
  } finally {
    toggle.disabled = false;
  }
}

// =============================================================================
// Restart Actions
// =============================================================================

/**
 * Restart FPPD on a host
 * @param {string} address - Host address
 */
export async function restartFppd(address) {
  const isLocal = address === 'localhost';
  const btn = document.getElementById(`restart-btn-${address}`);
  if (!btn) return;

  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restarting...';
  btn.disabled = true;

  try {
    if (isLocal) {
      const response = await fetch('/api/fppd/restart', { method: 'GET' });
      if (!response.ok) throw new Error('Failed to restart FPPD');
    } else {
      const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: address })
      });
      const data = await response.json();
      if (!data.success) throw new Error(data.error || 'Failed to restart FPPD');
    }

    btn.innerHTML = '<i class="fas fa-check"></i> Restarted!';
    setTimeout(async () => {
      btn.innerHTML = originalHtml;
      btn.disabled = false;
      const result = await fetchSystemStatus(address);
      updateCardUI(address, result);
    }, 3000);
  } catch (error) {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
    alert(`Failed to restart FPPD: ${error.message}`);
  }
}

// =============================================================================
// Reboot Actions
// =============================================================================

/**
 * Show reboot confirmation dialog
 * @param {string} address - Host address
 * @param {string} hostname - Host display name
 */
export function confirmReboot(address, hostname) {
  setPendingReboot({ address, hostname });
  const messageEl = document.getElementById('confirmMessage');
  const dialog = document.getElementById('confirmDialog');
  if (messageEl) {
    messageEl.textContent = `Are you sure you want to reboot "${hostname}" (${address})? This will take the system offline temporarily.`;
  }
  if (dialog) {
    dialog.style.display = 'flex';
  }
}

/**
 * Close reboot confirmation dialog
 */
export function closeConfirmDialog() {
  const dialog = document.getElementById('confirmDialog');
  if (dialog) {
    dialog.style.display = 'none';
  }
  setPendingReboot(null);
}

/**
 * Execute pending reboot
 */
export async function executeReboot() {
  if (!pendingReboot) return;

  const { address } = pendingReboot;
  const isLocal = address === 'localhost';
  closeConfirmDialog();

  const btn = document.getElementById(`reboot-btn-${address}`);
  const originalHtml = btn?.innerHTML || '<i class="fas fa-power-off"></i> Reboot';
  if (btn) {
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rebooting...';
    btn.disabled = true;
  }

  try {
    if (isLocal) {
      await fetch('/api/system/reboot', { method: 'GET' });
    } else {
      await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ host: address })
      });
    }

    // Mark card as offline/rebooting
    const card = document.getElementById(`card-${address}`);
    const statusEl = document.getElementById(`status-${address}`);
    if (card) card.classList.add('offline');
    if (statusEl) {
      statusEl.innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';
    }

    // For remote hosts, poll for recovery
    if (!isLocal) {
      let attempts = 0;
      const checkInterval = setInterval(async () => {
        if (++attempts > 60) {
          clearInterval(checkInterval);
          if (btn) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
          }
          return;
        }
        const result = await fetchSystemStatus(address);
        if (result.success) {
          clearInterval(checkInterval);
          updateCardUI(address, result);
          if (btn) {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
          }
        }
      }, 2000);
    }
  } catch (error) {
    // For localhost, connection loss is expected
    const card = document.getElementById(`card-${address}`);
    const statusEl = document.getElementById(`status-${address}`);
    if (card) card.classList.add('offline');
    if (statusEl) {
      statusEl.innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';
    }
    if (!isLocal && btn) {
      btn.innerHTML = originalHtml;
      btn.disabled = false;
    }
  }
}

// =============================================================================
// Connectivity Actions
// =============================================================================

/**
 * Clear connectivity reset state for a host
 * @param {string} address - Host address
 */
export async function clearResetState(address) {
  const isLocal = address === 'localhost';
  const btn = document.getElementById(`connectivity-clear-btn-${address}`);
  if (!btn) return;

  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  try {
    const url = isLocal
      ? '/api/plugin/fpp-plugin-watcher/connectivity/state/clear'
      : '/api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear';
    const options = isLocal
      ? { method: 'POST' }
      : {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ host: address })
        };

    const response = await fetch(url, options);
    const data = await response.json();
    if (!data.success) throw new Error(data.error || 'Failed to clear reset state');

    setTimeout(async () => {
      const result = await fetchSystemStatus(address);
      updateCardUI(address, result);
    }, 1000);
  } catch (error) {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
    alert(`Failed to clear reset state: ${error.message}`);
  }
}

// =============================================================================
// Plugin Upgrade Actions
// =============================================================================

/**
 * Upgrade a non-Watcher plugin
 * @param {string} address - Host address
 * @param {string} pluginRepoName - Plugin repository name
 */
export async function upgradePlugin(address, pluginRepoName) {
  const btn = document.getElementById(`upgrade-btn-${address}-${pluginRepoName}`);
  const item = document.getElementById(`upgrade-item-${address}-${pluginRepoName}`);
  if (!btn) return;

  const originalHtml = btn.innerHTML;

  if (!confirm(`Upgrade ${pluginRepoName} on ${address}?`)) return;

  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  btn.disabled = true;

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/upgrade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: address, plugin: pluginRepoName })
    });
    const data = await response.json();
    if (!data.success) throw new Error(data.error || 'Upgrade failed');

    btn.innerHTML = '<i class="fas fa-check"></i> Done';
    if (item) setTimeout(() => { item.style.opacity = '0.5'; }, 500);

    setTimeout(async () => {
      const result = await fetchSystemStatus(address);
      updateCardUI(address, result);
    }, 3000);
  } catch (error) {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
    alert(`Upgrade failed: ${error.message}`);
  }
}

// =============================================================================
// Local System Action Wrappers
// =============================================================================

/**
 * Restart FPPD on local system
 */
export function restartLocalFppd() {
  restartFppd('localhost');
}

/**
 * Show reboot confirmation for local system
 */
export function confirmLocalReboot() {
  confirmReboot('localhost', config.localHostname);
}

/**
 * Clear connectivity reset state for local system
 */
export function clearLocalResetState() {
  clearResetState('localhost');
}
