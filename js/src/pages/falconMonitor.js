/**
 * Falcon Controller Monitor Page Module
 *
 * Dashboard for monitoring and controlling Falcon pixel controllers.
 * Extracted from falconMonitorUI.php embedded JavaScript.
 */

import { escapeHtml, toFahrenheit, getTempUnit } from '../core/utils.js';
import { withButtonLoading, fetchJson } from '../core/api.js';

// =============================================================================
// Private State
// =============================================================================

let state = {
  controllers: [],
  isRefreshing: false,
  autoRefreshInterval: null,
  useFahrenheit: false,
  configuredHosts: [],
  config: {}
};

// =============================================================================
// Temperature & Voltage Helpers
// =============================================================================

/**
 * Format temperature with unit preference
 */
export function formatTemperature(celsius) {
  if (state.useFahrenheit) {
    return toFahrenheit(celsius).toFixed(1) + getTempUnit(true);
  }
  return celsius.toFixed(1) + getTempUnit(false);
}

/**
 * Get temperature color based on value
 */
export function getTempColor(celsius) {
  if (celsius > 80) return '#f5576c';
  if (celsius > 60) return '#ffc107';
  if (celsius > 40) return '#4facfe';
  return '#38ef7d';
}

/**
 * Get voltage status classification
 */
export function getVoltageStatus(value) {
  if (value > 0 && value < 11) return 'warning';
  if (value >= 11 && value <= 13) return 'good';
  if (value > 13) return 'warning';
  return 'normal';
}

// =============================================================================
// Card Section Renderers
// =============================================================================

/**
 * Render quick stats section
 */
function renderQuickStats(status, isLoading = false) {
  const stats = [
    { label: 'Firmware', value: status?.firmware_version },
    { label: 'Mode', value: status?.mode_name },
    { label: 'Ports', value: status?.num_ports },
    { label: 'Uptime', value: status?.uptime }
  ];
  return `
    <div class="watcher-quick-stats">
      ${stats.map(s => `
        <div class="watcher-quick-stats__item">
          <span class="watcher-quick-stats__label">${s.label}</span>
          ${isLoading
            ? '<span class="watcher-skeleton watcher-skeleton--text watcher-skeleton--text-short" style="display:inline-block;"></span>'
            : `<span class="watcher-quick-stats__value">${escapeHtml(s.value)}</span>`
          }
        </div>
      `).join('')}
    </div>
  `;
}

/**
 * Render temperatures section
 */
