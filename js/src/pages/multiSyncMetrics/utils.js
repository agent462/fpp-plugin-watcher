/**
 * multiSyncMetrics/utils.js - Utility functions for Multi-Sync Dashboard
 *
 * Contains formatting and helper functions used across the module.
 */

/**
 * Format seconds as MM:SS
 * @param {number} seconds - Time in seconds
 * @returns {string} Formatted time string
 */
export function formatTime(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Format seconds since as human-readable relative time
 * @param {number} seconds - Time in seconds
 * @returns {string} Formatted relative time (e.g., "5s", "2m", "1h", "3d")
 */
export function formatTimeSince(seconds) {
  if (seconds === undefined || seconds < 0) return '--';
  if (seconds < 60) return seconds + 's';
  if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
  if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
  return Math.floor(seconds / 86400) + 'd';
}

/**
 * Format milliseconds since as human-readable relative time
 * @param {number} ms - Time in milliseconds
 * @returns {string} Formatted relative time (e.g., "500ms", "2.5s", "1m")
 */
export function formatTimeSinceMs(ms) {
  if (ms === undefined || ms < 0) return '--';
  if (ms < 1000) return ms + 'ms';
  if (ms < 10000) return (ms / 1000).toFixed(1) + 's';
  const seconds = Math.floor(ms / 1000);
  if (seconds < 60) return seconds + 's';
  if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
  if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
  return Math.floor(seconds / 86400) + 'd';
}

/**
 * Apply quality class to an element
 * @param {string} elementId - The DOM element ID
 * @param {string} quality - Quality level ('good', 'fair', 'poor', etc.)
 */
export function applyQualityClass(elementId, quality) {
  const el = document.getElementById(elementId);
  if (!el) return;
  el.className = 'msm-quality-value';
  if (quality) {
    el.classList.add('msm-quality-' + quality);
  }
}

/**
 * Update the last refresh timestamp display
 */
export function updateLastRefresh() {
  const el = document.getElementById('lastUpdate');
  if (el) {
    el.textContent = 'Updated: ' + new Date().toLocaleTimeString();
  }
}

/**
 * Show plugin error message
 * @param {string} msg - Error message to display
 */
export function showPluginError(msg) {
  const msgEl = document.getElementById('pluginErrorMessage');
  const errorEl = document.getElementById('pluginError');
  if (msgEl) msgEl.textContent = msg;
  if (errorEl) errorEl.style.display = 'flex';
}

/**
 * Hide plugin error message
 */
export function hidePluginError() {
  const errorEl = document.getElementById('pluginError');
  if (errorEl) errorEl.style.display = 'none';
}

/**
 * Toggle the help tooltip visibility
 */
export function toggleHelpTooltip() {
  const tooltip = document.getElementById('helpTooltip');
  if (tooltip) {
    tooltip.classList.toggle('show');
  }
}

/**
 * Handle click outside help tooltip to close it
 * @param {Event} e - Click event
 */
export function handleClickOutside(e) {
  const tooltip = document.getElementById('helpTooltip');
  const helpBtn = document.querySelector('.msm-help-btn');
  if (tooltip && tooltip.classList.contains('show') &&
      !tooltip.contains(e.target) && (!helpBtn || !helpBtn.contains(e.target))) {
    tooltip.classList.remove('show');
  }
}

/**
 * Get drift class based on milliseconds
 * @param {number} driftMs - Drift in milliseconds (absolute value)
 * @returns {string} CSS class name
 */
export function getDriftClass(driftMs) {
  if (driftMs < 100) return 'msm-drift-good';
  if (driftMs < 1000) return 'msm-drift-fair';
  return 'msm-drift-poor';
}

/**
 * Get jitter class based on milliseconds
 * @param {number} jitterMs - Jitter in milliseconds
 * @returns {string} CSS class name
 */
export function getJitterClass(jitterMs) {
  if (jitterMs < 20) return 'good';
  if (jitterMs < 50) return 'warning';
  return 'critical';
}

/**
 * Get drift class based on frames
 * @param {number} driftFrames - Drift in frames (absolute value)
 * @returns {string} CSS class name
 */
export function getDriftFrameClass(driftFrames) {
  if (driftFrames > 10) return 'critical';
  if (driftFrames > 5) return 'warning';
  return 'good';
}
