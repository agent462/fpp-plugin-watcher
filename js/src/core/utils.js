/**
 * Core utility functions
 *
 * - HTML escaping
 * - DOM helpers (showElement, hideElement, setLoading)
 * - Formatters (formatBytes, formatLatency, formatPercent, formatDuration)
 * - Temperature helpers (toFahrenheit, formatTemp, getTempStatus, etc.)
 * - Time helpers (updateLastUpdateTime)
 */

// =============================================================================
// HTML Escaping
// =============================================================================

/**
 * HTML escape helper - prevents XSS by escaping special characters
 * @param {string|null|undefined} text - Text to escape
 * @returns {string} - Escaped text safe for innerHTML
 */
export function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

// =============================================================================
// DOM Helpers
// =============================================================================

/**
 * Show an element by ID (sets display to 'block')
 * @param {string} id - Element ID
 */
export function showElement(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'block';
}

/**
 * Hide an element by ID (sets display to 'none')
 * @param {string} id - Element ID
 */
export function hideElement(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}

/**
 * Toggle loading state for an element (uses 'flex' display)
 * @param {string} id - Element ID
 * @param {boolean} show - Whether to show or hide
 */
export function setLoading(id, show) {
  const el = document.getElementById(id);
  if (el) el.style.display = show ? 'flex' : 'none';
}

// =============================================================================
// Format Helpers
// =============================================================================

/**
 * Format bytes to human-readable string
 * @param {number} bytes - Number of bytes
 * @returns {string} - Formatted string (e.g., "1.50 MB")
 */
export function formatBytes(bytes) {
  if (!bytes) return '0 Bytes';
  const units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

/**
 * Format latency in milliseconds
 * @param {number|null|undefined} ms - Latency in milliseconds
 * @returns {string} - Formatted string (e.g., "12.50 ms")
 */
export function formatLatency(ms) {
  return ms !== null && ms !== undefined ? ms.toFixed(2) + ' ms' : '-- ms';
}

/**
 * Format percentage value
 * @param {number|null|undefined} value - Percentage value
 * @param {number} decimals - Decimal places (default: 1)
 * @returns {string} - Formatted string (e.g., "75.5%")
 */
export function formatPercent(value, decimals = 1) {
  return value !== null && value !== undefined ? value.toFixed(decimals) + '%' : '--%';
}

/**
 * Format duration in seconds to human-readable string
 * @param {number} seconds - Duration in seconds
 * @returns {string} - Formatted string (e.g., "1h 30m 45s")
 */
export function formatDuration(seconds) {
  if (!seconds || seconds <= 0) return '--';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  if (h > 0) return `${h}h ${m}m ${s}s`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

// =============================================================================
// Temperature Helpers
// =============================================================================

/**
 * Convert Celsius to Fahrenheit
 * @param {number} celsius - Temperature in Celsius
 * @returns {number} - Temperature in Fahrenheit
 */
export function toFahrenheit(celsius) {
  return celsius * 9/5 + 32;
}

/**
 * Get temperature unit string
 * @param {boolean} useFahrenheit - Whether to use Fahrenheit
 * @returns {string} - Unit string ("°F" or "°C")
 */
export function getTempUnit(useFahrenheit) {
  return useFahrenheit ? '°F' : '°C';
}

/**
 * Format temperature with unit
 * @param {number} celsius - Temperature in Celsius
 * @param {boolean} useFahrenheit - Whether to display in Fahrenheit
 * @returns {string} - Formatted temperature (e.g., "72.5°F")
 */
export function formatTemp(celsius, useFahrenheit) {
  const value = useFahrenheit ? toFahrenheit(celsius) : celsius;
  return value.toFixed(1) + getTempUnit(useFahrenheit);
}

/**
 * Get temperature status with color and icon
 * @param {number} celsius - Temperature in Celsius
 * @returns {{text: string, color: string, icon: string}} - Status object
 */
export function getTempStatus(celsius) {
  if (celsius < 40) return { text: 'Cool', color: '#38ef7d', icon: '❄️' };
  if (celsius < 60) return { text: 'Normal', color: '#28a745', icon: '✅' };
  if (celsius < 80) return { text: 'Warm', color: '#ffc107', icon: '⚠️' };
  return { text: 'Hot', color: '#f5576c', icon: '🔥' };
}

/**
 * Format thermal zone type for display
 * Converts "cpu-thermal" to "CPU Thermal", etc.
 * @param {string} type - Raw type from sysfs (e.g., "cpu-thermal")
 * @returns {string} Formatted display name (e.g., "CPU Thermal")
 */
export function formatThermalZoneName(type) {
  if (!type) return type;
  const abbreviations = { cpu: 'CPU', gpu: 'GPU', soc: 'SoC', acpi: 'ACPI', pch: 'PCH' };
  // Replace underscores/hyphens with spaces and capitalize each word
  let formatted = type.replace(/[_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  // Apply abbreviation replacements (case-insensitive)
  Object.entries(abbreviations).forEach(([search, replace]) => {
    formatted = formatted.replace(new RegExp('\\b' + search + '\\b', 'gi'), replace);
  });
  return formatted;
}

// =============================================================================
// Time Helpers
// =============================================================================

/**
 * Update the "Last updated" text element with current time
 * @param {string} elementId - Element ID (default: 'lastUpdate')
 */
export function updateLastUpdateTime(elementId = 'lastUpdate') {
  const el = document.getElementById(elementId);
  if (el) {
    el.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
  }
}

// =============================================================================
// Export object for convenient access
// =============================================================================

export const utils = {
  // HTML escaping
  escapeHtml,

  // DOM helpers
  showElement,
  hideElement,
  setLoading,

  // Formatters
  formatBytes,
  formatLatency,
  formatPercent,
  formatDuration,

  // Temperature helpers
  toFahrenheit,
  getTempUnit,
  formatTemp,
  getTempStatus,
  formatThermalZoneName,

  // Time helpers
  updateLastUpdateTime,
};