function renderTemperatures(status, isLoading = false) {
  const temps = [
    { label: 'CPU', celsius: status?.temperature1 },
    { label: 'Temp1', celsius: status?.temperature2 },
    { label: 'Temp2', celsius: status?.temperature3 }
  ].filter(t => t.celsius !== undefined && t.celsius !== null);

  return `
    <div class="watcher-section-title"><i class="fas fa-thermometer-half"></i> Temperatures</div>
    <div class="watcher-temp-grid">
      ${(isLoading ? [{label:'CPU'},{label:'Temp1'},{label:'Temp2'}] : temps).map(t => {
        if (isLoading) {
          return `
            <div class="watcher-temp-item">
              <div class="watcher-temp-item__label">${t.label}</div>
              <div class="watcher-skeleton watcher-skeleton--temp"></div>
            </div>
          `;
        }
        const color = getTempColor(t.celsius);
        const percent = Math.min((t.celsius / 100) * 100, 100);
        return `
          <div class="watcher-temp-item">
            <div class="watcher-temp-item__label">${t.label}</div>
            <div class="watcher-temp-item__value" style="color: ${color};">${formatTemperature(t.celsius)}</div>
            <div class="watcher-temp-item__bar">
              <div class="watcher-temp-item__bar-fill" style="width: ${percent}%; background-color: ${color};"></div>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

/**
 * Render voltages section
 */
function renderVoltages(status, isLoading = false) {
  if (isLoading) {
    return `
      <div class="watcher-section-title"><i class="fas fa-bolt"></i> Power</div>
      <div class="watcher-voltage-info">
        <div class="watcher-voltage-item">
          <span class="watcher-voltage-item__label">V1</span>
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:50px;"></span>
        </div>
        <div class="watcher-voltage-item">
          <span class="watcher-voltage-item__label">Input</span>
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:50px;"></span>
        </div>
      </div>
    `;
  }

  const voltages = [];
  if (status?.voltage1) voltages.push({ label: 'V1', value: status.voltage1, status: 'normal' });
  if (status?.voltage2) {
    const match = status.voltage2.match(/([\d.]+)/);
    const v = match ? parseFloat(match[1]) : 0;
    voltages.push({ label: 'Input', value: status.voltage2, status: getVoltageStatus(v) });
  }

  if (voltages.length === 0) return '';

  return `
    <div class="watcher-section-title"><i class="fas fa-bolt"></i> Power</div>
    <div class="watcher-voltage-info">
      ${voltages.map(v => `
        <div class="watcher-voltage-item">
          <span class="watcher-voltage-item__label">${v.label}</span>
          <span class="watcher-voltage-item__value watcher-voltage-item__value--${v.status}">${escapeHtml(v.value)}</span>
        </div>
      `).join('')}
    </div>
  `;
}

/**
 * Render pixels section
 */
function renderPixels(status, isLoading = false) {
  if (isLoading) {
    return `
      <div class="watcher-section-title"><i class="fas fa-lightbulb"></i> Pixels</div>
      <div class="watcher-pixel-info">
        <div class="watcher-pixel-total">
          <span class="watcher-pixel-total__label">Total Pixels</span>
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:80px;height:1.5em;"></span>
        </div>
        <div class="watcher-pixel-banks">
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:80px;"></span>
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:80px;"></span>
          <span class="watcher-skeleton watcher-skeleton--text" style="display:inline-block;width:80px;"></span>
        </div>
      </div>
    `;
  }

  const total = (status.pixels_bank0 || 0) + (status.pixels_bank1 || 0) + (status.pixels_bank2 || 0);
  return `
    <div class="watcher-section-title"><i class="fas fa-lightbulb"></i> Pixels</div>
    <div class="watcher-pixel-info">
      <div class="watcher-pixel-total">
        <span class="watcher-pixel-total__label">Total Pixels</span>
        <span class="watcher-pixel-total__value">${total.toLocaleString()}</span>
      </div>
      <div class="watcher-pixel-banks">
        <span class="watcher-pixel-bank">Bank 0: ${(status.pixels_bank0 || 0).toLocaleString()}</span>
        <span class="watcher-pixel-bank">Bank 1: ${(status.pixels_bank1 || 0).toLocaleString()}</span>
        <span class="watcher-pixel-bank">Bank 2: ${(status.pixels_bank2 || 0).toLocaleString()}</span>
      </div>
    </div>
  `;
}

/**
 * Render details section
 */
function renderDetails(host, status, isLoading = false) {
  return `
    <div class="watcher-details">
      <div class="watcher-details__row">
        <span class="watcher-details__label">IP Address</span>
        <a href="http://${escapeHtml(host)}/" target="_blank" class="watcher-details__value watcher-details__link">${escapeHtml(host)}</a>
      </div>
      <div class="watcher-details__row">
        <span class="watcher-details__label">Controller Time</span>
        ${isLoading
          ? '<span class="watcher-skeleton watcher-skeleton--text watcher-skeleton--text-medium" style="display:inline-block;"></span>'
          : `<span class="watcher-details__value">${escapeHtml(status?.time || '')} ${escapeHtml(status?.date || '')}</span>`
        }
      </div>
    </div>
  `;
}

/**
 * Render card footer with action buttons
 */
function renderFooter(index, controller, isLoading = false) {
  if (isLoading) {
    return `
      <div class="watcher-card__footer">
        <span class="watcher-skeleton watcher-skeleton--btn"></span>
        <span class="watcher-skeleton watcher-skeleton--btn"></span>
        <span class="watcher-skeleton watcher-skeleton--btn"></span>
        <span class="watcher-skeleton watcher-skeleton--btn"></span>
      </div>
    `;
  }

  const testActive = controller.testMode?.enabled ? 'active' : '';
  const testTitle = controller.testMode?.enabled ? 'Test mode is ON - Click to disable' : 'Click to enable test mode';

  // Check if F16V5 (product_code 130) - has eFuse support
  const isF16V5 = controller.status?.product_code === 130;
  const fuseControls = isF16V5 ? `
    <div class="watcher-card__footer-row watcher-fuse-controls">
      <button class="btn btn-sm btn-outline-success" onclick="page.setFuses(${index}, true)" title="Enable all fuses">
        <i class="fas fa-bolt"></i> Fuses On
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="page.setFuses(${index}, false)" title="Disable all fuses">
        <i class="fas fa-bolt"></i> Fuses Off
      </button>
      <button class="btn btn-sm btn-outline-info" onclick="page.resetFuses(${index})" title="Reset all tripped fuses">
        <i class="fas fa-redo"></i> Reset Fuses
      </button>
    </div>
  ` : '';

  return `
    <div class="watcher-card__footer">
      <a href="http://${escapeHtml(controller.host)}/" target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-external-link-alt"></i> Web UI
      </a>
      <button class="btn btn-sm btn-outline-warning watcher-test-btn ${testActive}"
              onclick="page.toggleTestMode(${index})" title="${testTitle}">
        <i class="fas fa-lightbulb"></i> Test
      </button>
      <button class="btn btn-sm btn-outline-danger" onclick="page.rebootController(${index})" title="Reboot controller">
        <i class="fas fa-power-off"></i>
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="page.refreshController(${index})">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>
    ${fuseControls}
  `;
}

// =============================================================================
// Card Creation
// =============================================================================

/**
 * Create controller card (unified for skeleton and live data)
 */
export function createControllerCard(controller, index, isLoading = false) {
  const card = document.createElement('div');
  const host = controller.host || controller;
  const isOnline = isLoading ? null : controller.online;
  const status = controller.status;

  // Determine card state
  let cardClass = 'watcher-card';
  let headerClass = 'watcher-card__header';
  let headerIcon = 'fa-microchip';
  let badgeHtml = '';

  if (isLoading) {
    headerClass += ' watcher-card__header--loading';
    headerIcon = 'fa-spinner fa-spin';
    badgeHtml = '<span class="watcher-skeleton watcher-skeleton--badge"></span>';
  } else if (!isOnline) {
    cardClass += ' watcher-card--offline';
    headerClass += ' watcher-card__header--offline';
    headerIcon = 'fa-times-circle';
    badgeHtml = '<span class="watcher-card__badge watcher-card__badge--offline">Offline</span>';
  } else {
    badgeHtml = `<span class="watcher-card__badge watcher-card__badge--online">${escapeHtml(status?.model || '')}</span>`;
  }

  card.className = cardClass;
  card.id = 'controller-' + index;

  // Offline card - simplified body
  if (!isLoading && !isOnline) {
    card.innerHTML = `
      <div class="${headerClass}">
        <div class="watcher-card__title"><i class="fas ${headerIcon}"></i> ${escapeHtml(host)}</div>
        ${badgeHtml}
      </div>
      <div class="watcher-card__body watcher-card__body--offline">
        <i class="fas fa-plug"></i>
        <p>Controller not responding</p>
        <small>${escapeHtml(controller.error || 'Connection failed')}</small>
      </div>
    `;
    return card;
  }

  // Online or loading card
  const displayName = isLoading ? host : (status?.name || host);
  card.innerHTML = `
    <div class="${headerClass}">
      <div class="watcher-card__title"><i class="fas ${headerIcon}"></i> ${escapeHtml(displayName)}</div>
      ${badgeHtml}
    </div>
    <div class="watcher-card__body">
      ${renderQuickStats(status, isLoading)}
      ${renderTemperatures(status, isLoading)}
      ${renderVoltages(status, isLoading)}
      ${renderPixels(status, isLoading)}
      ${renderDetails(host, status, isLoading)}
    </div>
    ${renderFooter(index, controller, isLoading)}
  `;

  return card;
}

// =============================================================================
// UI Actions
// =============================================================================

/**
 * Toggle configuration panel
 */
export function toggleConfig() {
  document.getElementById('configPanel').classList.toggle('visible');
}

/**
 * Hide discovery results panel
 */
export function hideDiscoveryResults() {
  document.getElementById('discoveryResults').classList.remove('visible');
}

/**
 * Save configuration
 */
export async function saveConfiguration() {
  const hosts = document.getElementById('falconHosts').value;
  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/config', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ hosts })
    });
    if (response.ok) {
      toggleConfig();
      loadAllControllers();
    } else {
      alert('Failed to save configuration');
    }
  } catch (error) {
    console.error('Error saving configuration:', error);
    alert('Error saving configuration');
  }
}

