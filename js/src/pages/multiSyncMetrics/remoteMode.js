/**
 * multiSyncMetrics/remoteMode.js - Remote mode specific UI updates
 *
 * Handles updates specific to remote mode:
 * - Packet summary card
 * - Sync source card
 * - Sync health badge
 * - Packet rate display
 * - Local vs sync comparison
 */

import { state } from './state.js';

/**
 * Update packet summary card (Remote Mode)
 * @param {Object} status - Status from C++ plugin
 */
export function updatePacketSummary(status) {
  const recv = status.packetsReceived || {};
  const totalReceived = status.totalPacketsReceived || 0;

  const elements = {
    summaryTotalReceived: totalReceived,
    summarySyncReceived: recv.sync || 0,
    summaryMediaReceived: recv.mediaSync || 0,
    summaryCmdReceived: recv.command || 0
  };

  for (const [id, value] of Object.entries(elements)) {
    const el = document.getElementById(id);
    if (el) el.textContent = value.toLocaleString();
  }
}

/**
 * Update sync source card (Remote Mode)
 * Shows which player this remote is syncing from
 */
export function updateSyncSource() {
  const hostnameEl = document.getElementById('syncSourceHostname');
  const ipEl = document.getElementById('syncSourceIP');
  const statusEl = document.getElementById('syncSourceStatus');

  if (!hostnameEl) return;

  // Find player system from fppSystems
  const player = state.fppSystems.find(s => s.fppModeString === 'player' || s.fppMode === 2);

  if (player) {
    hostnameEl.textContent = player.hostname || 'Unknown';
    ipEl.textContent = player.address || '--';

    // Check if player was recently seen (within 60 seconds)
    const lastSeen = player.lastSeen ? new Date(player.lastSeen * 1000) : null;
    const now = new Date();
    const secondsAgo = lastSeen ? Math.floor((now - lastSeen) / 1000) : null;

    if (secondsAgo !== null && secondsAgo < 60) {
      statusEl.textContent = 'Online';
      statusEl.className = 'msm-sync-source-value status-good';
    } else if (secondsAgo !== null) {
      statusEl.textContent = `Last seen ${secondsAgo}s ago`;
      statusEl.className = 'msm-sync-source-value status-warning';
    } else {
      statusEl.textContent = 'Unknown';
      statusEl.className = 'msm-sync-source-value';
    }
  } else {
    hostnameEl.textContent = 'No player found';
    ipEl.textContent = '--';
    statusEl.textContent = 'Not detected';
    statusEl.className = 'msm-sync-source-value status-warning';
  }
}

/**
 * Update sync health badge (Remote Mode)
 * @param {Object} status - Status from C++ plugin
 * @param {Array} issues - Array of issues
 */
export function updateSyncHealth(status, issues) {
  const badge = document.getElementById('syncHealthBadge');
  if (!badge) return;

  const icon = badge.querySelector('i');
  const text = badge.querySelector('span');

  const secondsSinceSync = status.secondsSinceLastSync ?? -1;
  const avgDrift = Math.abs(status.avgFrameDrift ?? 0);
  const hasIssues = issues && issues.length > 0;
  const hasCriticalIssues = issues && issues.some(i => i.severity >= 3);
  // Check if player is actively playing (from sync packet data)
  const isPlayerPlaying = status.sequencePlaying === true;

  let health = 'good';
  let healthText = 'Healthy';

  // Only flag old sync packets as an issue if the player is actively playing
  // When player is idle, not receiving sync packets is expected behavior
  const syncPacketIssue = isPlayerPlaying && secondsSinceSync > 30;
  const syncPacketWarning = isPlayerPlaying && secondsSinceSync > 10;

  if (hasCriticalIssues || syncPacketIssue || avgDrift > 10) {
    health = 'critical';
    healthText = 'Critical';
  } else if (hasIssues || syncPacketWarning || avgDrift > 5) {
    health = 'warning';
    healthText = 'Warning';
  } else if (secondsSinceSync < 0 || status.totalPacketsReceived === 0) {
    health = 'unknown';
    healthText = 'No Data';
  }

  badge.className = `msm-sync-health msm-sync-health-${health}`;
  if (text) text.textContent = healthText;
}

/**
 * Update packet rate display (Remote Mode)
 * @param {Object} status - Status from C++ plugin
 */
