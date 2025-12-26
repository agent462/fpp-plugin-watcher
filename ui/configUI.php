<?php
include_once __DIR__ . "/../lib/core/watcherCommon.php";
include_once __DIR__ . "/../lib/core/config.php";

require_once __DIR__ . '/../classes/autoload.php'; // Load class autoloader
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';

use Watcher\Core\Settings;
use Watcher\Core\Logger;
use Watcher\Http\ApiClient;
use Watcher\Controllers\EfuseHardware;
use Watcher\Controllers\NetworkAdapter;
use Watcher\MultiSync\SyncStatus;
use Watcher\UI\ViewHelpers;

// Render CSS includes (consistent with other UI pages)
ViewHelpers::renderCSSIncludes(false);

$statusMessage = '';
$statusType = '';

// Load current settings BEFORE form processing (for restart check comparison)
$oldConfig = readPluginConfig();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Get form values
    $connectivityCheckEnabled = isset($_POST['connectivityCheckEnabled']) ? 'true' : 'false';
    $checkInterval = intval($_POST['checkInterval']);
    $maxFailures = intval($_POST['maxFailures']);
    $networkAdapter = trim($_POST['networkAdapter']);
    $collectdEnabled = isset($_POST['collectdEnabled']) ? 'true' : 'false';
    $multiSyncMetricsEnabled = isset($_POST['multiSyncMetricsEnabled']) ? 'true' : 'false';
    $multiSyncPingEnabled = isset($_POST['multiSyncPingEnabled']) ? 'true' : 'false';
    $multiSyncPingInterval = intval($_POST['multiSyncPingInterval'] ?? 60);
    $falconMonitorEnabled = isset($_POST['falconMonitorEnabled']) ? 'true' : 'false';
    $controlUIEnabled = isset($_POST['controlUIEnabled']) ? 'true' : 'false';
    $mqttMonitorEnabled = isset($_POST['mqttMonitorEnabled']) ? 'true' : 'false';
    $mqttRetentionDays = intval($_POST['mqttRetentionDays'] ?? 60);
    $issueCheckOutputs = isset($_POST['issueCheckOutputs']) ? 'true' : 'false';
    $issueCheckSequences = isset($_POST['issueCheckSequences']) ? 'true' : 'false';
    $issueCheckOutputHostsNotInSync = isset($_POST['issueCheckOutputHostsNotInSync']) ? 'true' : 'false';
    $efuseMonitorEnabled = isset($_POST['efuseMonitorEnabled']) ? 'true' : 'false';
    $efuseCollectionInterval = intval($_POST['efuseCollectionInterval'] ?? 5);
    $efuseRetentionDays = intval($_POST['efuseRetentionDays'] ?? 7);

    // If 'default' is selected, auto-detect and save the actual interface
    if ($networkAdapter === 'default') {
        $networkAdapter = NetworkAdapter::getInstance()->detectActiveInterface();
        Logger::getInstance()->info("Auto-detected network adapter '$networkAdapter' from 'default' setting");
    }

    // Process test hosts (comes as array from form)
    $testHosts = [];
    if (isset($_POST['testHosts']) && is_array($_POST['testHosts'])) {
        foreach ($_POST['testHosts'] as $host) {
            $host = trim($host);
            if (!empty($host)) {
                $testHosts[] = $host;
            }
        }
    }

    // Validate
    $errors = [];
    if ($checkInterval < 5 || $checkInterval > 3600) {
        $errors[] = "Check interval must be between 5 and 3600 seconds";
    }
    if ($maxFailures < 1 || $maxFailures > 100) {
        $errors[] = "Max failures must be between 1 and 100";
    }
    if (empty($networkAdapter)) {
        $errors[] = "Network adapter must be specified";
    }
    if (empty($testHosts)) {
        $errors[] = "At least one test host must be specified";
    }
    if ($multiSyncPingInterval < 10 || $multiSyncPingInterval > 300) {
        $errors[] = "Multi-sync ping interval must be between 10 and 300 seconds";
    }
    if ($mqttRetentionDays < 1 || $mqttRetentionDays > 365) {
        $errors[] = "MQTT retention must be between 1 and 365 days";
    }
    if ($efuseCollectionInterval < 1 || $efuseCollectionInterval > 60) {
        $errors[] = "eFuse collection interval must be between 1 and 60 seconds";
    }
    if ($efuseRetentionDays < 1 || $efuseRetentionDays > 90) {
        $errors[] = "eFuse retention must be between 1 and 90 days";
    }

    if (empty($errors)) {
        // Save settings using WatcherWriteSettingToFile
        $settingsToSave = [
            'connectivityCheckEnabled' => $connectivityCheckEnabled,
            'checkInterval' => $checkInterval,
            'maxFailures' => $maxFailures,
            'networkAdapter' => $networkAdapter,
            'testHosts' => implode(',', $testHosts),
            'collectdEnabled' => $collectdEnabled,
            'multiSyncMetricsEnabled' => $multiSyncMetricsEnabled,
            'multiSyncPingEnabled' => $multiSyncPingEnabled,
            'multiSyncPingInterval' => $multiSyncPingInterval,
            'falconMonitorEnabled' => $falconMonitorEnabled,
            'controlUIEnabled' => $controlUIEnabled,
            'mqttMonitorEnabled' => $mqttMonitorEnabled,
            'mqttRetentionDays' => $mqttRetentionDays,
            'issueCheckOutputs' => $issueCheckOutputs,
            'issueCheckSequences' => $issueCheckSequences,
            'issueCheckOutputHostsNotInSync' => $issueCheckOutputHostsNotInSync,
            'efuseMonitorEnabled' => $efuseMonitorEnabled,
            'efuseCollectionInterval' => $efuseCollectionInterval,
            'efuseRetentionDays' => $efuseRetentionDays
        ];

        $settings = Settings::getInstance();
        foreach ($settingsToSave as $settingName => $settingValue) {
            $settings->writeSettingToFile($settingName, (string)$settingValue, WATCHERPLUGINNAME);
        }

        // Configure FPP MQTT settings based on enable/disable state
        $mqttConfigResult = configureFppMqttSettings($mqttMonitorEnabled === 'true');
        if (!$mqttConfigResult['success']) {
            Logger::getInstance()->info("Warning: Some FPP MQTT settings may not have been configured: " . implode(', ', $mqttConfigResult['messages']));
        }

        // Only set FPP restart flag if settings that require restart have changed
        // (collectd or MQTT changes still need restart; connectivity settings are hot-reloaded)
        if (settingsRequireRestart($oldConfig, $settingsToSave)) {
            $settings->writeSettingToFile('restartFlag', '1', WATCHERPLUGINNAME);
            $statusMessage = 'Settings saved! FPP restart required for collectd/MQTT/efuse process changes.';
        } else {
            $statusMessage = 'Settings saved! Changes take effect within 60 seconds.';
        }
        $statusType = 'success';

        // Reload config (force reload to bypass static cache after save)
        $config = readPluginConfig(true);
    } else {
        $statusMessage = 'Error saving settings: ' . implode(', ', $errors);
        $statusType = 'error';
    }
}