// =============================================================================
// Data Loading
// =============================================================================

/**
 * Show skeleton cards while loading
 */
function showSkeletonCards(hosts) {
  const grid = document.getElementById('controllersGrid');
  document.getElementById('loadingIndicator').style.display = 'none';
  document.getElementById('noControllersMessage').style.display = 'none';
  grid.style.display = 'grid';
  grid.innerHTML = '';
  hosts.forEach((host, index) => {
    grid.appendChild(createControllerCard({ host }, index, true));
  });
}

/**
 * Render all controller cards
 */
function renderControllers() {
  const grid = document.getElementById('controllersGrid');
  grid.innerHTML = '';
  state.controllers.forEach((controller, index) => {
    grid.appendChild(createControllerCard(controller, index, false));
  });
}

/**
 * Load all controllers
 */
export async function loadAllControllers(showLoading = true) {
  if (state.isRefreshing) return;
  state.isRefreshing = true;

  const refreshBtn = document.querySelector('.refreshButton i');
  if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

  const grid = document.getElementById('controllersGrid');
  const hasSkeletons = grid.querySelector('.watcher-skeleton') !== null;

  if (showLoading && !hasSkeletons) {
    document.getElementById('loadingIndicator').style.display = 'block';
    document.getElementById('controllersGrid').style.display = 'none';
    document.getElementById('noControllersMessage').style.display = 'none';
  }

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/status');
    const data = await response.json();

    if (data.success && data.controllers?.length > 0) {
      state.controllers = data.controllers;
      renderControllers();
      document.getElementById('controllersGrid').style.display = 'grid';
      document.getElementById('noControllersMessage').style.display = 'none';
    } else if (data.controllers?.length === 0) {
      document.getElementById('noControllersMessage').style.display = 'block';
      document.getElementById('controllersGrid').style.display = 'none';
    } else {
      throw new Error(data.error || 'Failed to load controllers');
    }

    document.getElementById('loadingIndicator').style.display = 'none';
    document.getElementById('lastUpdate').textContent = 'Last updated: ' + new Date().toLocaleTimeString();

  } catch (error) {
    console.error('Error loading controllers:', error);
    if (!hasSkeletons) {
      document.getElementById('loadingIndicator').innerHTML = `
        <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
        <p style="color: #dc3545;">Error loading controllers</p>
        <button class="btn btn-primary" onclick="watcher.pages.falconMonitor.loadAllControllers()">Retry</button>
      `;
    }
  } finally {
    state.isRefreshing = false;
    if (refreshBtn) refreshBtn.style.animation = '';
  }
}

