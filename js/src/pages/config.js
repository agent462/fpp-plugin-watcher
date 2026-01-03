/**
 * Configuration Page Module
 *
 * Handles plugin configuration UI functionality.
 * Extracted from configUI.php embedded JavaScript.
 */

import { escapeHtml, formatBytes } from '../core/utils.js';
import { fetchJson } from '../core/api.js';

// =============================================================================
// Private State
// =============================================================================

let state = {
  isPlayerMode: false,
  watcherEditorOriginalContent: '',
  config: {}
};

// =============================================================================
// eFuse Storage Calculator
// =============================================================================

/**
 * Calculate estimated eFuse storage requirements
 */
export function calculateEfuseStorage(interval, days, ports) {
  // Raw storage (6 hours fixed)
  const rawSamplesPerHour = 3600 / interval;
  const rawSamples = rawSamplesPerHour * 6;
  const rawEntrySize = 50 + (18 * ports);
  const rawStorage = rawSamples * rawEntrySize;

  // Rollup buckets (capped by retention)
  const daysInSeconds = days * 86400;
  const tier1minBuckets = Math.min(360, Math.floor(daysInSeconds / 60));
  const tier5minBuckets = Math.min(576, Math.floor(daysInSeconds / 300));
  const tier30minBuckets = Math.min(672, Math.floor(daysInSeconds / 1800));
  const tier2hourBuckets = Math.floor(daysInSeconds / 7200);

  const rollupEntrySize = 50 + (80 * ports);
  const rollupStorage = (tier1minBuckets + tier5minBuckets + tier30minBuckets + tier2hourBuckets) * rollupEntrySize;

  return rawStorage + rollupStorage;
}

/**
 * Update eFuse storage estimate display
 */
export function updateEfuseStorageEstimate() {
  const intervalSelect = document.getElementById('efuseCollectionInterval');
  const daysSelect = document.getElementById('efuseRetentionDays');
  const container = document.getElementById('efuseStorageEstimate');

  if (!intervalSelect || !daysSelect || !container) return;

  const interval = parseInt(intervalSelect.value);
  const days = parseInt(daysSelect.value);

  const portCounts = [4, 8, 16, 32];
  let html = '<div class="storageEstimateTitle"><i class="fas fa-hdd"></i> Estimated Storage</div>';
  html += '<div class="storageEstimateGrid">';

  portCounts.forEach(ports => {
    const bytes = calculateEfuseStorage(interval, days, ports);
    html += `<div class="storageEstimateItem">
      <span class="storageEstimatePorts">${ports} ports</span>
      <span class="storageEstimateSize">${formatBytes(bytes)}</span>
    </div>`;
  });

  html += '</div>';
  container.innerHTML = html;
}

/**
 * Toggle eFuse options visibility
 */
export function toggleEfuseOptions() {
  const checkbox = document.getElementById('efuseMonitorEnabled');
  const container = document.getElementById('efuseOptionsContainer');
  if (checkbox && container) {
    container.style.display = checkbox.checked ? '' : 'none';
    if (checkbox.checked) {
      updateEfuseStorageEstimate();
    }
  }
}

// =============================================================================
// Voltage Storage Calculator
// =============================================================================

/**
 * Calculate estimated voltage storage requirements
 * Storage varies by retention period due to multi-tier rollups
 *
 * @param {number} interval - Collection interval in seconds
 * @param {number} days - Retention period in days
 * @param {number} railCount - Number of voltage rails (Pi5=13, Legacy Pi=4)
 */
