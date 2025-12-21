/**
 * multiSyncMetrics/api.js - Data fetching functions for Multi-Sync Dashboard
 *
 * Handles all API calls for:
 * - Local C++ plugin status
 * - FPP status
 * - Comparison data
 * - Clock drift
 * - Network quality
 */

import { state, isPlayerMode, isRemoteMode, CONSECUTIVE_FAILURE_THRESHOLD } from './state.js';
import { updateLastRefresh, showPluginError, hidePluginError } from './utils.js';
import { updateSystemCard, updateLifecycleMetrics } from './systemCard.js';
import { updatePacketSummary, updateSyncSource, updateSyncHealth, updatePacketRate, updateLocalComparison } from './remoteMode.js';
import { renderRemoteCards, applyConsecutiveFailureThreshold } from './remoteCards.js';
import { renderSystemsPacketTable, sortAndRenderTable } from './packetTable.js';
import { renderLatencyJitterChart, renderPacketLossChart, updateQualityCard, showPingDisabledMessage, isPingEnabled } from './charts.js';
import { updateStats, updateIssues } from './issues.js';

/**
 * Load fast-changing data (every 2 seconds)
 * - Local C++ plugin status
 * - Local FPP status
 * - Local issues
 * - Remote comparison (real-time sync metrics)
 * - Network quality current status
 */
export async function loadFastData() {
  try {
    const spinnerIcon = document.querySelector('.msm-refresh-btn i');
    if (spinnerIcon) spinnerIcon.classList.add('fa-spin');

    // Load local status from C++ plugin and FPP status in parallel
    const [statusResp, fppStatusResp] = await Promise.all([
      fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/status'),
      fetch('/api/fppd/status')
    ]);

    if (!statusResp.ok) {
      showPluginError('C++ plugin not responding. Restart FPP to load the plugin.');
      return;
    }

    const status = await statusResp.json();
    state.localStatus = status;

    // Parse local FPP status (for remote mode frame display)
    if (fppStatusResp.ok) {
      state.localFppStatus = await fppStatusResp.json();
    }

    hidePluginError();
    await updateSystemCard(status);
    updateLifecycleMetrics(status);

    // Load issues from C++ plugin
    const issuesResp = await fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/issues');
    let localIssues = [];
    if (issuesResp.ok) {
      const issuesData = await issuesResp.json();
      localIssues = issuesData.issues || [];
    }

    // If player mode, also load comparison data (which will render systems table)
    if (isPlayerMode()) {
      await loadComparison(localIssues);
      // Load current network quality (not history)
      await loadNetworkQualityCurrent();
    } else {
      // Remote mode - show local issues, packet summary, and remote-specific cards
      updateIssues(localIssues);
      updatePacketSummary(status);
      updateSyncSource();
      updateSyncHealth(status, localIssues);
      updatePacketRate(status);
      await updateLocalComparison(status);
    }

    updateLastRefresh();
  } catch (e) {
    console.error('Error loading fast data:', e);
    showPluginError('Error connecting to multi-sync plugin: ' + e.message);
  } finally {
    const spinnerIcon = document.querySelector('.msm-refresh-btn i');
    if (spinnerIcon) spinnerIcon.classList.remove('fa-spin');
  }
}

/**
 * Load slow-changing data (every 30 seconds)
 * - FPP systems list (rarely changes)
 * - Clock drift data (changes slowly)
 * - Network quality history/charts
 */
export async function loadSlowData() {
  try {
    if (isPlayerMode()) {
      // Load FPP systems, clock drift, and chart data in parallel
      const [systemsResp] = await Promise.all([
        fetch('/api/fppd/multiSyncSystems'),
        loadClockDrift(),
        loadQualityCharts()
      ]);

      // Parse FPP systems
      if (systemsResp.ok) {
        const sysData = await systemsResp.json();
        state.fppSystems = sysData.systems || [];
      }
    } else {
      // Remote mode - just load FPP systems for sync source detection
      const systemsResp = await fetch('/api/fppd/multiSyncSystems');
      if (systemsResp.ok) {
        const sysData = await systemsResp.json();
        state.fppSystems = sysData.systems || [];
      }
    }

    state.slowDataLoaded = true;
  } catch (e) {
    console.error('Error loading slow data:', e);
  }
}

/**
 * Load all data (initial load and manual refresh)
 */
export async function loadAllData() {
  // Load slow data first if not yet loaded, then fast data
  if (!state.slowDataLoaded) {
    await loadSlowData();
  }
  await loadFastData();
}

/**
 * Load comparison data from PHP API
 * @param {Array} localIssues - Local issues from C++ plugin
 */