// Load/reload current settings (after form processing)
$config = readPluginConfig();

// Always detect what auto-detect would choose (for display in the dropdown)
$actualAdapter = NetworkAdapter::getInstance()->detectActiveInterface();

// Get network interfaces from system
$interfaces = [];
exec("ip link show | grep -E '^[0-9]+:' | awk -F': ' '{print $2}' | grep -v '^lo'", $interfaces);
$interfaces = array_map('trim', $interfaces);
$interfaces = array_filter($interfaces);

// Ensure current adapter is in the list (if not using 'default')
if ($config['networkAdapter'] !== 'default' && !in_array($config['networkAdapter'], $interfaces)) {
    $interfaces[] = $config['networkAdapter'];
}

$gatewaySuggestion = NetworkAdapter::getInstance()->detectGateway($actualAdapter);
$gatewayAlreadyConfigured = $gatewaySuggestion && in_array($gatewaySuggestion, $config['testHosts'], true);
$gatewayInputValue = ($gatewaySuggestion && !$gatewayAlreadyConfigured) ? $gatewaySuggestion : '';

// Detect if this FPP instance is in player mode (for multisync metrics feature)
$isPlayerMode = SyncStatus::getInstance()->isPlayerMode();

// Detect eFuse hardware
$efuseHardware = EfuseHardware::getInstance()->detectHardware();

