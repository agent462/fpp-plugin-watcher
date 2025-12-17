<?php
/**
 * Falcon Controller Monitor UI - Dashboard for Falcon pixel controllers
 */
include_once '/opt/fpp/www/common.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/ui/common.php';

$watcherConfig = readPluginConfig();
$falconHosts = !empty($watcherConfig['falconControllers']) ? $watcherConfig['falconControllers'] : '';

renderCSSIncludes(false);
renderCommonJS();
?>

<div class="metricsContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-broadcast-tower"></i> Falcon Controller Monitor
    </h2>

    <!-- Configuration Section -->
    <div class="watcher-config-panel" id="configPanel">
        <div class="chartTitle">
            <i class="fas fa-cog"></i> Controller Configuration
        </div>
        <div>
            <label for="falconHosts">Falcon Controller IPs (comma-separated):</label>
            <div class="input-group">
                <input type="text" id="falconHosts" class="form-control"
                       placeholder="192.168.1.100, 192.168.1.101, 192.168.1.102"
                       value="<?php echo htmlspecialchars($falconHosts); ?>">
                <button class="btn btn-primary" onclick="saveConfiguration()">
                    <i class="fas fa-save"></i> Save
                </button>
                <button class="btn btn-success" onclick="discoverControllers()">
                    <i class="fas fa-search"></i> Discover
                </button>
                <button class="btn btn-secondary" onclick="toggleConfig()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            <div class="infoBox">
                <i class="fas fa-microchip"></i>
                <strong>Supported Controllers:</strong> F4V2, F16V2, F4V3, F16V3, F48, F16V4, F48V4, F16V5, F48V5, F32V5
            </div>

            <!-- Discovery Results -->
            <div class="watcher-discovery-results" id="discoveryResults">
                <div class="watcher-discovery-results__container">
                    <div class="watcher-discovery-results__header">
                        <strong><i class="fas fa-broadcast-tower"></i> Discovered Controllers</strong>
                        <button class="btn btn-sm btn-outline-secondary" onclick="hideDiscoveryResults()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div id="discoveryList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="watcher-toolbar">
        <button class="btn btn-outline-secondary btn-sm" onclick="toggleConfig()">
            <i class="fas fa-cog"></i> Configure Controllers
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadAllControllers()">
            <i class="fas fa-sync-alt"></i> Refresh All
        </button>
        <span class="last-update" id="lastUpdate">Last updated: --</span>
    </div>

    <!-- Loading Indicator -->
    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading Falcon controllers...</p>
    </div>

    <!-- No Controllers Message -->
    <div id="noControllersMessage" class="empty-message" style="display: none;">
        <i class="fas fa-info-circle"></i>
        <h3>No Falcon Controllers Configured</h3>
        <p>Click "Configure Controllers" to add your Falcon controller IP addresses.</p>
        <button class="btn btn-primary" onclick="toggleConfig()">
            <i class="fas fa-plus"></i> Add Controllers
        </button>
    </div>

    <!-- Controllers Grid -->
    <div id="controllersGrid" class="watcher-card-grid" style="display: none;"></div>

    <!-- Refresh Button -->
    <button class="refreshButton" onclick="loadAllControllers()" title="Refresh All Controllers">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<script>
// =============================================================================
// State
// =============================================================================
let controllers = [];
let isRefreshing = false;
let autoRefreshInterval = null;
let useFahrenheit = false;

const configuredHosts = <?php
    $hostsArray = array_filter(array_map('trim', explode(',', $falconHosts)));
    echo json_encode($hostsArray);
?>;

// =============================================================================
// Helpers
// =============================================================================

function formatTemperature(celsius) {
    if (useFahrenheit) {
        return ((celsius * 9/5) + 32).toFixed(1) + '°F';
    }
    return celsius.toFixed(1) + '°C';
}

// =============================================================================
// Temperature & Voltage Helpers
// =============================================================================

function getTempColor(celsius) {
    if (celsius > 80) return '#f5576c';
    if (celsius > 60) return '#ffc107';
    if (celsius > 40) return '#4facfe';
    return '#38ef7d';
}

function getVoltageStatus(value) {
    if (value > 0 && value < 11) return 'warning';
    if (value >= 11 && value <= 13) return 'good';
    if (value > 13) return 'warning';
    return 'normal';
}