export function calculateVoltageStorage(interval, days, railCount = 4) {
  // Raw storage (6 hours retention before rotation)
  // Format: {"timestamp":1234567890,"voltages":{"rail":0.8768,...}}
  // Base overhead ~40 bytes + ~18 bytes per rail
  const rawSamplesPerHour = 3600 / interval;
  const rawSamples = rawSamplesPerHour * 6;  // 6 hours of raw data
  const rawEntrySize = 40 + (railCount * 18);
  const rawStorage = rawSamples * rawEntrySize;

  const daysInSeconds = days * 86400;

  // Rollup entry format: {"timestamp":..,"interval":..,"voltages":{"rail":{"avg":..,"min":..,"max":..,"samples":..},...}}
  // Base overhead ~60 bytes + ~55 bytes per rail (avg/min/max/samples)
  const rollupEntrySize = 60 + (railCount * 55);

  // Tier storage based on retention (mirrors VoltageCollector::getTiers)
  // 1min tier: 6 hours max
  const tier1minBuckets = Math.min(360, Math.floor(daysInSeconds / 60));

  // 5min tier: added if retention > 1 day, 48 hours max
  const tier5minBuckets = days > 1 ? Math.min(576, Math.floor(daysInSeconds / 300)) : 0;

  // 30min tier: added if retention > 3 days, 7 days max
  const tier30minBuckets = days > 3 ? Math.min(336, Math.floor(daysInSeconds / 1800)) : 0;

  // 2hour tier: added if retention > 7 days, full retention
  const tier2hourBuckets = days > 7 ? Math.floor(daysInSeconds / 7200) : 0;

  const rollupStorage = (tier1minBuckets + tier5minBuckets + tier30minBuckets + tier2hourBuckets) * rollupEntrySize;

  // State file is minimal (~150 bytes)
  const stateStorage = 150;

  return rawStorage + rollupStorage + stateStorage;
}

/**
 * Update voltage storage estimate display
 */
export function updateVoltageStorageEstimate() {
  const intervalSelect = document.getElementById('voltageCollectionInterval');
  const daysSelect = document.getElementById('voltageRetentionDays');
  const container = document.getElementById('voltageStorageEstimate');

  if (!intervalSelect || !daysSelect || !container) return;

  const interval = parseInt(intervalSelect.value);
  const days = parseInt(daysSelect.value);

  // Get rail count from config (Pi5=13 rails, Legacy Pi=4 rails)
  const railCount = state.config.voltageRailCount || 4;
  const bytes = calculateVoltageStorage(interval, days, railCount);

  const samplesPerMinute = Math.floor(60 / interval);
  const readingsPerDay = Math.floor(60 / interval) * 60 * 24;

  // Determine which tiers will be used
  let tierInfo = '1min';
  if (days > 1) tierInfo += ', 5min';
  if (days > 3) tierInfo += ', 30min';
  if (days > 7) tierInfo += ', 2hour';

  // Hardware type info
  const hwType = railCount >= 13 ? 'Pi 5 (PMIC)' : 'Pi 4/earlier';

  let html = '<div class="storageEstimateTitle"><i class="fas fa-hdd"></i> Storage Estimate</div>';
  html += '<div class="storageEstimateGrid">';
  html += `<div class="storageEstimateItem">
    <span class="storageEstimatePorts">${days} day${days > 1 ? 's' : ''} data</span>
    <span class="storageEstimateSize">${formatBytes(bytes)}</span>
  </div>`;
  html += `<div class="storageEstimateItem">
    <span class="storageEstimatePorts">${samplesPerMinute} samples/min</span>
    <span class="storageEstimateSize">${readingsPerDay.toLocaleString()} readings/day</span>
  </div>`;
  html += '</div>';
  html += `<div class="storageEstimateNote" style="font-size: 0.8rem; color: #6c757d; margin-top: 0.5rem;">${hwType} (${railCount} rails) | Rollup tiers: ${tierInfo}</div>`;

  container.innerHTML = html;
}

/**
 * Toggle voltage options visibility
 */
export function toggleVoltageOptions() {
  const checkbox = document.getElementById('voltageMonitorEnabled');
  const container = document.getElementById('voltageOptionsContainer');
  if (checkbox && container) {
    container.style.display = checkbox.checked ? '' : 'none';
    if (checkbox.checked) {
      updateVoltageStorageEstimate();
    }
  }
}

// =============================================================================
// Panel Toggle
// =============================================================================

/**
 * Toggle panel collapse state
 */
