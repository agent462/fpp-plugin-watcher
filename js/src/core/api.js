/**
 * API utilities
 *
 * Shared API utilities extracted from commonUI.js:
 * - fetchJson - Fetch JSON with timeout and error handling
 * - withButtonLoading - Wrap async function with button loading state
 * - createRefreshController - Create refresh state controller
 * - loadTemperaturePreference - Load temperature unit preference
 */

// =============================================================================
// Fetch Helper
// =============================================================================

/**
 * Fetch JSON with timeout and error handling
 * @param {string} url - URL to fetch
 * @param {number} timeout - Timeout in milliseconds (default: 10000)
 * @returns {Promise<Object>} - Parsed JSON response
 * @throws {Error} - On timeout, network error, or non-OK response
 */
export async function fetchJson(url, timeout = 10000) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, { signal: controller.signal, cache: 'no-store' });
    clearTimeout(timeoutId);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (e) {
    clearTimeout(timeoutId);
    throw e;
  }
}

// =============================================================================
// Button Loading State
// =============================================================================

/**
 * Wrap async function with button loading state
 * Shows spinner icon while loading, disables button, restores after
 * @param {HTMLButtonElement} btn - Button element
 * @param {string} originalIconClass - Original icon class (e.g., 'fas fa-sync-alt')
 * @param {Function} asyncFn - Async function to execute
 * @returns {Promise<*>} - Result of asyncFn
 */
export async function withButtonLoading(btn, originalIconClass, asyncFn) {
  const icon = btn?.querySelector('i');
  if (icon) icon.className = 'fas fa-spinner fa-spin';
  if (btn) btn.disabled = true;
  try {
    return await asyncFn();
  } finally {
    if (icon) icon.className = originalIconClass;
    if (btn) btn.disabled = false;
  }
}

// =============================================================================
// Refresh State Controller
// =============================================================================

/**
 * Create refresh state controller for dashboard pages
 * Manages refresh state, button animation, and auto-refresh interval
 * @param {Function} refreshFn - Function to call on refresh
 * @param {number} intervalMs - Auto-refresh interval in milliseconds (default: 30000)
 * @returns {Object} - Controller with refresh(), startAutoRefresh(), stopAutoRefresh()
 */
export function createRefreshController(refreshFn, intervalMs = 30000) {
  let isRefreshing = false;
  let intervalId = null;

  const controller = {
    /**
     * Whether a refresh is currently in progress
     */
    get isRefreshing() { return isRefreshing; },

    /**
     * Trigger a refresh
     * @param {boolean} showLoading - Whether to show loading state (default: true)
     */
    async refresh(showLoading = true) {
      if (isRefreshing) return;
      isRefreshing = true;

      const refreshBtn = document.querySelector('.refreshButton i');
      if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

      try {
        await refreshFn(showLoading);
      } finally {
        isRefreshing = false;
        if (refreshBtn) refreshBtn.style.animation = '';
      }
    },

    /**
     * Start auto-refresh interval
     */
    startAutoRefresh() {
      if (!intervalId) {
        intervalId = setInterval(() => controller.refresh(false), intervalMs);
      }
    },

    /**
     * Stop auto-refresh interval
     */
    stopAutoRefresh() {
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
      }
    }
  };

  return controller;
}

// =============================================================================
// Temperature Preference
// =============================================================================

/**
 * Load user's temperature unit preference
 * Checks localStorage cache first, then fetches from FPP settings API
 * @returns {Promise<boolean>} - True if Fahrenheit, false if Celsius
 */
export async function loadTemperaturePreference() {
  const cached = localStorage.getItem('temperatureInF');
  if (cached !== null) {
    return cached === 'true';
  }
  try {
    const { value } = await fetchJson('/api/settings/temperatureInF');
    const useFahrenheit = value === '1' || value === 1;
    localStorage.setItem('temperatureInF', useFahrenheit);
    return useFahrenheit;
  } catch {
    return false;
  }
}

// =============================================================================
// Export object for convenient access
// =============================================================================

export const api = {
  fetchJson,
  withButtonLoading,
  createRefreshController,
  loadTemperaturePreference,
};