/**
 * Refresh a single controller
 */
export async function refreshController(index) {
  const controller = state.controllers[index];
  if (!controller) return;

  const card = document.getElementById('controller-' + index);
  if (card) card.style.opacity = '0.5';

  try {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/status?host=' + encodeURIComponent(controller.host));
    const data = await response.json();

    if (data.success && data.controllers?.length > 0) {
      state.controllers[index] = data.controllers[0];
      const newCard = createControllerCard(state.controllers[index], index, false);
      card.replaceWith(newCard);
      return;
    }
  } catch (error) {
    console.error('Error refreshing controller:', error);
  }

  const currentCard = document.getElementById('controller-' + index);
  if (currentCard) currentCard.style.opacity = '1';
}

// =============================================================================
// Controller Actions
// =============================================================================

/**
 * Toggle test mode on a controller
 */
export async function toggleTestMode(index) {
  const controller = state.controllers[index];
  if (!controller?.online) return;

  const card = document.getElementById('controller-' + index);
  const testBtn = card?.querySelector('.watcher-test-btn');

  await withButtonLoading(testBtn, 'fas fa-lightbulb', async () => {
    const currentlyEnabled = controller.testMode?.enabled || false;
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: controller.host, enable: !currentlyEnabled, testMode: 5 })
    });

    const data = await response.json();
    if (data.success) {
      if (!controller.testMode) controller.testMode = { enabled: false, mode: 0 };
      controller.testMode.enabled = data.testEnabled;
      testBtn?.classList.toggle('active', data.testEnabled);
      if (testBtn) testBtn.title = data.testEnabled ? 'Test mode is ON - Click to disable' : 'Click to enable test mode';
    } else {
      alert('Failed to toggle test mode: ' + (data.error || 'Unknown error'));
    }
  });
}