export function watcherTogglePanel(header) {
  const panel = header.closest('.settingsPanel');
  panel.classList.toggle('collapsed');
}

// =============================================================================
// Tag Input (Test Hosts)
// =============================================================================

/**
 * Handle tag input keypress
 */
export function watcherHandleTagKeypress(event) {
  if (event.key === 'Enter') {
    event.preventDefault();
    watcherAddTag();
  }
}

/**
 * Add a test host tag
 */
export function watcherAddTag() {
  const input = document.getElementById('newHostInput');
  const host = input.value.trim();

  if (!host) return;

  // Check for duplicates
  const existingHosts = Array.from(document.querySelectorAll('input[name="testHosts[]"]'))
    .map(el => el.value);

  if (existingHosts.includes(host)) {
    alert('This host is already in the list');
    return;
  }

  // Create tag element
  const container = document.getElementById('testHostsContainer');
  const tag = document.createElement('span');
  tag.className = 'tag';
  tag.innerHTML = `
    ${escapeHtml(host)}
    <i class="fas fa-times tagRemove" onclick="page.watcherRemoveTag(this)"></i>
    <input type="hidden" name="testHosts[]" value="${escapeHtml(host)}">
  `;

  // Insert before the input
  container.insertBefore(tag, input);
  input.value = '';
}

/**
 * Remove a test host tag
 */
export function watcherRemoveTag(element) {
  element.closest('.tag').remove();
}

// =============================================================================
// Reset State Management
// =============================================================================

/**
 * Clear reset state and restart daemon
 */
export async function clearResetState() {
  const btn = document.getElementById('clearResetStateBtn');
  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/connectivity/state/clear', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' }
    });

    const result = await response.json();

    if (result.success) {
      window.location.reload();
    } else {
      alert('Failed to clear reset state: ' + (result.error || 'Unknown error'));
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    }
  } catch (error) {
    alert('Error clearing reset state: ' + error.message);
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
}

// =============================================================================
// Data Management
// =============================================================================

/**
 * Load data statistics with accordion UI
 */
