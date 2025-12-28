<?php
/**
 * Falcon Controller Monitor UI - Dashboard for Falcon pixel controllers
 */
require_once __DIR__ . '/../classes/autoload.php';
include_once __DIR__ . '/../lib/core/config.php';

use Watcher\UI\ViewHelpers;

$watcherConfig = readPluginConfig();
$falconHosts = !empty($watcherConfig['falconControllers']) ? $watcherConfig['falconControllers'] : '';
$hostsArray = array_filter(array_map('trim', explode(',', $falconHosts)));

ViewHelpers::renderCSSIncludes(false);
?>
<script>
window.watcherConfig = {
    configuredHosts: <?php echo json_encode($hostsArray); ?>
};
</script>

<div class="metricsContainer" data-watcher-page="falconMonitorUI">
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
                <button class="btn btn-primary" onclick="page.saveConfiguration()">
                    <i class="fas fa-save"></i> Save
                </button>
                <button class="btn btn-success" onclick="page.discoverControllers()">
                    <i class="fas fa-search"></i> Discover
                </button>
                <button class="btn btn-secondary" onclick="page.toggleConfig()">
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
                        <button class="btn btn-sm btn-outline-secondary" onclick="page.hideDiscoveryResults()">
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
        <button class="btn btn-outline-secondary btn-sm" onclick="page.toggleConfig()">
            <i class="fas fa-cog"></i> Configure Controllers
        </button>
        <button class="btn btn-outline-secondary btn-sm" onclick="page.loadAllControllers()">
            <i class="fas fa-sync-alt"></i> Refresh All
        </button>
        <span class="last-update" id="lastUpdate">Last updated: --</span>
    </div>

    <!-- Loading Indicator -->
    <?php ViewHelpers::renderLoadingSpinner('Loading Falcon controllers...'); ?>

    <!-- No Controllers Message -->
    <div id="noControllersMessage" class="empty-message" style="display: none;">
        <i class="fas fa-info-circle"></i>
        <h3>No Falcon Controllers Configured</h3>
        <p>Click "Configure Controllers" to add your Falcon controller IP addresses.</p>
        <button class="btn btn-primary" onclick="page.toggleConfig()">
            <i class="fas fa-plus"></i> Add Controllers
        </button>
    </div>

    <!-- Controllers Grid -->
    <div id="controllersGrid" class="watcher-card-grid" style="display: none;"></div>

    <!-- Refresh Button -->
    <?php ViewHelpers::renderRefreshButton('page.loadAllControllers()', 'Refresh All Controllers'); ?>
</div>