/**
 * Reboot a controller
 */
export async function rebootController(index) {
  const controller = state.controllers[index];
  if (!controller?.online) return;
  if (!confirm(`Are you sure you want to reboot ${controller.status?.name || controller.host}?`)) return;

  const card = document.getElementById('controller-' + index);
  const rebootBtn = card?.querySelector('.btn-outline-danger');

  await withButtonLoading(rebootBtn, 'fas fa-power-off', async () => {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/reboot', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: controller.host })
    });

    const data = await response.json();
    if (data.success) {
      controller.online = false;
      card.replaceWith(createControllerCard(controller, index, false));
    } else {
      alert('Failed to reboot: ' + (data.error || 'Unknown error'));
    }
  });
}

/**
 * Set fuses on/off for a controller
 */
export async function setFuses(index, enable) {
  const controller = state.controllers[index];
  if (!controller?.online) return;

  const action = enable ? 'enable' : 'disable';
  if (!confirm(`Are you sure you want to ${action} all fuses on ${controller.status?.name || controller.host}?`)) return;

  const card = document.getElementById('controller-' + index);
  const btn = card?.querySelector(enable ? '.btn-outline-success' : '.watcher-fuse-controls .btn-outline-secondary');

  await withButtonLoading(btn, 'fas fa-bolt', async () => {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/fuses/master', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: controller.host, enable })
    });

    const data = await response.json();
    if (data.success) {
      refreshController(index);
    } else {
      alert('Failed to ' + action + ' fuses: ' + (data.error || 'Unknown error'));
    }
  });
}

/**
 * Reset tripped fuses on a controller
 */
export async function resetFuses(index) {
  const controller = state.controllers[index];
  if (!controller?.online) return;

  if (!confirm(`Reset all fuses on ${controller.status?.name || controller.host}?`)) return;

  const card = document.getElementById('controller-' + index);
  const btn = card?.querySelector('.btn-outline-info');

  await withButtonLoading(btn, 'fas fa-redo', async () => {
    const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/fuses/reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ host: controller.host })
    });

    const data = await response.json();
    if (data.success) {
      refreshController(index);
    } else {
      alert('Failed to reset fuses: ' + (data.error || 'Unknown error'));
    }
  });
}

// =============================================================================
// Discovery
// =============================================================================

/**
 * Discover controllers on the network
 */
export async function discoverControllers() {
  const discoverBtn = document.querySelector('button[onclick*="discoverControllers"]');
  const resultsDiv = document.getElementById('discoveryResults');
  const listDiv = document.getElementById('discoveryList');

  listDiv.innerHTML = '<div style="text-align:center;padding:1rem;"><i class="fas fa-spinner fa-spin"></i> Scanning network...<br><small style="color:#6c757d;">This may take a few minutes</small></div>';
  resultsDiv.classList.add('visible');

  let subnet = '';
  const ipMatch = window.location.hostname.match(/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/);
  if (ipMatch) subnet = ipMatch[1];

  await withButtonLoading(discoverBtn, 'fas fa-search', async () => {
    try {
      const url = '/api/plugin/fpp-plugin-watcher/falcon/discover' + (subnet ? '?subnet=' + subnet : '');
      const response = await fetch(url);
      const data = await response.json();

      if (data.success && data.controllers?.length > 0) {
        listDiv.innerHTML = `
          <div style="margin-bottom:0.5rem;color:#6c757d;font-size:0.875rem;">
            Found ${data.count} controller(s) on subnet ${escapeHtml(data.subnet)}
          </div>
          <div style="display:flex;flex-direction:column;gap:0.5rem;">
            ${data.controllers.map(c => `
              <div class="watcher-discovery-item">
                <div>
                  <strong>${escapeHtml(c.name || c.ip)}</strong>
                  ${c.model ? `<span style="color:#6c757d;font-size:0.875rem;">(${escapeHtml(c.model)})</span>` : ''}
                  <br><small style="color:#6c757d;">${escapeHtml(c.ip)}${c.firmware ? ` - FW: ${escapeHtml(c.firmware)}` : ''}</small>
                </div>
                <button class="btn btn-sm btn-outline-primary watcher-add-controller-btn" data-ip="${escapeHtml(c.ip)}">
                  <i class="fas fa-plus"></i> Add
                </button>
              </div>
            `).join('')}
          </div>
          <div style="margin-top:0.75rem;">
            <button class="btn btn-sm btn-primary" onclick="watcher.pages.falconMonitor.addAllDiscoveredControllers()">
              <i class="fas fa-plus-circle"></i> Add All
            </button>
          </div>
        `;
        // Attach click handlers
        listDiv.querySelectorAll('.watcher-add-controller-btn').forEach(btn => {
          btn.addEventListener('click', () => addDiscoveredController(btn.dataset.ip));
        });
      } else if (data.success) {
        listDiv.innerHTML = `<div style="text-align:center;padding:1rem;color:#6c757d;"><i class="fas fa-info-circle"></i> No Falcon controllers found on subnet ${escapeHtml(data.subnet)}</div>`;
      } else {
        listDiv.innerHTML = `<div style="text-align:center;padding:1rem;color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.error || 'Discovery failed')}</div>`;
      }
    } catch (error) {
      console.error('Error discovering controllers:', error);
      listDiv.innerHTML = '<div style="text-align:center;padding:1rem;color:#dc3545;"><i class="fas fa-exclamation-triangle"></i> Error during discovery</div>';
    }
  });
}