export async function loadDataStats() {
  const container = document.getElementById('dataStatsContainer');

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/data/stats');
    const result = await response.json();

    if (!result.success) {
      container.innerHTML = '<div class="dataStatsError">Failed to load data statistics</div>';
      return;
    }

    let html = '<div class="dataAccordion">';
    let totalSize = 0;
    let totalFiles = 0;

    for (const [key, category] of Object.entries(result.categories)) {
      // Skip player-only categories when not in player mode
      if (category.playerOnly && !state.isPlayerMode) {
        continue;
      }
      totalSize += category.totalSize;
      totalFiles += category.fileCount;

      const hasFiles = category.fileCount > 0;
      const showFiles = category.showFiles !== false;
      const canExpand = hasFiles && showFiles;
      const expandable = canExpand ? 'expandable' : '';

      // Auto-expand items with warnings
      const autoExpanded = category.warning ? 'expanded' : '';

      html += `<div class="dataAccordionItem ${autoExpanded}" data-category="${escapeHtml(key)}">
        <div class="dataAccordionHeader ${expandable}" onclick="${canExpand ? 'page.toggleDataAccordion(this)' : ''}">
          <div class="dataAccordionTitle">
            ${canExpand ? '<i class="fas fa-chevron-right dataAccordionChevron"></i>' : '<i class="fas fa-database dataAccordionIcon"></i>'}
            <strong>${escapeHtml(category.name)}</strong>
            <span class="dataAccordionBadge">${category.fileCount} file${category.fileCount !== 1 ? 's' : ''}</span>
            <span class="dataAccordionSize">${formatBytes(category.totalSize)}</span>
          </div>
          <div class="dataAccordionActions">
            <button type="button" class="buttons btn-sm btn-outline-danger"
              onclick="event.stopPropagation(); page.clearDataCategory('${escapeHtml(key)}', '${escapeHtml(category.name)}')"
              ${!hasFiles ? 'disabled' : ''}>
              <i class="fas fa-trash"></i> Clear All
            </button>
          </div>
        </div>`;

      // Only show body for expandable items or items with warnings
      if (canExpand || category.warning) {
        html += '<div class="dataAccordionBody">';
        html += `<div class="dataAccordionDesc">${escapeHtml(category.description)}</div>`;

        // Show warning if present
        if (category.warning) {
          html += `<div class="dataWarningInline"><i class="fas fa-info-circle"></i> ${escapeHtml(category.warning)}</div>`;
        }

        // Show file list only for categories that show files
        if (showFiles && hasFiles) {
          const sortedFiles = [...category.files].sort((a, b) => b.modified - a.modified);
          html += '<div class="dataFileList">';
          for (const file of sortedFiles) {
            const modDate = new Date(file.modified * 1000).toLocaleString();
            const isViewable = file.name.endsWith('.log') || file.name.endsWith('.json');
            html += `<div class="dataFileItem">
              <div class="dataFileInfo">
                <i class="fas fa-file dataFileIcon"></i>
                <span class="dataFileName">${escapeHtml(file.name)}</span>
                <span class="dataFileMeta">${formatBytes(file.size)} &bull; ${modDate}</span>
              </div>
              <div class="dataFileActions">
                ${isViewable ? `<button type="button" class="buttons btn-xs btn-outline-secondary"
                  onclick="page.viewDataFile('${escapeHtml(key)}', '${escapeHtml(file.name)}')"
                  title="View file contents">
                  <i class="fas fa-eye"></i>
                </button>` : ''}
                <button type="button" class="buttons btn-xs btn-outline-danger"
                  onclick="page.clearDataFile('${escapeHtml(key)}', '${escapeHtml(file.name)}')"
                  title="Delete file">
                  <i class="fas fa-times"></i>
                </button>
              </div>
            </div>`;
          }
          html += '</div>';
        } else if (showFiles && !hasFiles) {
          html += '<div class="dataEmptyMessage"><i class="fas fa-check-circle"></i> No data files</div>';
        }

        html += '</div>';
      }

      html += '</div>';
    }

    html += `</div>
      <div class="dataTotalRow">
        <strong>Total:</strong> ${totalFiles} files &bull; ${formatBytes(totalSize)}
      </div>`;

    container.innerHTML = html;
  } catch (error) {
    container.innerHTML = '<div class="dataStatsError">Error loading data statistics: ' + escapeHtml(error.message) + '</div>';
  }
}

/**
 * Toggle accordion expand/collapse
 */
export function toggleDataAccordion(header) {
  const item = header.closest('.dataAccordionItem');
  item.classList.toggle('expanded');
}

/**
 * Clear data for a category
 */
export async function clearDataCategory(category, categoryName) {
  if (!confirm(`Are you sure you want to clear all ${categoryName} data?\n\nThis action cannot be undone.`)) {
    return;
  }

  try {
    const response = await fetch(`/api/plugin/fpp-plugin-watcher/data/${encodeURIComponent(category)}`, {
      method: 'DELETE'
    });

    const result = await response.json();

    if (result.success) {
      loadDataStats();
    } else {
      alert('Failed to clear data: ' + (result.errors?.join(', ') || 'Unknown error'));
    }
  } catch (error) {
    alert('Error clearing data: ' + error.message);
  }
}

/**
 * Clear a single file
 */
export async function clearDataFile(category, filename) {
  if (!confirm(`Delete "${filename}"?\n\nThis action cannot be undone.`)) {
    return;
  }

  try {
    const response = await fetch(`/api/plugin/fpp-plugin-watcher/data/${encodeURIComponent(category)}/${encodeURIComponent(filename)}`, {
      method: 'DELETE'
    });

    const result = await response.json();

    if (result.success) {
      loadDataStats();
    } else {
      alert('Failed to delete file: ' + (result.error || 'Unknown error'));
    }
  } catch (error) {
    alert('Error deleting file: ' + error.message);
  }
}

// =============================================================================
// File Viewer Modal
// =============================================================================

/**
 * View file contents in terminal modal
 */
