<?php
/**
 * Falcon Controller Monitor UI
 *
 * Dashboard for monitoring multiple Falcon pixel controllers
 * Displays status, temperatures, voltages, and configuration
 */

// Include FPP common functions
include_once '/opt/fpp/www/common.php';

// Get configured Falcon controllers from plugin settings
include_once __DIR__ . '/lib/config.php';
$watcherConfig = readPluginConfig();
$falconHosts = !empty($watcherConfig['falconControllers']) ? $watcherConfig['falconControllers'] : '';
?>
<link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
<link rel="stylesheet" href="/css/fpp.css">

<style>
/* Falcon Monitor CSS */
.falconMonitorContainer {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1rem;
}

.toolbar {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.toolbar .lastUpdate {
    margin-left: auto;
    color: #6c757d;
    font-size: 0.875rem;
}

.configPanel {
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    display: none;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.configPanel.visible {
    display: block;
}

.configForm label {
    font-weight: 500;
    margin-bottom: 0.5rem;
    display: block;
}

.configForm .inputGroup {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.configForm .inputGroup input {
    flex: 1;
}

.controllersGrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 1.5rem;
}

.controllerCard {
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.controllerCard:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.controllerCard.offline {
    opacity: 0.8;
}

.cardHeader {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cardHeader.offline {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
}

.controllerName {
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.statusBadge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.statusBadge.online {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

.statusBadge.offline {
    background-color: #f8d7da;
    color: #721c24;
}

.cardBody {
    padding: 1.25rem;
}

.cardBody.offlineMessage {
    text-align: center;
    padding: 2rem;
    color: #6c757d;
}

.cardBody.offlineMessage i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.quickStats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #f0f0f0;
}

.quickStat {
    text-align: center;
}

.quickStatLabel {
    display: block;
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.quickStatValue {
    display: block;
    font-size: 0.9rem;
    font-weight: 600;
    color: #212529;
}

.sectionTitle {
    font-size: 0.85rem;
    font-weight: 600;
    color: #495057;
    margin: 1rem 0 0.75rem 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.sectionTitle i {
    color: #667eea;
}

.tempGrid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
}

.tempItem {
    text-align: center;
}

.tempLabel {
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 0.25rem;
}

.tempValue {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.tempBar {
    height: 4px;
    background-color: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
}

.tempBarFill {
    height: 100%;
    transition: width 0.3s ease;
}

.voltageInfo {
    display: flex;
    gap: 1.5rem;
}

.voltageItem {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.voltageLabel {
    font-size: 0.875rem;
    color: #6c757d;
}

.voltageValue {
    font-weight: 600;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
}

.voltageValue.good {
    background-color: #d4edda;
    color: #155724;
}

.voltageValue.warning {
    background-color: #fff3cd;
    color: #856404;
}

.voltageValue.normal {
    background-color: #e9ecef;
    color: #495057;
}

.pixelInfo {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.pixelTotal {
    display: flex;
    flex-direction: column;
}

.pixelTotalLabel {
    font-size: 0.75rem;
    color: #6c757d;
}

.pixelTotalValue {
    font-size: 1.5rem;
    font-weight: 700;
    color: #667eea;
}

.pixelBanks {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.pixelBank {
    font-size: 0.75rem;
    color: #6c757d;
    background-color: #f8f9fa;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
}

.controllerDetails {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #f0f0f0;
}

.detailRow {
    display: flex;
    justify-content: space-between;
    padding: 0.35rem 0;
    font-size: 0.875rem;
}

.detailLabel {
    color: #6c757d;
}

.detailValue {
    font-weight: 500;
    color: #212529;
}

.cardFooter {
    background-color: #f8f9fa;
    padding: 0.75rem 1.25rem;
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    border-top: 1px solid #e9ecef;
}

.noControllersPanel {
    text-align: center;
    padding: 3rem;
    background-color: #fff;
    border: 2px dashed #dee2e6;
    border-radius: 0.5rem;
    color: #6c757d;
}

.noControllersPanel i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.noControllersPanel h4 {
    margin-bottom: 0.5rem;
    color: #495057;
}

.noControllersPanel p {
    margin-bottom: 1.5rem;
}

.systemLoadingSpinner {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.systemLoadingSpinner i {
    font-size: 3rem;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.systemRefreshButton {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    z-index: 1000;
}

.systemRefreshButton:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.systemRefreshButton i {
    font-size: 1.5rem;
}

.systemPanelTitle {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: #212529;
    border-bottom: 2px solid #007bff;
    padding-bottom: 0.5rem;
}

@media (max-width: 768px) {
    .controllersGrid {
        grid-template-columns: 1fr;
    }
    .quickStats {
        grid-template-columns: repeat(2, 1fr);
    }
    .tempGrid {
        grid-template-columns: repeat(3, 1fr);
    }
    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .toolbar .lastUpdate {
        margin-left: 0;
        text-align: center;
    }
    .configForm .inputGroup {
        flex-direction: column;
    }
    .cardFooter {
        flex-direction: column;
    }
    .cardFooter .btn {
        width: 100%;
    }
}
</style>

<div class="falconMonitorContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-broadcast-tower"></i> Falcon Controller Monitor
    </h2>

    <!-- Configuration Section -->
    <div class="configPanel" id="configPanel">
        <div class="systemPanelTitle">
            <i class="fas fa-cog"></i> Controller Configuration
        </div>
        <div class="configForm">
            <label for="falconHosts">Falcon Controller IPs (comma-separated):</label>
            <div class="inputGroup">
                <input type="text" id="falconHosts" class="form-control"
                       placeholder="192.168.1.100, 192.168.1.101, 192.168.1.102"
                       value="<?php echo htmlspecialchars($falconHosts); ?>">
                <button class="btn btn-primary" onclick="saveConfiguration()">
                    <i class="fas fa-save"></i> Save
                </button>
                <button class="btn btn-secondary" onclick="toggleConfig()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            <div style="margin-top: 0.75rem; padding: 0.6rem 1rem; background: #e7f3ff; border: 1px solid #b3d7ff; border-radius: 4px;">
                <i class="fas fa-microchip" style="color: #0066cc;"></i>
                <strong>Supported Controllers:</strong> F4V2, F16V2, F4V3, F16V3, F48
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <button class="btn btn-outline-secondary btn-sm" onclick="toggleConfig()">
            <i class="fas fa-cog"></i> Configure Controllers
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="loadAllControllers()">
            <i class="fas fa-sync-alt"></i> Refresh All
        </button>
        <span class="lastUpdate" id="lastUpdate">Last updated: --</span>
    </div>

    <!-- Loading Indicator -->
    <div id="loadingIndicator" class="systemLoadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading Falcon controllers...</p>
    </div>

    <!-- No Controllers Message -->
    <div id="noControllersMessage" class="noControllersPanel" style="display: none;">
        <i class="fas fa-info-circle"></i>
        <h4>No Falcon Controllers Configured</h4>
        <p>Click "Configure Controllers" to add your Falcon controller IP addresses.</p>
        <button class="btn btn-primary" onclick="toggleConfig()">
            <i class="fas fa-plus"></i> Add Controllers
        </button>
    </div>

    <!-- Controllers Grid -->
    <div id="controllersGrid" class="controllersGrid" style="display: none;">
        <!-- Controller cards will be dynamically inserted here -->
    </div>

    <!-- Refresh Button -->
    <button class="systemRefreshButton" onclick="loadAllControllers()" title="Refresh All Controllers">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<script>
    // Global state
    let controllers = [];
    let isRefreshing = false;
    let autoRefreshInterval = null;
    let useFahrenheit = false;

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', async function() {
        // Get temperature preference
        try {
            const tempSettingResponse = await fetch('/api/settings/temperatureInF');
            const tempSettingData = await tempSettingResponse.json();
            useFahrenheit = (tempSettingData.value === "1" || tempSettingData.value === 1);
        } catch (e) {
            console.warn('Could not fetch temperature setting');
        }

        // Load controllers
        loadAllControllers();

        // Auto-refresh every 30 seconds
        autoRefreshInterval = setInterval(() => loadAllControllers(false), 30000);
    });

    // Toggle configuration panel
    function toggleConfig() {
        const panel = document.getElementById('configPanel');
        panel.classList.toggle('visible');
    }

    // Save configuration
    async function saveConfiguration() {
        const hosts = document.getElementById('falconHosts').value;

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hosts: hosts })
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

    // Load all controllers
    async function loadAllControllers(showLoading = true) {
        if (isRefreshing) return;
        isRefreshing = true;

        const refreshBtn = document.querySelector('.systemRefreshButton i');
        if (refreshBtn) {
            refreshBtn.style.animation = 'spin 1s linear infinite';
        }

        if (showLoading) {
            document.getElementById('loadingIndicator').style.display = 'block';
            document.getElementById('controllersGrid').style.display = 'none';
            document.getElementById('noControllersMessage').style.display = 'none';
        }

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/status');
            const data = await response.json();

            if (data.success && data.controllers && data.controllers.length > 0) {
                controllers = data.controllers;
                renderControllers();
                document.getElementById('controllersGrid').style.display = 'grid';
                document.getElementById('noControllersMessage').style.display = 'none';
            } else if (data.controllers && data.controllers.length === 0) {
                document.getElementById('noControllersMessage').style.display = 'block';
                document.getElementById('controllersGrid').style.display = 'none';
            } else {
                throw new Error(data.error || 'Failed to load controllers');
            }

            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('lastUpdate').textContent = 'Last updated: ' + new Date().toLocaleTimeString();

        } catch (error) {
            console.error('Error loading controllers:', error);
            document.getElementById('loadingIndicator').innerHTML = `
                <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                <p style="color: #dc3545;">Error loading controllers</p>
                <button class="btn btn-primary" onclick="loadAllControllers()">Retry</button>
            `;
        } finally {
            isRefreshing = false;
            if (refreshBtn) {
                refreshBtn.style.animation = '';
            }
        }
    }

    // Render all controller cards
    function renderControllers() {
        const grid = document.getElementById('controllersGrid');
        grid.innerHTML = '';

        controllers.forEach((controller, index) => {
            const card = createControllerCard(controller, index);
            grid.appendChild(card);
        });
    }

    // Create a controller card
    function createControllerCard(controller, index) {
        const card = document.createElement('div');
        card.className = 'controllerCard' + (controller.online ? '' : ' offline');
        card.id = 'controller-' + index;

        if (!controller.online) {
            card.innerHTML = `
                <div class="cardHeader offline">
                    <div class="controllerName">
                        <i class="fas fa-times-circle"></i>
                        ${escapeHtml(controller.host)}
                    </div>
                    <span class="statusBadge offline">Offline</span>
                </div>
                <div class="cardBody offlineMessage">
                    <i class="fas fa-plug"></i>
                    <p>Controller not responding</p>
                    <small>${escapeHtml(controller.error || 'Connection failed')}</small>
                </div>
            `;
            return card;
        }

        const status = controller.status;
        const temps = getTemperatureInfo(status);
        const voltageInfo = getVoltageInfo(status);

        card.innerHTML = `
            <div class="cardHeader">
                <div class="controllerName">
                    <i class="fas fa-microchip"></i>
                    ${escapeHtml(status.name || controller.host)}
                </div>
                <span class="statusBadge online">${escapeHtml(status.model)}</span>
            </div>

            <div class="cardBody">
                <!-- Quick Stats -->
                <div class="quickStats">
                    <div class="quickStat">
                        <span class="quickStatLabel">Firmware</span>
                        <span class="quickStatValue">${escapeHtml(status.firmware_version)}</span>
                    </div>
                    <div class="quickStat">
                        <span class="quickStatLabel">Mode</span>
                        <span class="quickStatValue">${escapeHtml(status.mode_name)}</span>
                    </div>
                    <div class="quickStat">
                        <span class="quickStatLabel">Ports</span>
                        <span class="quickStatValue">${status.num_ports}</span>
                    </div>
                    <div class="quickStat">
                        <span class="quickStatLabel">Uptime</span>
                        <span class="quickStatValue">${escapeHtml(status.uptime)}</span>
                    </div>
                </div>

                <!-- Temperature Section -->
                <div class="sectionTitle">
                    <i class="fas fa-thermometer-half"></i> Temperatures
                </div>
                <div class="tempGrid">
                    ${temps.map(t => `
                        <div class="tempItem">
                            <div class="tempLabel">${t.label}</div>
                            <div class="tempValue" style="color: ${t.color};">
                                ${formatTemperature(t.celsius)}
                            </div>
                            <div class="tempBar">
                                <div class="tempBarFill" style="width: ${t.percent}%; background-color: ${t.color};"></div>
                            </div>
                        </div>
                    `).join('')}
                </div>

                <!-- Voltage Section -->
                <div class="sectionTitle">
                    <i class="fas fa-bolt"></i> Power
                </div>
                <div class="voltageInfo">
                    ${voltageInfo.map(v => `
                        <div class="voltageItem">
                            <span class="voltageLabel">${v.label}</span>
                            <span class="voltageValue ${v.status}">${escapeHtml(v.value)}</span>
                        </div>
                    `).join('')}
                </div>

                <!-- Pixel Counts -->
                <div class="sectionTitle">
                    <i class="fas fa-lightbulb"></i> Pixels
                </div>
                <div class="pixelInfo">
                    <div class="pixelTotal">
                        <span class="pixelTotalLabel">Total Pixels</span>
                        <span class="pixelTotalValue">${(status.pixels_bank0 + status.pixels_bank1 + status.pixels_bank2).toLocaleString()}</span>
                    </div>
                    <div class="pixelBanks">
                        <span class="pixelBank">Bank 0: ${status.pixels_bank0.toLocaleString()}</span>
                        <span class="pixelBank">Bank 1: ${status.pixels_bank1.toLocaleString()}</span>
                        <span class="pixelBank">Bank 2: ${status.pixels_bank2.toLocaleString()}</span>
                    </div>
                </div>

                <!-- Controller Details -->
                <div class="controllerDetails">
                    <div class="detailRow">
                        <span class="detailLabel">IP Address</span>
                        <span class="detailValue">${escapeHtml(controller.host)}</span>
                    </div>
                    <div class="detailRow">
                        <span class="detailLabel">Controller Time</span>
                        <span class="detailValue">${escapeHtml(status.time)} ${escapeHtml(status.date)}</span>
                    </div>
                </div>
            </div>

            <div class="cardFooter">
                <a href="http://${escapeHtml(controller.host)}/" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-external-link-alt"></i> Open Web UI
                </a>
                <button class="btn btn-sm btn-outline-secondary" onclick="refreshController(${index})">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        `;

        return card;
    }

    // Get temperature info with color coding
    function getTemperatureInfo(status) {
        const temps = [];

        const addTemp = (label, celsius) => {
            if (celsius === undefined || celsius === null) return;

            let color = '#38ef7d'; // Green
            let percent = Math.min((celsius / 100) * 100, 100);

            if (celsius > 80) {
                color = '#f5576c'; // Red
            } else if (celsius > 60) {
                color = '#ffc107'; // Yellow
            } else if (celsius > 40) {
                color = '#4facfe'; // Blue
            }

            temps.push({ label, celsius, color, percent });
        };

        addTemp('Board', status.temperature1);
        addTemp('CPU', status.temperature2);
        addTemp('Aux', status.temperature3);

        return temps;
    }

    // Get voltage info
    function getVoltageInfo(status) {
        const voltages = [];

        if (status.voltage1) {
            voltages.push({
                label: 'V1',
                value: status.voltage1,
                status: 'normal'
            });
        }

        if (status.voltage2) {
            // Parse voltage value and check if in range
            const v2Match = status.voltage2.match(/([\d.]+)/);
            const v2Value = v2Match ? parseFloat(v2Match[1]) : 0;
            let v2Status = 'normal';

            if (v2Value > 0 && v2Value < 11) {
                v2Status = 'warning';
            } else if (v2Value >= 11 && v2Value <= 13) {
                v2Status = 'good';
            } else if (v2Value > 13) {
                v2Status = 'warning';
            }

            voltages.push({
                label: 'Input',
                value: status.voltage2,
                status: v2Status
            });
        }

        return voltages;
    }

    // Format temperature based on preference
    function formatTemperature(celsius) {
        if (useFahrenheit) {
            const fahrenheit = (celsius * 9/5) + 32;
            return fahrenheit.toFixed(1) + '°F';
        }
        return celsius.toFixed(1) + '°C';
    }

    // Refresh single controller
    async function refreshController(index) {
        const controller = controllers[index];
        if (!controller) return;

        const card = document.getElementById('controller-' + index);
        if (card) {
            card.style.opacity = '0.5';
        }

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/falcon/status?host=' +
                encodeURIComponent(controller.host));
            const data = await response.json();

            if (data.success && data.controllers && data.controllers.length > 0) {
                controllers[index] = data.controllers[0];
                const newCard = createControllerCard(controllers[index], index);
                card.replaceWith(newCard);
            }
        } catch (error) {
            console.error('Error refreshing controller:', error);
        }

        if (card) {
            card.style.opacity = '1';
        }
    }

    // Escape HTML
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
</script>
