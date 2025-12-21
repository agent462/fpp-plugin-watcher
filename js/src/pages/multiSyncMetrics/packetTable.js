/**
 * multiSyncMetrics/packetTable.js - Packet metrics table
 *
 * Handles the sortable table showing packet metrics for all systems:
 * - Local system (player)
 * - Remote systems with plugin
 * - FPP systems without plugin
 */

import { state, isPlayerMode, isRemoteMode, getLocalHostname } from './state.js';
import { getDriftClass } from './utils.js';
import { escapeHtml } from '../../core/utils.js';

/**
 * Build and render the systems packet metrics table
 * @param {Array} remotes - Array of remote system data from comparison API
 * @param {Object} local - Local status from C++ plugin
 */
export function renderSystemsPacketTable(remotes, local) {
  const tbody = document.getElementById('systemsPacketBody');
  if (!tbody) return;

  // Build data array for sorting
  state.systemsData = [];

  // Find local system in fppSystems to get type info
  const localFpp = state.fppSystems.find(s => s.local === 1) || {};
  const localSent = local?.packetsSent || {};
  const localRecv = local?.packetsReceived || {};
  const localHostname = getLocalHostname();

  // Add local system (player is the time reference)
  state.systemsData.push({
    isLocal: true,
    status: 'local',
    hostname: localHostname,
    address: localFpp.address || '127.0.0.1',
    type: localFpp.type || 'FPP',
    mode: isPlayerMode() ? 'Player' : (isRemoteMode() ? 'Remote' : localFpp.fppModeString || '--'),
    syncSent: localSent.sync || 0,
    syncRecv: localRecv.sync || 0,
    mediaSent: localSent.mediaSync || 0,
    mediaRecv: localRecv.mediaSync || 0,
    blankSent: localSent.blank || 0,
    blankRecv: localRecv.blank || 0,
    pluginSent: localSent.plugin || 0,
    pluginRecv: localRecv.plugin || 0,
    hasMetrics: true
  });

  // Add remote systems from comparison data
  if (remotes && remotes.length > 0) {
    remotes.forEach(remote => {
      const fppInfo = state.fppSystems.find(s => s.address === remote.address) || {};
      const m = remote.metrics || {};
      const sent = m.packetsSent || {};
      const recv = m.packetsReceived || {};

      state.systemsData.push({
        isLocal: false,
        status: !remote.online ? 'offline' : (!remote.pluginInstalled ? 'no-plugin' : 'online'),
        hostname: remote.hostname || fppInfo.hostname || remote.address,
        address: remote.address,
        type: fppInfo.type || '--',
        mode: fppInfo.fppModeString || '--',
        syncSent: sent.sync || 0,
        syncRecv: recv.sync || 0,
        mediaSent: sent.mediaSync || 0,
        mediaRecv: recv.mediaSync || 0,
        blankSent: sent.blank || 0,
        blankRecv: recv.blank || 0,
        pluginSent: sent.plugin || 0,
        pluginRecv: recv.plugin || 0,
        hasMetrics: remote.online && remote.pluginInstalled
      });
    });
  }

  // Add any FPP systems not in comparison (bridge devices, etc.)
  state.fppSystems.forEach(sys => {
    if (sys.local === 1) return;
    if (state.systemsData.some(s => s.address === sys.address)) return;

    state.systemsData.push({
      isLocal: false,
      status: 'unknown',
      hostname: sys.hostname,
      address: sys.address,
      type: sys.type || '--',
      mode: sys.fppModeString || '--',
      drift: null,
      driftFrames: null,
      syncSent: 0, syncRecv: 0, mediaSent: 0, mediaRecv: 0, blankSent: 0, blankRecv: 0, pluginSent: 0, pluginRecv: 0,
      hasMetrics: false
    });
  });

  sortAndRenderTable();
  setupTableSorting();
}

/**
 * Sort and render the table based on current sort settings
 */
export function sortAndRenderTable() {
  const tbody = document.getElementById('systemsPacketBody');
  if (!tbody || state.systemsData.length === 0) return;

  // Sort data
  const col = state.currentSort.column;
  const dir = state.currentSort.direction === 'asc' ? 1 : -1;

  state.systemsData.sort((a, b) => {
    // Local always first
    if (a.isLocal && !b.isLocal) return -1;
    if (!a.isLocal && b.isLocal) return 1;

    let valA = a[col];
    let valB = b[col];

    // Status ordering: local > online > no-plugin > offline > unknown
    if (col === 'status') {
      const order = { local: 0, online: 1, 'no-plugin': 2, offline: 3, unknown: 4 };
      valA = order[valA] ?? 5;
      valB = order[valB] ?? 5;
    }

    // Drift sorting uses clock drift data
    if (col === 'drift') {
      const driftA = state.clockDriftData[a.address]?.drift_ms ?? null;
      const driftB = state.clockDriftData[b.address]?.drift_ms ?? null;
      // Put nulls at end
      if (driftA === null && driftB === null) return 0;
      if (driftA === null) return 1;
      if (driftB === null) return -1;
      return dir * (Math.abs(driftA) - Math.abs(driftB));
    }

    if (typeof valA === 'string') {
      return dir * valA.localeCompare(valB);
    }
    return dir * ((valA ?? 0) - (valB ?? 0));
  });

  // Render rows
  tbody.innerHTML = state.systemsData.map(row => renderTableRow(row)).join('');
}