export async function viewDataFile(category, filename) {
  const modal = document.getElementById('terminalModal');
  const title = document.getElementById('terminalModalTitle');
  const content = document.getElementById('terminalContent');

  // Store current file info for refresh
  modal.dataset.category = category;
  modal.dataset.filename = filename;

  title.textContent = filename;
  content.textContent = 'Loading...';
  modal.style.display = 'flex';

  await refreshTerminalContent();
}

/**
 * Refresh terminal content
 */
export async function refreshTerminalContent() {
  const modal = document.getElementById('terminalModal');
  const content = document.getElementById('terminalContent');
  const category = modal.dataset.category;
  const filename = modal.dataset.filename;

  try {
    const response = await fetch(`/api/plugin/fpp-plugin-watcher/data/${encodeURIComponent(category)}/${encodeURIComponent(filename)}/tail?lines=100`);
    const result = await response.json();

    if (result.success) {
      content.textContent = result.content || '(empty file)';
      content.scrollTop = content.scrollHeight;
    } else {
      content.textContent = 'Error: ' + (result.error || 'Failed to load file');
    }
  } catch (error) {
    content.textContent = 'Error: ' + error.message;
  }
}

/**
 * Close terminal modal
 */
export function closeTerminalModal() {
  document.getElementById('terminalModal').style.display = 'none';
}

// =============================================================================
// Collectd Config Viewer
// =============================================================================

/**
 * View collectd config (read-only)
 */
export async function viewCollectdConfig() {
  const modal = document.getElementById('collectdViewerModal');
  const content = document.getElementById('collectdViewerContent');

  content.textContent = 'Loading...';
  modal.style.display = 'flex';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/config/collectd');
    const result = await response.json();

    if (result.success) {
      content.textContent = result.content || '(empty file)';
    } else {
      content.textContent = 'Error: ' + (result.error || 'Failed to load configuration');
    }
  } catch (error) {
    content.textContent = 'Error: ' + error.message;
  }
}

/**
 * Close collectd viewer modal
 */
export function closeCollectdViewer() {
  document.getElementById('collectdViewerModal').style.display = 'none';
}

// =============================================================================
// Watcher Config Editor
// =============================================================================

/**
 * Open watcher config editor
 */
export async function openWatcherEditor() {
  const modal = document.getElementById('watcherEditorModal');
  const textarea = document.getElementById('watcherEditorContent');
  const status = document.getElementById('watcherEditorStatus');

  textarea.value = 'Loading...';
  status.textContent = '';
  status.className = 'configEditorStatus';
  modal.style.display = 'flex';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/config/watcher');
    const result = await response.json();

    if (result.success) {
      textarea.value = result.content;
      state.watcherEditorOriginalContent = result.content;
    } else {
      textarea.value = 'Error: ' + (result.error || 'Failed to load configuration');
      status.textContent = 'Load failed';
      status.className = 'configEditorStatus error';
    }
  } catch (error) {
    textarea.value = 'Error: ' + error.message;
    status.textContent = 'Load failed';
    status.className = 'configEditorStatus error';
  }
}

/**
 * Save watcher config
 */
export async function saveWatcherConfig() {
  const textarea = document.getElementById('watcherEditorContent');
  const saveBtn = document.getElementById('saveWatcherBtn');
  const status = document.getElementById('watcherEditorStatus');
  const content = textarea.value;

  if (content === state.watcherEditorOriginalContent) {
    status.textContent = 'No changes to save';
    status.className = 'configEditorStatus';
    return;
  }

  const originalBtnHtml = saveBtn.innerHTML;
  saveBtn.disabled = true;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  status.textContent = '';
  status.className = 'configEditorStatus';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/config/watcher', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ content: content })
    });

    const result = await response.json();

    if (result.success) {
      state.watcherEditorOriginalContent = content;
      status.textContent = 'Saved! Some changes may require FPP restart.';
      status.className = 'configEditorStatus';
    } else {
      status.textContent = 'Error: ' + (result.error || 'Failed to save');
      status.className = 'configEditorStatus error';
    }
  } catch (error) {
    status.textContent = 'Error: ' + error.message;
    status.className = 'configEditorStatus error';
  } finally {
    saveBtn.disabled = false;
    saveBtn.innerHTML = originalBtnHtml;
  }
}