// =============================================================================
// Card Section Renderers
// =============================================================================

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

    return `
        <div class="watcher-card__footer">
            <a href="http://${escapeHtml(controller.host)}/" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-external-link-alt"></i> Web UI
            </a>
            <button class="btn btn-sm btn-outline-warning watcher-test-btn ${testActive}"
                    onclick="toggleTestMode(${index})" title="${testTitle}">
                <i class="fas fa-lightbulb"></i> Test
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="rebootController(${index})" title="Reboot controller">
                <i class="fas fa-power-off"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="refreshController(${index})">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    `;
}

// =============================================================================
// Card Creation (unified for skeleton and live data)
// =============================================================================

function createControllerCard(controller, index, isLoading = false) {
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

function toggleConfig() {
    document.getElementById('configPanel').classList.toggle('visible');
}

function hideDiscoveryResults() {
    document.getElementById('discoveryResults').classList.remove('visible');
}

async function saveConfiguration() {
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

function renderControllers() {
    const grid = document.getElementById('controllersGrid');
    grid.innerHTML = '';
    controllers.forEach((controller, index) => {
        grid.appendChild(createControllerCard(controller, index, false));
    });
}

async function loadAllControllers(showLoading = true) {
    if (isRefreshing) return;
    isRefreshing = true;

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
            controllers = data.controllers;
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
                <button class="btn btn-primary" onclick="loadAllControllers()">Retry</button>
            `;
        }
    } finally {
        isRefreshing = false;
        if (refreshBtn) refreshBtn.style.animation = '';
    }
}

async function refreshController(index) {
    const controller = controllers[index];
    if (!controller) return;

    const card = document.getElementById('controller-' + index);
    if (card) card.style.opacity = '0.5';

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/status?host=' + encodeURIComponent(controller.host));
        const data = await response.json();

        if (data.success && data.controllers?.length > 0) {
            controllers[index] = data.controllers[0];
            const newCard = createControllerCard(controllers[index], index, false);
            card.replaceWith(newCard);
            return; // Card replaced, no need to reset opacity
        }
    } catch (error) {
        console.error('Error refreshing controller:', error);
    }

    // Only reset opacity if card wasn't replaced
    const currentCard = document.getElementById('controller-' + index);
    if (currentCard) currentCard.style.opacity = '1';
}

// =============================================================================
// Controller Actions
// =============================================================================

async function toggleTestMode(index) {
    const controller = controllers[index];
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

async function rebootController(index) {
    const controller = controllers[index];
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

// =============================================================================
// Discovery
// =============================================================================

async function discoverControllers() {
    const discoverBtn = document.querySelector('button[onclick="discoverControllers()"]');
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
                        <button class="btn btn-sm btn-primary" onclick="addAllDiscoveredControllers()">
                            <i class="fas fa-plus-circle"></i> Add All
                        </button>
                    </div>
                `;
                // Attach click handlers using data attributes
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

function addDiscoveredController(ip) {
    const hostsInput = document.getElementById('falconHosts');
    const currentHosts = hostsInput.value.split(',').map(h => h.trim()).filter(h => h);
    if (!currentHosts.includes(ip)) {
        currentHosts.push(ip);
        hostsInput.value = currentHosts.join(', ');
    }
}

function addAllDiscoveredControllers() {
    document.querySelectorAll('#discoveryList .watcher-add-controller-btn').forEach(btn => {
        if (btn.dataset.ip) addDiscoveredController(btn.dataset.ip);
    });
}

// =============================================================================
// Initialization
// =============================================================================

document.addEventListener('DOMContentLoaded', async function() {
    if (configuredHosts.length > 0) {
        showSkeletonCards(configuredHosts);
    }

    try {
        const tempResponse = await fetch('/api/settings/temperatureInF');
        const tempData = await tempResponse.json();
        useFahrenheit = (tempData.value === "1" || tempData.value === 1);
    } catch (e) {
        console.warn('Could not fetch temperature setting');
    }

    loadAllControllers();
    autoRefreshInterval = setInterval(() => loadAllControllers(false), 30000);

    // Cleanup auto-refresh on page unload to prevent memory leaks
    window.addEventListener('beforeunload', () => {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    });
});
</script>