// Check for connectivity reset state
$resetState = NetworkAdapter::getInstance()->getResetState();

// Get plugin version from pluginInfo.json
$pluginVersion = '';
$pluginInfoPath = __DIR__ . '/../pluginInfo.json';
if (file_exists($pluginInfoPath)) {
    $pluginInfo = json_decode(file_get_contents($pluginInfoPath), true);
    $pluginVersion = $pluginInfo['version'] ?? '';
}

// Count remote systems for display
$remoteSystemCount = 0;
if ($isPlayerMode) {
    $multiSyncData = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/multiSyncSystems', 5);
    if ($multiSyncData && isset($multiSyncData['systems']) && is_array($multiSyncData['systems'])) {
        foreach ($multiSyncData['systems'] as $system) {
            if (empty($system['local']) && isset($system['fppModeString']) && $system['fppModeString'] === 'remote') {
                $remoteSystemCount++;
            }
        }
    }
}
?>
<script>
window.watcherConfig = {
    isPlayerMode: <?php echo $isPlayerMode ? 'true' : 'false'; ?>
};
</script>

    <div class="watcherSettingsContainer" data-watcher-page="configUI">
        <?php if ($pluginVersion): ?>
        <div class="watcherVersionBadge">Watcher v<?php echo htmlspecialchars($pluginVersion); ?></div>
        <?php endif; ?>

        <?php if ($statusMessage): ?>
        <div class="statusMessage <?php echo htmlspecialchars($statusType); ?>">
            <?php echo htmlspecialchars($statusMessage); ?>
        </div>
        <?php endif; ?>

        <?php if ($resetState && !empty($resetState['hasResetAdapter'])): ?>
        <div class="settingsPanel warningPanel">
            <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                <div class="panelTitle">
                    <i class="fas fa-exclamation-triangle"></i>
                    Network Adapter Reset Occurred
                </div>
                <i class="fas fa-chevron-down panelToggle"></i>
            </div>
            <div class="panelBody">
                <p style="margin-bottom: 0.5rem;">
                    The connectivity daemon reset <strong><?php echo htmlspecialchars($resetState['adapter'] ?? 'unknown'); ?></strong>
                    on <strong><?php echo htmlspecialchars(date('Y-m-d H:i:s', $resetState['resetTimestamp'] ?? 0)); ?></strong>
                    and stopped monitoring to prevent a reset loop.
                </p>
                <p style="margin-bottom: 1rem;">
                    If your network is stable, clear this state to restart monitoring.
                </p>
                <button type="button" class="buttons btn-warning" id="clearResetStateBtn" onclick="page.clearResetState()">
                    <i class="fas fa-redo"></i> Clear State &amp; Restart Daemon
                </button>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" action="" id="watcherSettingsForm">

            <!-- Connectivity Check Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <label class="toggleSwitch toggleSwitch--sm" onclick="event.stopPropagation()">
                            <input type="checkbox" id="connectivityCheckEnabled" name="connectivityCheckEnabled" value="1"
                                <?php echo (!empty($config['connectivityCheckEnabled'])) ? 'checked' : ''; ?>>
                            <span class="toggleSlider toggleSlider--green"></span>
                        </label>
                        <i class="fas fa-wifi"></i>
                        Connectivity Check
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="panelDesc" style="margin-bottom: 1rem;">
                        Periodically pings test hosts to verify network connectivity. After consecutive failures reach the max threshold,
                        the network adapter is automatically reset to restore connectivity. A connectivity reset will only happen once to avoid a reset loop.  You must clear the reset state.
                        Metrics are logged to the Connectivity dashboard. Recommendation is to configure pinging your local gateway.
                    </div>
                    <div class="formRow">
                        <div class="formGroup">
                            <label class="formLabel">Check Interval</label>
                            <div class="inputWithSuffix">
                                <input type="number" id="checkInterval" name="checkInterval" class="form-control"
                                    min="5" max="3600" value="<?php echo htmlspecialchars($config['checkInterval']); ?>">
                                <span class="inputSuffix">seconds</span>
                            </div>
                        </div>
                        <div class="formGroup">
                            <label class="formLabel">Max Failures</label>
                            <input type="number" id="maxFailures" name="maxFailures" class="form-control"
                                min="1" max="100" value="<?php echo htmlspecialchars($config['maxFailures']); ?>" style="width: 80px;">
                        </div>
                        <div class="formGroup">
                            <label class="formLabel">Network Adapter</label>
                            <select id="networkAdapter" name="networkAdapter" class="form-control">
                                <option value="default" <?php echo ($config['networkAdapter'] === 'default') ? 'selected' : ''; ?>>
                                    Auto-detect (<?php echo htmlspecialchars($actualAdapter); ?>)
                                </option>
                                <?php foreach ($interfaces as $iface): ?>
                                <option value="<?php echo htmlspecialchars($iface); ?>"
                                    <?php echo ($config['networkAdapter'] === $iface) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($iface); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="formRow">
                        <div class="formGroup fullWidth">
                            <label class="formLabel">Test Hosts</label>
                            <div class="tagsContainer" id="testHostsContainer">
                                <?php foreach ($config['testHosts'] as $host): ?>
                                <span class="tag">
                                    <?php echo htmlspecialchars($host); ?>
                                    <i class="fas fa-times tagRemove" onclick="page.watcherRemoveTag(this)"></i>
                                    <input type="hidden" name="testHosts[]" value="<?php echo htmlspecialchars($host); ?>">
                                </span>
                                <?php endforeach; ?>
                                <input type="text" class="tagInput" id="newHostInput"
                                    placeholder="Add host (e.g., 8.8.8.8)"
                                    value="<?php echo htmlspecialchars($gatewayInputValue); ?>"
                                    onkeypress="page.watcherHandleTagKeypress(event)">
                            </div>
                            <?php if ($gatewaySuggestion && !$gatewayAlreadyConfigured): ?>
                            <div class="gatewaySuggestion">
                                <i class="fas fa-lightbulb"></i> Detected gateway: <?php echo htmlspecialchars($gatewaySuggestion); ?> - press Enter to add
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Metrics Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <label class="toggleSwitch toggleSwitch--sm" onclick="event.stopPropagation()">
                            <input type="checkbox" id="collectdEnabled" name="collectdEnabled" value="1"
                                <?php echo (!empty($config['collectdEnabled'])) ? 'checked' : ''; ?>>
                            <span class="toggleSlider toggleSlider--green"></span>
                        </label>
                        <i class="fas fa-chart-line"></i>
                        System Metrics Collection
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="panelDesc">
                        Collectd gathers CPU, memory, disk, network, and temperature metrics. Uses ~25MB storage for historical data.
                        Runs at lower priority to avoid impacting FPP performance. View in "Watcher - Local Metrics" dashboard.
                    </div>
                </div>
            </div>

            <?php if ($isPlayerMode): ?>
            <!-- Player Mode Options Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <i class="fas fa-network-wired"></i>
                        Player Mode Options
                        <span class="badge"><i class="fas fa-server"></i> <?php echo $remoteSystemCount; ?> remote<?php echo $remoteSystemCount !== 1 ? 's' : ''; ?></span>
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="featureGrid">
                        <!-- Remote Metrics -->
                        <div class="featureCard">
                            <div class="featureHeader">
                                <div class="featureTitle">
                                    <i class="fas fa-chart-area"></i>
                                    Remote Metrics
                                </div>
                                <label class="toggleSwitch toggleSwitch--sm">
                                    <input type="checkbox" id="multiSyncMetricsEnabled" name="multiSyncMetricsEnabled" value="1"
                                        <?php echo (!empty($config['multiSyncMetricsEnabled'])) ? 'checked' : ''; ?>>
                                    <span class="toggleSlider toggleSlider--green"></span>
                                </label>
                            </div>
                            <div class="featureDesc">
                                Aggregate system metrics from all remote FPP systems. Remotes need Watcher + collectd enabled. Enables Dashboard.
                            </div>
                        </div>

                        <!-- Remote Ping -->
                        <div class="featureCard">
                            <div class="featureHeader">
                                <div class="featureTitle">
                                    <i class="fas fa-satellite-dish"></i>
                                    Remote Ping
                                </div>
                                <label class="toggleSwitch toggleSwitch--sm">
                                    <input type="checkbox" id="multiSyncPingEnabled" name="multiSyncPingEnabled" value="1"
                                        <?php echo (!empty($config['multiSyncPingEnabled'])) ? 'checked' : ''; ?>>
                                    <span class="toggleSlider toggleSlider--green"></span>
                                </label>
                            </div>
                            <div class="featureDesc">
                                Monitor latency and availability to all remote multi-sync systems. Enables Dashboard.
                            </div>
                            <div class="featureOptions">
                                <div class="featureOption">
                                    <span>Interval:</span>
                                    <input type="number" id="multiSyncPingInterval" name="multiSyncPingInterval"
                                        min="10" max="300" value="<?php echo htmlspecialchars($config['multiSyncPingInterval'] ?? WATCHERDEFAULTSETTINGS['multiSyncPingInterval']); ?>">
                                    <span>sec</span>
                                </div>
                            </div>
                        </div>

                        <!-- Remote Control -->
                        <div class="featureCard">
                            <div class="featureHeader">
                                <div class="featureTitle">
                                    <i class="fas fa-sliders-h"></i>
                                    Remote Control
                                </div>
                                <label class="toggleSwitch toggleSwitch--sm">
                                    <input type="checkbox" id="controlUIEnabled" name="controlUIEnabled" value="1"
                                        <?php echo (!empty($config['controlUIEnabled'])) ? 'checked' : ''; ?>>
                                    <span class="toggleSlider toggleSlider--green"></span>
                                </label>
                            </div>
                            <div class="featureDesc">
                                Control remote FPP systems: test mode, restart FPPD, reboot devices. Enables Consolidated View.
                            </div>
                            <div class="featureOptions">
                                <div class="featureOptionTitle">Issue Checks</div>
                                <div class="featureOption">
                                    <input type="checkbox" name="issueCheckOutputs" value="1"
                                        <?php echo (!empty($config['issueCheckOutputs'])) ? 'checked' : ''; ?>>
                                    <span><strong>Output to Remote</strong> - Warn when outputs target systems in remote mode</span>
                                </div>
                                <div class="featureOption">
                                    <input type="checkbox" name="issueCheckSequences" value="1"
                                        <?php echo (!empty($config['issueCheckSequences'])) ? 'checked' : ''; ?>>
                                    <span><strong>Missing Sequences</strong> - Warn when remotes are missing sequences from the player</span>
                                </div>
                                <div class="featureOption">
                                    <input type="checkbox" name="issueCheckOutputHostsNotInSync" value="1"
                                        <?php echo (!empty($config['issueCheckOutputHostsNotInSync'])) ? 'checked' : ''; ?>>
                                    <span><strong>Output Host Not Found</strong> - Warn when outputs target hosts not discovered via MultiSync</span>
                                </div>
                            </div>
                        </div>

                        <!-- MQTT Monitor -->
                        <div class="featureCard">
                            <div class="featureHeader">
                                <div class="featureTitle">
                                    <i class="fas fa-broadcast-tower"></i>
                                    MQTT Events
                                </div>
                                <label class="toggleSwitch toggleSwitch--sm">
                                    <input type="checkbox" id="mqttMonitorEnabled" name="mqttMonitorEnabled" value="1"
                                        <?php echo (!empty($config['mqttMonitorEnabled'])) ? 'checked' : ''; ?>>
                                    <span class="toggleSlider toggleSlider--green"></span>
                                </label>
                            </div>
                            <div class="featureDesc">
                                Capture sequence, playlist, and status events. Auto-configures FPP's MQTT broker. Enables Dashboard.
                            </div>
                            <div class="featureOptions">
                                <div class="featureOption">
                                    <span>Retention:</span>
                                    <input type="number" id="mqttRetentionDays" name="mqttRetentionDays"
                                        min="1" max="365" value="<?php echo htmlspecialchars($config['mqttRetentionDays'] ?? WATCHERDEFAULTSETTINGS['mqttRetentionDays']); ?>">
                                    <span>days</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Falcon Controller Monitor Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <label class="toggleSwitch toggleSwitch--sm" onclick="event.stopPropagation()">
                            <input type="checkbox" id="falconMonitorEnabled" name="falconMonitorEnabled" value="1"
                                <?php echo (!empty($config['falconMonitorEnabled'])) ? 'checked' : ''; ?>>
                            <span class="toggleSlider toggleSlider--green"></span>
                        </label>
                        <i class="fas fa-microchip"></i>
                        Falcon Controller Monitor
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="panelDesc">
                        Monitor Falcon controllers (F4V2, F16V2, F4V3, F16V3, F48) - temps, voltages, pixel counts, firmware. Toggle test mode or reboot controllers.
                        Configure controller IPs in the Falcon Monitor dashboard once enabled.
                    </div>
                </div>
            </div>

            <?php if ($efuseHardware['supported']): ?>
            <!-- eFuse Current Monitor Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <label class="toggleSwitch toggleSwitch--sm" onclick="event.stopPropagation()">
                            <input type="checkbox" id="efuseMonitorEnabled" name="efuseMonitorEnabled" value="1"
                                <?php echo (!empty($config['efuseMonitorEnabled'])) ? 'checked' : ''; ?>
                                onchange="page.toggleEfuseOptions()">
                            <span class="toggleSlider toggleSlider--green"></span>
                        </label>
                        <i class="fas fa-bolt"></i>
                        eFuse Current Monitor
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="panelDesc" style="margin-bottom: 1rem;">
                        Track per-port current draw with heatmap visualization. Detected hardware: <?php echo htmlspecialchars(EfuseHardware::getInstance()->getHardwareSummary()['typeLabel'] ?? 'Unknown'); ?>
                        (<?php echo $efuseHardware['ports']; ?> ports). View trends in the eFuse Monitor dashboard.
                    </div>
                    <div id="efuseOptionsContainer" style="<?php echo empty($config['efuseMonitorEnabled']) ? 'display:none;' : ''; ?>">
                        <div class="formRow">
                            <div class="formGroup">
                                <label class="formLabel">Collection Interval</label>
                                <select id="efuseCollectionInterval" name="efuseCollectionInterval" class="form-control" onchange="page.updateEfuseStorageEstimate()">
                                    <option value="1" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 1 ? 'selected' : ''; ?>>1 second</option>
                                    <option value="2" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 2 ? 'selected' : ''; ?>>2 seconds</option>
                                    <option value="5" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 5 ? 'selected' : ''; ?>>5 seconds</option>
                                    <option value="10" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 10 ? 'selected' : ''; ?>>10 seconds</option>
                                    <option value="30" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 30 ? 'selected' : ''; ?>>30 seconds</option>
                                    <option value="60" <?php echo ($config['efuseCollectionInterval'] ?? 5) == 60 ? 'selected' : ''; ?>>60 seconds</option>
                                </select>
                            </div>
                            <div class="formGroup">
                                <label class="formLabel">Data Retention</label>
                                <select id="efuseRetentionDays" name="efuseRetentionDays" class="form-control" onchange="page.updateEfuseStorageEstimate()">
                                    <option value="1" <?php echo ($config['efuseRetentionDays'] ?? 14) == 1 ? 'selected' : ''; ?>>1 day</option>
                                    <option value="3" <?php echo ($config['efuseRetentionDays'] ?? 14) == 3 ? 'selected' : ''; ?>>3 days</option>
                                    <option value="7" <?php echo ($config['efuseRetentionDays'] ?? 14) == 7 ? 'selected' : ''; ?>>7 days</option>
                                    <option value="14" <?php echo ($config['efuseRetentionDays'] ?? 14) == 14 ? 'selected' : ''; ?>>14 days</option>
                                    <option value="30" <?php echo ($config['efuseRetentionDays'] ?? 14) == 30 ? 'selected' : ''; ?>>30 days</option>
                                    <option value="90" <?php echo ($config['efuseRetentionDays'] ?? 14) == 90 ? 'selected' : ''; ?>>90 days</option>
                                </select>
                            </div>
                        </div>
                        <div class="efuseStorageEstimate" id="efuseStorageEstimate">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Form Actions -->
            <div class="formActions">
                <button type="button" class="buttons btn-outline-secondary" onclick="window.location.reload();">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" name="save_settings" class="buttons btn-success">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </form>

        <!-- Data Management Panel (outside form) -->
        <div class="settingsPanel collapsed">
            <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                <div class="panelTitle">
                    <i class="fas fa-database"></i>
                    Data Management
                </div>
                <i class="fas fa-chevron-down panelToggle"></i>
            </div>
            <div class="panelBody">
                <div class="dataWarning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Clearing data will remove historical metrics. Data will rebuild over time as new metrics are collected.</span>
                </div>
                <div class="dataStatsContainer" id="dataStatsContainer">
                    <div class="dataStatsLoading">
                        <i class="fas fa-spinner fa-spin"></i> Loading data statistics...
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Configuration Panel -->
        <div class="settingsPanel collapsed">
            <div class="panelHeader" onclick="page.watcherTogglePanel(this)">
                <div class="panelTitle">
                    <i class="fas fa-cogs"></i>
                    Advanced Configuration
                </div>
                <i class="fas fa-chevron-down panelToggle"></i>
            </div>
            <div class="panelBody">
                <div class="panelDesc" style="margin-bottom: 1rem;">
                    These options are for power users who need to customize the underlying configuration files.
                </div>
                <div class="advancedConfigItem" style="margin-bottom: 0.75rem;">
                    <div class="advancedConfigInfo">
                        <div class="advancedConfigTitle">
                            <i class="fas fa-sliders-h"></i>
                            Watcher Configuration
                        </div>
                        <div class="advancedConfigDesc">
                            Edit the watcher plugin configuration file directly. Use with caution.
                        </div>
                    </div>
                    <button type="button" class="buttons btn-outline-secondary" onclick="page.openWatcherEditor()">
                        <i class="fas fa-edit"></i> Edit Config
                    </button>
                </div>
                <div class="advancedConfigItem">
                    <div class="advancedConfigInfo">
                        <div class="advancedConfigTitle">
                            <i class="fas fa-file-code"></i>
                            Collectd Configuration
                        </div>
                        <div class="advancedConfigDesc">
                            View the collectd.conf file used for system metrics collection.
                        </div>
                    </div>
                    <button type="button" class="buttons btn-outline-secondary" onclick="page.viewCollectdConfig()">
                        <i class="fas fa-eye"></i> View Config
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Terminal Modal for viewing files -->
    <div id="terminalModal" class="terminalModal" onclick="if(event.target === this) page.closeTerminalModal()">
        <div class="terminalModalContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-terminal"></i>
                    <span id="terminalModalTitle">File Viewer</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm" onclick="page.refreshTerminalContent()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" class="buttons btn-sm" onclick="page.closeTerminalModal()" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <pre id="terminalContent" class="terminalContent">Loading...</pre>
            <div class="terminalModalFooter">
                <span class="terminalFooterText">Last 100 lines &bull; Press ESC to close</span>
            </div>
        </div>
    </div>

    <!-- Collectd Config Viewer Modal (read-only) -->
    <div id="collectdViewerModal" class="terminalModal" onclick="if(event.target === this) page.closeCollectdViewer()">
        <div class="terminalModalContent configEditorContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-file-code"></i>
                    <span>collectd.conf</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm" onclick="page.closeCollectdViewer()" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <pre id="collectdViewerContent" class="terminalContent">Loading...</pre>
            <div class="terminalModalFooter">
                <span class="terminalFooterText">Read-only view &bull; Press ESC to close</span>
            </div>
        </div>
    </div>

    <!-- Watcher Config Editor Modal -->
    <div id="watcherEditorModal" class="terminalModal" onclick="if(event.target === this) page.closeWatcherEditor()">
        <div class="terminalModalContent configEditorContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-sliders-h"></i>
                    <span>plugin.fpp-plugin-watcher</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm btn-success" id="saveWatcherBtn" onclick="page.saveWatcherConfig()" title="Save">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="buttons btn-sm" onclick="page.closeWatcherEditor()" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="configEditorWarning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Warning:</strong> Editing this file incorrectly may break the plugin. Only modify values you understand.
                    Some changes may require an FPP restart to take effect.
                </div>
            </div>
            <textarea id="watcherEditorContent" class="configEditorTextarea" spellcheck="false">Loading...</textarea>
            <div class="terminalModalFooter">
                <span class="terminalFooterText">Press ESC to close without saving</span>
                <span class="configEditorStatus" id="watcherEditorStatus"></span>
            </div>
        </div>
    </div>