/**
 * Close watcher editor modal
 */
export function closeWatcherEditor() {
  const modal = document.getElementById('watcherEditorModal');
  const textarea = document.getElementById('watcherEditorContent');

  if (textarea.value !== state.watcherEditorOriginalContent) {
    if (!confirm('You have unsaved changes. Are you sure you want to close?')) {
      return;
    }
  }

  modal.style.display = 'none';
}

// =============================================================================
// Form Validation
// =============================================================================

/**
 * Validate settings form before submission
 */
export function validateForm(event) {
  const testHosts = document.querySelectorAll('input[name="testHosts[]"]');
  if (testHosts.length === 0) {
    event.preventDefault();
    alert('Please add at least one test host');
    return false;
  }
  return true;
}

// =============================================================================
// Event Handlers
// =============================================================================

/**
 * Set up keyboard event handlers
 */
function setupKeyboardHandlers() {
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      const terminalModal = document.getElementById('terminalModal');
      const collectdModal = document.getElementById('collectdViewerModal');
      const watcherModal = document.getElementById('watcherEditorModal');

      if (watcherModal && watcherModal.style.display === 'flex') {
        closeWatcherEditor();
      } else if (collectdModal && collectdModal.style.display === 'flex') {
        closeCollectdViewer();
      } else if (terminalModal && terminalModal.style.display === 'flex') {
        closeTerminalModal();
      }
    }
  });
}

/**
 * Set up data panel observer
 */
function setupDataPanelObserver() {
  const dataPanel = document.querySelector('.settingsPanel:has(#dataStatsContainer)');
  if (dataPanel) {
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
          if (!dataPanel.classList.contains('collapsed')) {
            loadDataStats();
          }
        }
      });
    });
    observer.observe(dataPanel, { attributes: true });
  }
}

// =============================================================================
// Initialization
// =============================================================================

/**
 * Initialize the config page
 */
function initConfig() {
  // Initialize eFuse storage estimate
  updateEfuseStorageEstimate();

  // Initialize voltage storage estimate
  updateVoltageStorageEstimate();

  // Set up form validation
  const form = document.getElementById('watcherSettingsForm');
  if (form) {
    form.addEventListener('submit', validateForm);
  }

  // Set up keyboard handlers
  setupKeyboardHandlers();

  // Set up data panel observer
  setupDataPanelObserver();
}

// =============================================================================
// Public Page Interface
// =============================================================================

/**
 * Config page module
 */
export const config = {
  pageId: 'configUI',

  /**
   * Initialize with config from PHP
   */
  init(pageConfig) {
    state.config = pageConfig;
    state.isPlayerMode = pageConfig.isPlayerMode || false;
    initConfig();
  },

  /**
   * Cleanup and destroy
   */
  destroy() {
    state = {
      isPlayerMode: false,
      watcherEditorOriginalContent: '',
      config: {}
    };
  },

  // eFuse storage calculator
  calculateEfuseStorage,
  updateEfuseStorageEstimate,
  toggleEfuseOptions,

  // Voltage storage calculator
  calculateVoltageStorage,
  updateVoltageStorageEstimate,
  toggleVoltageOptions,

  // Panel toggle
  watcherTogglePanel,

  // Tag input
  watcherHandleTagKeypress,
  watcherAddTag,
  watcherRemoveTag,

  // Reset state
  clearResetState,

  // Data management
  loadDataStats,
  toggleDataAccordion,
  clearDataCategory,
  clearDataFile,
  viewDataFile,
  refreshTerminalContent,
  closeTerminalModal,

  // Config viewers/editors
  viewCollectdConfig,
  closeCollectdViewer,
  openWatcherEditor,
  saveWatcherConfig,
  closeWatcherEditor,

  // Form validation
  validateForm
};

export default config;