/**
 * Add a discovered controller to the host list
 */
export function addDiscoveredController(ip) {
  const hostsInput = document.getElementById('falconHosts');
  const currentHosts = hostsInput.value.split(',').map(h => h.trim()).filter(h => h);
  if (!currentHosts.includes(ip)) {
    currentHosts.push(ip);
    hostsInput.value = currentHosts.join(', ');
  }
}

/**
 * Add all discovered controllers to the host list
 */
export function addAllDiscoveredControllers() {
  document.querySelectorAll('#discoveryList .watcher-add-controller-btn').forEach(btn => {
    if (btn.dataset.ip) addDiscoveredController(btn.dataset.ip);
  });
}

// =============================================================================
// Initialization and Cleanup
// =============================================================================

/**
 * Initialize the Falcon Monitor page
 */
async function initFalconMonitor() {
  // Show skeleton cards for configured hosts
  if (state.configuredHosts.length > 0) {
    showSkeletonCards(state.configuredHosts);
  }

  // Load temperature preference
  try {
    const tempResponse = await fetch('/api/settings/temperatureInF');
    const tempData = await tempResponse.json();
    state.useFahrenheit = (tempData.value === "1" || tempData.value === 1);
  } catch (e) {
    console.warn('Could not fetch temperature setting');
  }

  // Load controllers
  loadAllControllers();

  // Set up auto-refresh
  state.autoRefreshInterval = setInterval(() => loadAllControllers(false), 30000);

  // Cleanup on page unload
  window.addEventListener('beforeunload', () => {
    if (state.autoRefreshInterval) {
      clearInterval(state.autoRefreshInterval);
      state.autoRefreshInterval = null;
    }
  });
}

// =============================================================================
// Public Page Interface
// =============================================================================

/**
 * Falcon Monitor page module
 */
export const falconMonitor = {
  pageId: 'falconMonitorUI',

  /**
   * Initialize with config from PHP
   */
  init(config) {
    state.config = config;
    state.configuredHosts = config.configuredHosts || [];
    initFalconMonitor();
  },

  /**
   * Cleanup and destroy
   */
  destroy() {
    if (state.autoRefreshInterval) {
      clearInterval(state.autoRefreshInterval);
    }
    state = {
      controllers: [],
      isRefreshing: false,
      autoRefreshInterval: null,
      useFahrenheit: false,
      configuredHosts: [],
      config: {}
    };
  },

  // Public methods for onclick handlers
  loadAllControllers,
  refreshController,
  toggleTestMode,
  rebootController,
  setFuses,
  resetFuses,
  discoverControllers,
  addDiscoveredController,
  addAllDiscoveredControllers,
  toggleConfig,
  hideDiscoveryResults,
  saveConfiguration,

  // Helper methods for testing
  formatTemperature,
  getTempColor,
  getVoltageStatus,
  createControllerCard
};

export default falconMonitor;
