/**
 * multiSyncMetrics/remoteCards.js - Remote system cards rendering
 *
 * Handles rendering the grid of remote system cards with:
 * - Status badges
 * - Sync metrics
 * - Frame drift
 * - Issues display
 */

import { state, CONSECUTIVE_FAILURE_THRESHOLD } from './state.js';
import { formatTimeSinceMs, getJitterClass, getDriftFrameClass } from './utils.js';
import { escapeHtml } from '../../core/utils.js';

/**
 * Apply consecutive failure threshold to remote status
 * Similar to FPP's multisync.php which requires 4 consecutive failures before marking unreachable.
 * We use 3 failures to prevent UI flickering from transient network issues.
 *
 * @param {Array} remotes - Array of remote data from comparison API
 * @returns {Array} - Modified remotes with failure threshold applied
 */
export function applyConsecutiveFailureThreshold(remotes) {
  return remotes.map(remote => {
    const addr = remote.address;

    if (remote.online) {
      // Host is online - reset failure counter and cache good state
      state.consecutiveFailures[addr] = 0;
      state.lastKnownGoodState[addr] = JSON.parse(JSON.stringify(remote));
      return remote;
    }

    // Host appears offline - increment failure counter
    state.consecutiveFailures[addr] = (state.consecutiveFailures[addr] || 0) + 1;

    // If we haven't reached threshold and have cached state, use cached state
    if (state.consecutiveFailures[addr] < CONSECUTIVE_FAILURE_THRESHOLD && state.lastKnownGoodState[addr]) {
      const cached = state.lastKnownGoodState[addr];
      // Keep the cached online state but mark metrics as potentially stale
      return {
        ...cached,
        _staleSinceFailure: state.consecutiveFailures[addr]
      };
    }

    // Threshold reached or no cached state - show as offline
    return remote;
  });
}

/**
 * Render remote system cards grid (Player Mode)
 * @param {Array} remotes - Array of remote system data
 */
export function renderRemoteCards(remotes) {
  const grid = document.getElementById('remotesGrid');
  if (!grid) return;

  if (!remotes || remotes.length === 0) {
    grid.innerHTML = `
      <div class="msm-empty" style="grid-column: 1/-1;">
        <i class="fas fa-satellite-dish"></i>
        <p>No remote systems found in multi-sync configuration</p>
      </div>
    `;
    return;
  }

  grid.innerHTML = remotes.map(remote => renderRemoteCard(remote)).join('');
}

/**
 * Render a single remote card
 * @param {Object} remote - Remote system data
 * @returns {string} HTML string for the card
 */
function renderRemoteCard(remote) {
  let cardClass = 'msm-remote-card';
  let badge = '';

  if (!remote.online) {
    cardClass += ' offline';
    badge = '<span class="msm-remote-badge offline">Offline</span>';
  } else if (!remote.pluginInstalled) {
    cardClass += ' no-plugin';
    badge = '<span class="msm-remote-badge no-plugin">No Plugin</span>';
  } else {
    badge = '<span class="msm-remote-badge online">Online</span>';
  }

  if (remote.maxSeverity >= 3) cardClass += ' critical';
  else if (remote.maxSeverity >= 2) cardClass += ' has-issues';

  const metricsHtml = renderRemoteMetrics(remote);
  const issuesHtml = renderRemoteIssues(remote);

  return `
    <div class="${cardClass}">
      <div class="msm-remote-header">
        <div>
          <div class="msm-remote-hostname">${escapeHtml(remote.hostname)}</div>
          <div class="msm-remote-address"><a href="http://${escapeHtml(remote.address)}/" target="_blank" class="msm-host-link">${escapeHtml(remote.address)}</a></div>
        </div>
        ${badge}
      </div>
      <div class="msm-remote-body">${metricsHtml}</div>
      ${issuesHtml}
    </div>
  `;
}

/**
 * Render metrics for a remote card
 * @param {Object} remote - Remote system data
 * @returns {string} HTML string for metrics
 */