export async function loadComparison(localIssues) {
  try {
    const resp = await fetch('/api/plugin/fpp-plugin-watcher/multisync/comparison');
    if (!resp.ok) return;

    const data = await resp.json();
    if (!data.success) return;

    // Apply consecutive failure threshold to remotes
    // This prevents UI flickering from transient network issues
    const remotes = applyConsecutiveFailureThreshold(data.remotes);

    // Filter issues to exclude offline issues for hosts not yet at threshold
    const filteredIssues = data.issues.filter(issue => {
      if (issue.type === 'offline') {
        // Find the corresponding remote to check its failure count
        const remote = remotes.find(r => r.hostname === issue.host || r.address === issue.host);
        // Keep the issue only if the host is actually shown as offline
        return remote && !remote.online;
      }
      return true;
    });

    // Combine local issues with filtered comparison issues
    const allIssues = [...localIssues, ...filteredIssues];

    // Recalculate stats based on filtered remotes
    const onlineCount = remotes.filter(r => r.online).length;
    const pluginInstalledCount = remotes.filter(r => r.pluginInstalled).length;
    updateStats(remotes.length, onlineCount, pluginInstalledCount, allIssues.length);

    // Update issues
    updateIssues(allIssues);

    // Render remote cards
    renderRemoteCards(remotes);

    // Render systems packet metrics table (local + remotes)
    renderSystemsPacketTable(remotes, state.localStatus);
  } catch (e) {
    console.error('Error loading comparison:', e);
  }
}

/**
 * Load clock drift data from PHP API
 */
export async function loadClockDrift() {
  try {
    const resp = await fetch('/api/plugin/fpp-plugin-watcher/multisync/clock-drift');
    if (!resp.ok) return;

    const data = await resp.json();
    if (!data.success) return;

    // Build map of address -> drift data
    state.clockDriftData = {};
    (data.hosts || []).forEach(host => {
      state.clockDriftData[host.address] = {
        drift_ms: host.drift_ms,
        rtt_ms: host.rtt_ms,
        hasPlugin: host.hasPlugin
      };
    });

    // Re-render table with new clock drift data
    if (state.systemsData.length > 0) {
      sortAndRenderTable();
    }
  } catch (e) {
    console.error('Error loading clock drift:', e);
  }
}

/**
 * Load current network quality status (fast poll)
 */
export async function loadNetworkQualityCurrent() {
  // Check if ping is enabled
  if (!isPingEnabled()) {
    showPingDisabledMessage();
    return;
  }

  try {
    const resp = await fetch('/api/plugin/fpp-plugin-watcher/metrics/network-quality/current');
    if (!resp.ok) return;

    const data = await resp.json();
    if (!data.success) return;

    updateQualityCard(data);
  } catch (e) {
    console.error('Error loading network quality:', e);
  }
}

/**
 * Load network quality charts (slow poll)
 */
export async function loadQualityCharts() {
  // Check if ping is enabled
  if (!isPingEnabled()) {
    showPingDisabledMessage();
    return;
  }

  const timeRangeEl = document.getElementById('qualityTimeRange');
  const hours = timeRangeEl?.value || 6;

  try {
    const resp = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/network-quality/history?hours=${hours}`);
    if (!resp.ok) return;

    const data = await resp.json();
    if (!data.success || !data.chartData) return;

    renderLatencyJitterChart(data.chartData);
    renderPacketLossChart(data.chartData);
  } catch (e) {
    console.error('Error loading quality charts:', e);
  }
}

/**
 * Reset metrics on local and remote systems
 */
export async function resetMetrics() {
  // Get list of remotes with plugin installed
  const remotesWithPlugin = state.systemsData.filter(s => !s.isLocal && s.hasMetrics);
  const remoteCount = remotesWithPlugin.length;

  const msg = remoteCount > 0
    ? `Reset multi-sync metrics on this system and ${remoteCount} remote${remoteCount > 1 ? 's' : ''}?`
    : 'Reset all multi-sync metrics?';

  if (!confirm(msg)) return;

  try {
    // Reset local
    const requests = [
      fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/reset', { method: 'POST' })
    ];

    // Reset all remotes with plugin in parallel
    remotesWithPlugin.forEach(remote => {
      requests.push(
        fetch(`http://${remote.address}/api/plugin-apis/fpp-plugin-watcher/multisync/reset`, {
          method: 'POST',
          mode: 'cors'
        }).catch(e => console.warn(`Failed to reset ${remote.hostname}:`, e))
      );
    });

    await Promise.all(requests);
    await loadAllData();
  } catch (e) {
    console.error('Error resetting:', e);
  }
}
