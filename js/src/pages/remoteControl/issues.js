/**
 * Remote Control Page - Issues Banner Module
 *
 * Fetches and renders the issues/discrepancies banner.
 * Combines output discrepancies from API with FPP warnings from cached status.
 */

import {
  shouldFetch,
  markFetched,
  localCache,
  bulkStatusCache,
  config
} from './state.js';
import { escapeHtml } from '../../core/utils.js';

// =============================================================================
// Module State
// =============================================================================

/** Whether issues details are expanded */
let issuesExpanded = false;

/** Cached issues data from API */
let cachedIssuesData = null;

// =============================================================================
// Data Fetching
// =============================================================================

/**
 * Fetch issues/discrepancies from API
 * @returns {Promise<Object|null>} - Issues data or null
 */
export async function fetchIssues() {
  // Use interval-based caching (60s)
  if (!shouldFetch('discrepancies')) {
    return cachedIssuesData;
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/outputs/discrepancies');
    if (!response.ok) return cachedIssuesData;
    const data = await response.json();
    if (data.success) {
      cachedIssuesData = data;
      markFetched('discrepancies');
      return data;
    }
    return cachedIssuesData;
  } catch (e) {
    console.log('Failed to fetch issues:', e);
    return cachedIssuesData;
  }
}

/**
 * Collect FPP warnings from cached status data
 * @returns {Array} - Array of warning objects
 */
export function collectFppWarnings() {
  const warnings = [];
  const localHostname = config.localHostname;

  // Collect warnings from local host
  if (localCache.status && Array.isArray(localCache.status.warnings)) {
    localCache.status.warnings.forEach(msg => {
      warnings.push({
        type: 'fpp_warning',
        severity: 'warning',
        address: 'localhost',
        hostname: localHostname,
        message: msg
      });
    });
  }

  // Collect warnings from remote hosts
  bulkStatusCache.forEach((hostData, address) => {
    if (hostData.sysStatus && Array.isArray(hostData.sysStatus.warnings)) {
      hostData.sysStatus.warnings.forEach(msg => {
        warnings.push({
          type: 'fpp_warning',
          severity: 'warning',
          address: address,
          hostname: hostData.hostname || address,
          message: msg
        });
      });
    }
  });

  return warnings;
}

// =============================================================================
// Rendering
// =============================================================================

/**
 * Render issues banner
 * @param {Object} data - Issues data from fetchIssues
 */
export function renderIssues(data) {
  const banner = document.getElementById('issuesBanner');
  const countEl = document.getElementById('issuesCount');
  const listEl = document.getElementById('issuesList');

  if (!banner || !countEl || !listEl) return;

  // Combine discrepancies from API with FPP warnings from cached status
  const discrepancies = (data && data.discrepancies) ? [...data.discrepancies] : [];
  const fppWarnings = collectFppWarnings();
  const allIssues = [...discrepancies, ...fppWarnings];

  if (allIssues.length === 0) {
    banner.classList.remove('visible');
    return;
  }

  countEl.textContent = allIssues.length;

  let html = '';
  allIssues.forEach(d => {
    let icon, details = [];

    switch (d.type) {
      case 'channel_mismatch':
        icon = 'fa-not-equal';
        details.push(`<span><strong>Player:</strong> ${escapeHtml(d.playerRange)}</span>`);
        details.push(`<span><strong>Remote:</strong> ${escapeHtml(d.remoteRange)}</span>`);
        break;
      case 'output_to_remote':
        icon = 'fa-exclamation-triangle';
        if (d.description) details.push(`<span><strong>Name:</strong> ${escapeHtml(d.description)}</span>`);
        details.push(`<span><strong>Channels:</strong> ${d.startChannel}-${d.startChannel + d.channelCount - 1}</span>`);
        break;
      case 'inactive_output':
        icon = 'fa-info-circle';
        if (d.description) details.push(`<span><strong>Name:</strong> ${escapeHtml(d.description)}</span>`);
        details.push(`<span><strong>Channels:</strong> ${d.startChannel}-${d.startChannel + d.channelCount - 1}</span>`);
        break;
      case 'missing_sequences':
        icon = 'fa-file-audio';
        if (d.sequences && d.sequences.length > 0) {
          const seqList = d.sequences.slice(0, 5).map(s => escapeHtml(s)).join(', ');
          const more = d.sequences.length > 5 ? ` (+${d.sequences.length - 5} more)` : '';
          details.push(`<span><strong>Missing:</strong> ${seqList}${more}</span>`);
        }
        break;
      case 'output_host_not_in_sync':
        icon = 'fa-unlink';
        if (d.description) details.push(`<span><strong>Output:</strong> ${escapeHtml(d.description)}</span>`);
        if (d.startChannel && d.channelCount) {
          details.push(`<span><strong>Channels:</strong> ${d.startChannel}-${d.startChannel + d.channelCount - 1}</span>`);
        }
        break;
      case 'fpp_warning':
        icon = 'fa-exclamation-circle';
        break;
      default:
        icon = 'fa-question-circle';
    }

    html += `
      <div class="issues-item severity-${d.severity}">
        <div class="issues-item__icon"><i class="fas ${icon}"></i></div>
        <div class="issues-item__content">
          <div class="issues-item__message">
            ${escapeHtml(d.message)}
          </div>
          <div class="issues-item__details">
            ${details.join('')}
          </div>
        </div>
        <span class="issues-item__address">${escapeHtml(d.hostname || d.address)}</span>
      </div>`;
  });

  listEl.innerHTML = html;
  banner.classList.add('visible');
}

// =============================================================================
// Toggle
// =============================================================================

/**
 * Toggle issues details visibility
 */
export function toggleIssuesDetails() {
  const body = document.getElementById('issuesBody');
  const toggle = document.getElementById('issuesToggle');

  if (!body || !toggle) return;

  issuesExpanded = !issuesExpanded;

  if (issuesExpanded) {
    body.style.display = 'block';
    toggle.innerHTML = '<i class="fas fa-chevron-up"></i> Hide';
  } else {
    body.style.display = 'none';
    toggle.innerHTML = '<i class="fas fa-chevron-down"></i> Details';
  }
}

/**
 * Reset issues state
 */
export function resetIssuesState() {
  issuesExpanded = false;
  cachedIssuesData = null;
}