export function updatePacketRate(status) {
  const rateEl = document.getElementById('systemPacketRate');
  if (!rateEl) return;

  const currentCount = status.totalPacketsReceived || 0;
  const now = Date.now();

  if (state.lastPacketTime !== null && state.lastPacketCount > 0) {
    const elapsed = (now - state.lastPacketTime) / 1000; // seconds
    if (elapsed > 0) {
      const packets = currentCount - state.lastPacketCount;
      state.packetRate = packets / elapsed;
    }
  }

  state.lastPacketCount = currentCount;
  state.lastPacketTime = now;

  if (state.packetRate > 0) {
    rateEl.textContent = `${state.packetRate.toFixed(1)}/sec`;
  } else {
    rateEl.textContent = '--';
  }
}

/**
 * Update local vs sync comparison card (Remote Mode)
 * @param {Object} status - Status from C++ plugin
 */
export async function updateLocalComparison(status) {
  const localSeqEl = document.getElementById('compLocalSeq');
  const syncSeqEl = document.getElementById('compSyncSeq');
  const localStatusEl = document.getElementById('compLocalStatus');
  const syncStatusEl = document.getElementById('compSyncStatus');
  const compStatusEl = document.getElementById('comparisonStatus');

  if (!localSeqEl) return;

  // Fetch local FPP status
  try {
    const resp = await fetch('/api/fppd/status');
    if (resp.ok) {
      state.localFppStatus = await resp.json();
    }
  } catch (e) {
    console.error('Error fetching local FPP status:', e);
  }

  // Sync packet data
  const syncSeq = (status.currentMasterSequence || '').replace(/\.fseq$/i, '') || '(none)';
  const syncPlaying = status.sequencePlaying ? 'Playing' : 'Idle';

  syncSeqEl.textContent = syncSeq;
  syncStatusEl.textContent = syncPlaying;

  // Local FPP data
  let localSeq = '(none)';
  let localPlaying = 'Idle';
  let mismatches = 0;

  if (state.localFppStatus) {
    localSeq = (state.localFppStatus.current_sequence || '').replace(/\.fseq$/i, '') || '(none)';
    localPlaying = state.localFppStatus.status_name === 'playing' ? 'Playing' : 'Idle';
  }

  localSeqEl.textContent = localSeq;
  localStatusEl.textContent = localPlaying;

  // Check for mismatches (sequence and status only - media is optional for remotes)
  const seqMatch = localSeq === syncSeq || (localSeq === '(none)' && syncSeq === '(none)');
  const statusMatch = localPlaying === syncPlaying;

  // Update vs indicators
  updateComparisonVs('compLocalSeq', 'compSyncSeq', seqMatch);
  updateComparisonVs('compLocalStatus', 'compSyncStatus', statusMatch);

  if (!seqMatch) mismatches++;
  if (!statusMatch) mismatches++;

  // Update overall status
  if (mismatches === 0) {
    compStatusEl.textContent = 'Match';
    compStatusEl.className = 'msm-comparison-status status-good';
  } else {
    compStatusEl.textContent = `${mismatches} Mismatch${mismatches > 1 ? 'es' : ''}`;
    compStatusEl.className = 'msm-comparison-status status-critical';
  }
}

/**
 * Update comparison vs indicator between two elements
 * @param {string} localId - ID of local element
 * @param {string} syncId - ID of sync element
 * @param {boolean} match - Whether values match
 */
export function updateComparisonVs(localId, syncId, match) {
  const localEl = document.getElementById(localId);
  const syncEl = document.getElementById(syncId);

  if (!localEl || !syncEl) return;

  if (match) {
    localEl.classList.remove('mismatch');
    syncEl.classList.remove('mismatch');
    // Find and update the vs element
    const row = localEl.closest('.msm-comparison-row');
    if (row) {
      const vs = row.querySelector('.msm-comparison-vs');
      if (vs) {
        vs.textContent = '=';
        vs.classList.remove('mismatch');
      }
    }
  } else {
    localEl.classList.add('mismatch');
    syncEl.classList.add('mismatch');
    const row = localEl.closest('.msm-comparison-row');
    if (row) {
      const vs = row.querySelector('.msm-comparison-vs');
      if (vs) {
        vs.textContent = '\u2260'; // Not equal sign
        vs.classList.add('mismatch');
      }
    }
  }
}
