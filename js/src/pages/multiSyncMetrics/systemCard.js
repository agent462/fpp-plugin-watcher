/**
 * multiSyncMetrics/systemCard.js - Local system card updates
 *
 * Handles updating the main system card with:
 * - Sequence information
 * - Frame and time display
 * - Lifecycle metrics
 * - Remote mode drift metrics
 */

import { state, isPlayerMode, isRemoteMode, getLocalHostname } from './state.js';
import { formatTime, formatTimeSinceMs, getJitterClass } from './utils.js';

/**
 * Update the main system card with local status
 * @param {Object} status - Status from C++ plugin
 */
export async function updateSystemCard(status) {
  const hostnameEl = document.getElementById('systemHostname');
  if (hostnameEl) {
    hostnameEl.textContent = getLocalHostname();
  }

  const seqName = status.currentMasterSequence || '';
  const displayName = seqName.replace(/\.fseq$/i, '') || 'None';

  const seqEl = document.getElementById('systemSequence');
  if (seqEl) seqEl.textContent = displayName;

  const statusEl = document.getElementById('systemStatus');
  if (statusEl) statusEl.textContent = status.sequencePlaying ? 'Playing' : 'Idle';

  // Fetch sequence metadata if sequence changed (remotes have sequences too)
  if (seqName && seqName !== state.lastSequenceName) {
    state.lastSequenceName = seqName;
    try {
      const metaResp = await fetch(`/api/sequence/${encodeURIComponent(seqName)}/meta`);
      if (metaResp.ok) {
        state.sequenceMeta = await metaResp.json();
      } else {
        state.sequenceMeta = null;
      }
    } catch (e) {
      state.sequenceMeta = null;
    }
  } else if (!seqName) {
    state.sequenceMeta = null;
    state.lastSequenceName = null;
  }

  // Determine if actively syncing
  // For player: use sequencePlaying flag
  // For remote: check if receiving sync packets recently (within 5 seconds)
  const isActivelySyncing = isPlayerMode()
    ? status.sequencePlaying
    : (status.secondsSinceLastSync !== undefined && status.secondsSinceLastSync >= 0 && status.secondsSinceLastSync < 5);

  // Frame counter: current / total
  // Use localCurrentFrame from C++ plugin - this is the actual frame being played
  // Falls back to lastMasterFrame (from sync packets) if localCurrentFrame not available
  const frame = status.localCurrentFrame !== undefined && status.localCurrentFrame >= 0
    ? status.localCurrentFrame
    : (status.lastMasterFrame || 0);
  const totalFrames = state.sequenceMeta?.NumFrames || 0;

  const frameEl = document.getElementById('systemFrame');
  if (frameEl) {
    if (isActivelySyncing && totalFrames > 0) {
      frameEl.textContent = `${frame.toLocaleString()} / ${totalFrames.toLocaleString()}`;
    } else if (isActivelySyncing && frame > 0) {
      frameEl.textContent = frame.toLocaleString();
    } else {
      frameEl.textContent = '--';
    }
  }

  // Time: elapsed / total
  // For remote mode, use local FPP status seconds (actual playing time)
  // For player mode, use lastMasterSeconds from sync status
  const secs = isRemoteMode() && state.localFppStatus?.seconds_played !== undefined
    ? state.localFppStatus.seconds_played
    : (status.lastMasterSeconds || 0);
  const stepTime = state.sequenceMeta?.StepTime || 25;
  const totalSecs = totalFrames > 0 ? (totalFrames * stepTime / 1000) : 0;

  const timeEl = document.getElementById('systemTime');
  if (timeEl) {
    if (isActivelySyncing && totalSecs > 0) {
      timeEl.textContent = `${formatTime(secs)} / ${formatTime(totalSecs)}`;
    } else if (isActivelySyncing && secs > 0) {
      timeEl.textContent = formatTime(secs);
    } else {
      timeEl.textContent = '--';
    }
  }

  // Sequence metadata - show step time with calculated FPS (both modes)
  const stepTimeEl = document.getElementById('systemStepTime');
  if (stepTimeEl) {
    if (state.sequenceMeta) {
      const fps = Math.round(1000 / state.sequenceMeta.StepTime);
      stepTimeEl.textContent = `${state.sequenceMeta.StepTime}ms (${fps}fps)`;
    } else {
      stepTimeEl.textContent = '--';
    }
  }

  // Player mode only fields
  if (isPlayerMode()) {
    const channelsEl = document.getElementById('systemChannels');
    if (channelsEl) {
      channelsEl.textContent = state.sequenceMeta ? state.sequenceMeta.ChannelCount.toLocaleString() : '--';
    }

    const sentEl = document.getElementById('systemPacketsSent');
    if (sentEl) {
      sentEl.textContent = (status.totalPacketsSent || 0).toLocaleString();
    }
  }

  const recvEl = document.getElementById('systemPacketsReceived');
  if (recvEl) {
    recvEl.textContent = (status.totalPacketsReceived || 0).toLocaleString();
  }

  // Remote mode drift fields (only show when actively syncing)
  if (isRemoteMode()) {
    const avgDriftEl = document.getElementById('systemAvgDrift');
    if (avgDriftEl) {
      avgDriftEl.textContent = isActivelySyncing && status.avgFrameDrift !== undefined
        ? status.avgFrameDrift.toFixed(1) + ' frames'
        : '--';
    }

    const maxDriftEl = document.getElementById('systemMaxDrift');
    if (maxDriftEl) {
      const maxDrift = isActivelySyncing && status.maxFrameDrift !== undefined
        ? Math.abs(status.maxFrameDrift)
        : null;
      maxDriftEl.textContent = maxDrift !== null ? maxDrift.toFixed(1) + ' frames' : '--';
    }

    // Sync packet interval and jitter (only show when actively syncing)
    const syncIntervalEl = document.getElementById('systemSyncInterval');
    if (syncIntervalEl) {
      if (isActivelySyncing && status.avgSyncIntervalMs !== undefined && status.syncIntervalSamples > 0) {
        syncIntervalEl.textContent = status.avgSyncIntervalMs.toFixed(0) + 'ms';
      } else {
        syncIntervalEl.textContent = '--';
      }
    }

    const syncJitterEl = document.getElementById('systemSyncJitter');
    if (syncJitterEl) {
      if (isActivelySyncing && status.syncIntervalJitterMs !== undefined && status.syncIntervalSamples > 0) {
        const jitter = status.syncIntervalJitterMs;
        syncJitterEl.textContent = jitter.toFixed(1) + 'ms';
        // Color code: good <20ms, fair 20-50ms, poor >50ms
        syncJitterEl.classList.remove('status-good', 'status-warning', 'status-critical');
        const jitterClass = getJitterClass(jitter);
        if (jitterClass === 'good') {
          syncJitterEl.classList.add('status-good');
        } else if (jitterClass === 'warning') {
          syncJitterEl.classList.add('status-warning');
        } else {
          syncJitterEl.classList.add('status-critical');
        }
      } else {
        syncJitterEl.textContent = '--';
        syncJitterEl.classList.remove('status-good', 'status-warning', 'status-critical');
      }
    }
  }

  const lastSyncEl = document.getElementById('systemLastSync');
  if (lastSyncEl) {
    lastSyncEl.textContent = formatTimeSinceMs(status.millisecondsSinceLastSync);
  }
}