function renderRemoteMetrics(remote) {
  if (!remote.online) {
    return `<div class="msm-remote-message"><i class="fas fa-plug"></i> Unreachable</div>`;
  }

  if (!remote.pluginInstalled) {
    return `<div class="msm-remote-message"><i class="fas fa-info-circle"></i> Watcher plugin not installed</div>`;
  }

  const m = remote.metrics || {};
  const fpp = remote.fppStatus || {};

  // Use FPP status for actual sequence/status, watcher plugin for frame
  const actualSeq = fpp.sequence || m.currentMasterSequence || '--';
  const actualStatus = fpp.status || (m.sequencePlaying ? 'playing' : 'idle');
  const statusDisplay = actualStatus === 'playing' ? 'Playing' : 'Idle';

  // Use localCurrentFrame from watcher plugin - this is the remote's actual playing frame
  // Falls back to lastMasterFrame (from sync packets) for systems without updated plugin
  const frameValue = m.localCurrentFrame !== undefined && m.localCurrentFrame >= 0
    ? m.localCurrentFrame
    : m.lastMasterFrame;
  const currentFrame = (actualStatus === 'playing' && frameValue !== undefined)
    ? frameValue.toLocaleString()
    : '--';

  const pkts = m.totalPacketsReceived !== undefined ? m.totalPacketsReceived.toLocaleString() : '--';
  const lastSync = m.millisecondsSinceLastSync !== undefined ? formatTimeSinceMs(m.millisecondsSinceLastSync) : '--';

  // Check for missing sequence scenario
  const syncSaysPlaying = m.sequencePlaying;
  const actuallyPlaying = actualStatus === 'playing';

  // Drift metrics (only show when playing)
  const avgDriftNum = actuallyPlaying && m.avgFrameDrift !== undefined ? Math.abs(m.avgFrameDrift) : null;
  const avgDrift = avgDriftNum !== null ? avgDriftNum.toFixed(1) : '--';
  const maxDrift = actuallyPlaying && m.maxFrameDrift !== undefined ? Math.abs(m.maxFrameDrift) : null;

  // Sync interval and jitter metrics (only show when playing)
  const syncInterval = actuallyPlaying && m.avgSyncIntervalMs !== undefined && m.syncIntervalSamples > 0
    ? m.avgSyncIntervalMs.toFixed(0) + 'ms' : '--';
  const syncJitter = actuallyPlaying && m.syncIntervalJitterMs !== undefined && m.syncIntervalSamples > 0
    ? m.syncIntervalJitterMs.toFixed(1) + 'ms' : '--';

  // Calculate sync packet rate from interval (sequence sync only, not media)
  const syncRate = actuallyPlaying && m.avgSyncIntervalMs !== undefined && m.avgSyncIntervalMs > 0 && m.syncIntervalSamples > 0
    ? (1000 / m.avgSyncIntervalMs).toFixed(1) + '/s' : '--';

  // Jitter class
  let syncJitterClass = '';
  if (actuallyPlaying && m.syncIntervalJitterMs !== undefined && m.syncIntervalSamples > 0) {
    syncJitterClass = getJitterClass(m.syncIntervalJitterMs);
  }

  // Use avg drift for alerting (max can spike on FPP restart)
  let driftClass = '';
  if (avgDriftNum !== null) {
    driftClass = getDriftFrameClass(avgDriftNum);
  }

  let statusClass = actuallyPlaying ? 'good' : '';
  if (syncSaysPlaying && !actuallyPlaying) {
    statusClass = 'critical'; // Sync says playing but FPP isn't
  }

  return `
    <div class="msm-remote-metrics">
      <div><span class="msm-remote-metric-label">Received Sequence</span><br><span class="msm-remote-metric-value">${escapeHtml(actualSeq) || '(none)'}</span></div>
      <div><span class="msm-remote-metric-label">Status</span><br><span class="msm-remote-metric-value ${statusClass}">${statusDisplay}</span></div>
      <div><span class="msm-remote-metric-label">Packets Recv</span><br><span class="msm-remote-metric-value">${pkts}</span></div>
      <div><span class="msm-remote-metric-label">Frame</span><br><span class="msm-remote-metric-value msm-mono">${currentFrame}</span></div>
      <div><span class="msm-remote-metric-label">Seq Sync Rate</span><br><span class="msm-remote-metric-value">${syncRate}</span></div>
      <div><span class="msm-remote-metric-label">Seq Sync Interval</span><br><span class="msm-remote-metric-value">${syncInterval}</span></div>
      <div><span class="msm-remote-metric-label">Seq Sync Jitter</span><br><span class="msm-remote-metric-value ${syncJitterClass}">${syncJitter}</span></div>
      <div><span class="msm-remote-metric-label">Last Sync</span><br><span class="msm-remote-metric-value">${lastSync}</span></div>
      <div><span class="msm-remote-metric-label">Avg Frame Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${avgDrift}f</span></div>
      <div><span class="msm-remote-metric-label">Max Frame Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${maxDrift !== null ? maxDrift + 'f' : '--'}</span></div>
    </div>
  `;
}

/**
 * Render issues for a remote card
 * @param {Object} remote - Remote system data
 * @returns {string} HTML string for issues
 */
function renderRemoteIssues(remote) {
  if (!remote.issues || remote.issues.length === 0) return '';

  // Filter out no_plugin issues
  const relevantIssues = remote.issues.filter(i => i.type !== 'no_plugin');
  if (relevantIssues.length === 0) return '';

  const issueClass = remote.maxSeverity >= 3 ? 'critical' : '';
  const texts = relevantIssues.map(i => i.description).join('; ');

  return `<div class="msm-remote-issues ${issueClass}"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(texts)}</div>`;
}