/**
 * Render a single table row
 * @param {Object} row - Row data
 * @returns {string} HTML string for the row
 */
function renderTableRow(row) {
  const statusClass = row.isLocal ? 'msm-status-local' :
    (row.status === 'online' ? 'msm-status-online' :
     row.status === 'offline' ? 'msm-status-offline' :
     row.status === 'no-plugin' ? 'msm-status-noplugin' : 'msm-status-unknown');

  const statusLabel = row.isLocal ? 'Local' :
    (row.status === 'online' ? 'Online' :
     row.status === 'offline' ? 'Offline' :
     row.status === 'no-plugin' ? 'No Plugin' : '--');

  const total = row.syncSent + row.syncRecv + row.mediaSent + row.mediaRecv +
                row.blankSent + row.blankRecv + row.pluginSent + row.pluginRecv;

  const dimClass = !row.hasMetrics && !row.isLocal ? 'msm-row-dim' : '';

  // Format clock drift display with color coding
  const driftDisplay = formatDriftDisplay(row);

  // Build hostname cell - clickable link for remote systems, plain text for local
  let hostnameCell;
  if (row.isLocal) {
    hostnameCell = `<i class="fas fa-home msm-home-icon"></i>${escapeHtml(row.hostname)}`;
  } else {
    hostnameCell = `<a href="http://${escapeHtml(row.address)}/" target="_blank" class="msm-host-link" title="${escapeHtml(row.address)}">${escapeHtml(row.hostname)}</a>`;
  }

  return `<tr class="${dimClass}">
    <td><span class="msm-status-badge ${statusClass}">${statusLabel}</span></td>
    <td>${hostnameCell}</td>
    <td>${escapeHtml(row.type)}</td>
    <td>${escapeHtml(row.mode)}</td>
    <td class="msm-td-num">${driftDisplay}</td>
    <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.syncSent.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.syncRecv.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.mediaSent.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.mediaRecv.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.blankSent.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.blankRecv.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.pluginSent.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.pluginRecv.toLocaleString() : '--'}</td>
    <td class="msm-td-num msm-td-total">${row.hasMetrics ? total.toLocaleString() : '--'}</td>
  </tr>`;
}

/**
 * Format drift display for a row
 * @param {Object} row - Row data
 * @returns {string} HTML string for drift cell
 */
function formatDriftDisplay(row) {
  if (row.isLocal) {
    return '<span class="msm-drift-ref">ref</span>';
  }

  const clockData = state.clockDriftData[row.address];

  if (clockData && clockData.drift_ms !== null) {
    const driftMs = clockData.drift_ms;
    const absMs = Math.abs(driftMs);
    const sign = driftMs >= 0 ? '+' : '';
    const driftClass = getDriftClass(absMs);
    const rttTitle = clockData.rtt_ms ? `RTT: ${clockData.rtt_ms}ms` : '';
    return `<span class="${driftClass}" title="${rttTitle}">${sign}${driftMs}ms</span>`;
  }

  if (row.hasMetrics) {
    // Has plugin but no clock data yet
    return '<span class="msm-drift-pending">...</span>';
  }

  return '--';
}

/**
 * Setup table column sorting click handlers
 */
export function setupTableSorting() {
  const table = document.getElementById('systemsPacketTable');
  if (!table) return;

  table.querySelectorAll('th[data-sort]').forEach(th => {
    // Remove existing listeners by cloning
    const newTh = th.cloneNode(true);
    th.parentNode.replaceChild(newTh, th);

    newTh.onclick = () => {
      const col = newTh.dataset.sort;
      if (state.currentSort.column === col) {
        state.currentSort.direction = state.currentSort.direction === 'asc' ? 'desc' : 'asc';
      } else {
        state.currentSort.column = col;
        state.currentSort.direction = 'asc';
      }

      // Update header classes
      table.querySelectorAll('th').forEach(h => {
        h.classList.remove('msm-th-sorted-asc', 'msm-th-sorted-desc');
      });
      newTh.classList.add(state.currentSort.direction === 'asc' ? 'msm-th-sorted-asc' : 'msm-th-sorted-desc');

      sortAndRenderTable();
    };
  });
}