/**
 * Update lifecycle metrics display
 * @param {Object} status - Status from C++ plugin
 */
export function updateLifecycleMetrics(status) {
  const sent = status.packetsSent || {};
  const recv = status.packetsReceived || {};

  // Update lifecycle metrics
  const lc = status.lifecycle || {};

  const elements = {
    lcSeqOpen: lc.seqOpen || 0,
    lcSeqStart: lc.seqStart || 0,
    lcSeqStop: lc.seqStop || 0,
    lcMediaOpen: lc.mediaOpen || 0,
    lcMediaStart: lc.mediaStart || 0,
    lcMediaStop: lc.mediaStop || 0
  };

  for (const [id, value] of Object.entries(elements)) {
    const el = document.getElementById(id);
    if (el) el.textContent = value.toLocaleString();
  }

  // Show related packet counts (sent + received for player, received only for remote)
  const isRemote = isRemoteMode();
  const syncTotal = isRemote ? (recv.sync || 0) : (sent.sync || 0) + (recv.sync || 0);
  const mediaTotal = isRemote ? (recv.mediaSync || 0) : (sent.mediaSync || 0) + (recv.mediaSync || 0);
  const blankTotal = isRemote ? (recv.blank || 0) : (sent.blank || 0) + (recv.blank || 0);
  const cmdTotal = isRemote ? (recv.command || 0) : (sent.command || 0) + (recv.command || 0);
  const pluginTotal = isRemote ? (recv.plugin || 0) : (sent.plugin || 0) + (recv.plugin || 0);

  const packetElements = {
    lcSyncPackets: syncTotal,
    lcMediaPackets: mediaTotal,
    lcBlankPackets: blankTotal,
    lcCmdPackets: cmdTotal,
    lcPluginPackets: pluginTotal
  };

  for (const [id, value] of Object.entries(packetElements)) {
    const el = document.getElementById(id);
    if (el) el.textContent = value.toLocaleString();
  }
}
