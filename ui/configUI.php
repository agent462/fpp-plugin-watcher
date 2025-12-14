    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/configUI.css&nopage=1">
    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/commonUI.js&nopage=1"></script>

<?php
// Include FPP common functions and configuration
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/../lib/core/watcherCommon.php";
include_once __DIR__ . "/../lib/core/config.php";
include_once __DIR__ . "/../lib/controllers/efuseHardware.php";

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
    $efuseMonitorEnabled = isset($_POST['efuseMonitorEnabled']) ? 'true' : 'false';
    $efuseCollectionInterval = intval($_POST['efuseCollectionInterval'] ?? 5);
    $efuseRetentionDays = intval($_POST['efuseRetentionDays'] ?? 7);

    // If 'default' is selected, auto-detect and save the actual interface
    if ($networkAdapter === 'default') {
        $networkAdapter = detectActiveNetworkInterface();
        logMessage("Auto-detected network adapter '$networkAdapter' from 'default' setting");
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
        // Save settings using FPP's WriteSettingToFile
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
            'efuseMonitorEnabled' => $efuseMonitorEnabled,
            'efuseCollectionInterval' => $efuseCollectionInterval,
            'efuseRetentionDays' => $efuseRetentionDays
        ];

        foreach ($settingsToSave as $settingName => $settingValue) {
            /** @disregard P1010 */
            WriteSettingToFile($settingName, $settingValue, WATCHERPLUGINNAME);
        }

        // Configure FPP MQTT settings based on enable/disable state
        $mqttConfigResult = configureFppMqttSettings($mqttMonitorEnabled === 'true');
        if (!$mqttConfigResult['success']) {
            logMessage("Warning: Some FPP MQTT settings may not have been configured: " . implode(', ', $mqttConfigResult['messages']));
        }

        // Only set FPP restart flag if settings that require restart have changed
        // (collectd or MQTT changes still need restart; connectivity settings are hot-reloaded)
        if (settingsRequireRestart($oldConfig, $settingsToSave)) {
            /** @disregard P1010 */
            WriteSettingToFile('restartFlag', 1);
            $statusMessage = 'Settings saved! FPP restart required for collectd/MQTT changes.';
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
$actualAdapter = detectActiveNetworkInterface();

// Get network interfaces from system
$interfaces = [];
exec("ip link show | grep -E '^[0-9]+:' | awk -F': ' '{print $2}' | grep -v '^lo'", $interfaces);
$interfaces = array_map('trim', $interfaces);
$interfaces = array_filter($interfaces);

// Ensure current adapter is in the list (if not using 'default')
if ($config['networkAdapter'] !== 'default' && !in_array($config['networkAdapter'], $interfaces)) {
    $interfaces[] = $config['networkAdapter'];
}

$gatewaySuggestion = detectGatewayForInterface($actualAdapter);
$gatewayAlreadyConfigured = $gatewaySuggestion && in_array($gatewaySuggestion, $config['testHosts'], true);
$gatewayInputValue = ($gatewaySuggestion && !$gatewayAlreadyConfigured) ? $gatewaySuggestion : '';

// Detect if this FPP instance is in player mode (for multisync metrics feature)
$isPlayerMode = isPlayerMode();

// Detect eFuse hardware
$efuseHardware = detectEfuseHardware();

// Check for connectivity reset state
$resetState = readResetState();

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
    include_once __DIR__ . '/../lib/core/apiCall.php';
    $multiSyncData = apiCall('GET', 'http://127.0.0.1/api/fppd/multiSyncSystems', [], true, 5);
    if ($multiSyncData && isset($multiSyncData['systems']) && is_array($multiSyncData['systems'])) {
        foreach ($multiSyncData['systems'] as $system) {
            if (empty($system['local']) && isset($system['fppModeString']) && $system['fppModeString'] === 'remote') {
                $remoteSystemCount++;
            }
        }
    }
}
?>

    <div class="watcherSettingsContainer">
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
            <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                <button type="button" class="buttons btn-warning" id="clearResetStateBtn" onclick="clearResetState()">
                    <i class="fas fa-redo"></i> Clear State &amp; Restart Daemon
                </button>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" action="" id="watcherSettingsForm">

            <!-- Connectivity Check Panel -->
            <div class="settingsPanel">
                <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                                    <i class="fas fa-times tagRemove" onclick="watcherRemoveTag(this)"></i>
                                    <input type="hidden" name="testHosts[]" value="<?php echo htmlspecialchars($host); ?>">
                                </span>
                                <?php endforeach; ?>
                                <input type="text" class="tagInput" id="newHostInput"
                                    placeholder="Add host (e.g., 8.8.8.8)"
                                    value="<?php echo htmlspecialchars($gatewayInputValue); ?>"
                                    onkeypress="watcherHandleTagKeypress(event)">
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
                <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                <div class="panelHeader" onclick="watcherTogglePanel(this)">
                    <div class="panelTitle">
                        <label class="toggleSwitch toggleSwitch--sm" onclick="event.stopPropagation()">
                            <input type="checkbox" id="efuseMonitorEnabled" name="efuseMonitorEnabled" value="1"
                                <?php echo (!empty($config['efuseMonitorEnabled'])) ? 'checked' : ''; ?>
                                onchange="toggleEfuseOptions()">
                            <span class="toggleSlider toggleSlider--green"></span>
                        </label>
                        <i class="fas fa-bolt"></i>
                        eFuse Current Monitor
                    </div>
                    <i class="fas fa-chevron-down panelToggle"></i>
                </div>
                <div class="panelBody">
                    <div class="panelDesc" style="margin-bottom: 1rem;">
                        Track per-port current draw with heatmap visualization. Detected hardware: <?php echo htmlspecialchars(getEfuseHardwareSummary()['typeLabel'] ?? 'Unknown'); ?>
                        (<?php echo $efuseHardware['ports']; ?> ports). View trends in the eFuse Monitor dashboard.
                    </div>
                    <div id="efuseOptionsContainer" style="<?php echo empty($config['efuseMonitorEnabled']) ? 'display:none;' : ''; ?>">
                        <div class="formRow">
                            <div class="formGroup">
                                <label class="formLabel">Collection Interval</label>
                                <select id="efuseCollectionInterval" name="efuseCollectionInterval" class="form-control" onchange="updateEfuseStorageEstimate()">
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
                                <select id="efuseRetentionDays" name="efuseRetentionDays" class="form-control" onchange="updateEfuseStorageEstimate()">
                                    <option value="1" <?php echo ($config['efuseRetentionDays'] ?? 7) == 1 ? 'selected' : ''; ?>>1 day</option>
                                    <option value="3" <?php echo ($config['efuseRetentionDays'] ?? 7) == 3 ? 'selected' : ''; ?>>3 days</option>
                                    <option value="7" <?php echo ($config['efuseRetentionDays'] ?? 7) == 7 ? 'selected' : ''; ?>>7 days</option>
                                    <option value="14" <?php echo ($config['efuseRetentionDays'] ?? 7) == 14 ? 'selected' : ''; ?>>14 days</option>
                                    <option value="30" <?php echo ($config['efuseRetentionDays'] ?? 7) == 30 ? 'selected' : ''; ?>>30 days</option>
                                    <option value="90" <?php echo ($config['efuseRetentionDays'] ?? 7) == 90 ? 'selected' : ''; ?>>90 days</option>
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
            <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
            <div class="panelHeader" onclick="watcherTogglePanel(this)">
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
                    <button type="button" class="buttons btn-outline-secondary" onclick="openWatcherEditor()">
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
                    <button type="button" class="buttons btn-outline-secondary" onclick="viewCollectdConfig()">
                        <i class="fas fa-eye"></i> View Config
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Player mode flag for filtering data categories
        const isPlayerMode = <?php echo $isPlayerMode ? 'true' : 'false'; ?>;

        // eFuse storage calculator functions
        function calculateEfuseStorage(interval, days, ports) {
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

        function updateEfuseStorageEstimate() {
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

        function toggleEfuseOptions() {
            const checkbox = document.getElementById('efuseMonitorEnabled');
            const container = document.getElementById('efuseOptionsContainer');
            if (checkbox && container) {
                container.style.display = checkbox.checked ? '' : 'none';
                if (checkbox.checked) {
                    updateEfuseStorageEstimate();
                }
            }
        }

        // Initialize eFuse storage estimate on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateEfuseStorageEstimate();
        });

        // Toggle panel collapse
        function watcherTogglePanel(header) {
            const panel = header.closest('.settingsPanel');
            panel.classList.toggle('collapsed');
        }

        // Handle tag input keypress
        function watcherHandleTagKeypress(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                watcherAddTag();
            }
        }

        // Add a test host tag
        function watcherAddTag() {
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
                <i class="fas fa-times tagRemove" onclick="watcherRemoveTag(this)"></i>
                <input type="hidden" name="testHosts[]" value="${escapeHtml(host)}">
            `;

            // Insert before the input
            container.insertBefore(tag, input);
            input.value = '';
        }

        // Remove a test host tag
        function watcherRemoveTag(element) {
            element.closest('.tag').remove();
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('watcherSettingsForm').addEventListener('submit', function(e) {
                const testHosts = document.querySelectorAll('input[name="testHosts[]"]');
                if (testHosts.length === 0) {
                    e.preventDefault();
                    alert('Please add at least one test host');
                    return false;
                }
            });
        });

        // Clear reset state and restart daemon
        async function clearResetState() {
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

        // Format bytes to human readable
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        // Load data statistics with accordion UI
        async function loadDataStats() {
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
                    if (category.playerOnly && !isPlayerMode) {
                        continue;
                    }
                    totalSize += category.totalSize;
                    totalFiles += category.fileCount;

                    const hasFiles = category.fileCount > 0;
                    const showFiles = category.showFiles !== false;
                    const canExpand = hasFiles && showFiles;
                    const expandable = canExpand ? 'expandable' : '';

                    // Auto-expand items with warnings so the warning is visible
                    const autoExpanded = category.warning ? 'expanded' : '';

                    html += `<div class="dataAccordionItem ${autoExpanded}" data-category="${escapeHtml(key)}">
                        <div class="dataAccordionHeader ${expandable}" onclick="${canExpand ? 'toggleDataAccordion(this)' : ''}">
                            <div class="dataAccordionTitle">
                                ${canExpand ? '<i class="fas fa-chevron-right dataAccordionChevron"></i>' : '<i class="fas fa-database dataAccordionIcon"></i>'}
                                <strong>${escapeHtml(category.name)}</strong>
                                <span class="dataAccordionBadge">${category.fileCount} file${category.fileCount !== 1 ? 's' : ''}</span>
                                <span class="dataAccordionSize">${formatBytes(category.totalSize)}</span>
                            </div>
                            <div class="dataAccordionActions">
                                <button type="button" class="buttons btn-sm btn-outline-danger"
                                    onclick="event.stopPropagation(); clearDataCategory('${escapeHtml(key)}', '${escapeHtml(category.name)}')"
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
                                            onclick="viewDataFile('${escapeHtml(key)}', '${escapeHtml(file.name)}')"
                                            title="View file contents">
                                            <i class="fas fa-eye"></i>
                                        </button>` : ''}
                                        <button type="button" class="buttons btn-xs btn-outline-danger"
                                            onclick="clearDataFile('${escapeHtml(key)}', '${escapeHtml(file.name)}')"
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

        // Toggle accordion expand/collapse
        function toggleDataAccordion(header) {
            const item = header.closest('.dataAccordionItem');
            item.classList.toggle('expanded');
        }

        // Clear data for a category
        async function clearDataCategory(category, categoryName) {
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

        // Clear a single file
        async function clearDataFile(category, filename) {
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

        // View file contents in terminal modal
        async function viewDataFile(category, filename) {
            const modal = document.getElementById('terminalModal');
            const title = document.getElementById('terminalModalTitle');
            const content = document.getElementById('terminalContent');
            const refreshBtn = document.getElementById('terminalRefreshBtn');

            // Store current file info for refresh
            modal.dataset.category = category;
            modal.dataset.filename = filename;

            title.textContent = filename;
            content.textContent = 'Loading...';
            modal.style.display = 'flex';

            await refreshTerminalContent();
        }

        // Refresh terminal content
        async function refreshTerminalContent() {
            const modal = document.getElementById('terminalModal');
            const content = document.getElementById('terminalContent');
            const category = modal.dataset.category;
            const filename = modal.dataset.filename;

            try {
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/data/${encodeURIComponent(category)}/${encodeURIComponent(filename)}/tail?lines=100`);
                const result = await response.json();

                if (result.success) {
                    content.textContent = result.content || '(empty file)';
                    // Scroll to bottom
                    content.scrollTop = content.scrollHeight;
                } else {
                    content.textContent = 'Error: ' + (result.error || 'Failed to load file');
                }
            } catch (error) {
                content.textContent = 'Error: ' + error.message;
            }
        }

        // Close terminal modal
        function closeTerminalModal() {
            document.getElementById('terminalModal').style.display = 'none';
        }

        // Load data stats when Data Management panel is expanded
        document.addEventListener('DOMContentLoaded', function() {
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
        });

        // Collectd Config Viewer (read-only)
        async function viewCollectdConfig() {
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

        function closeCollectdViewer() {
            document.getElementById('collectdViewerModal').style.display = 'none';
        }

        // Watcher Config Editor Functions
        let watcherEditorOriginalContent = '';

        async function openWatcherEditor() {
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
                    watcherEditorOriginalContent = result.content;
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

        async function saveWatcherConfig() {
            const textarea = document.getElementById('watcherEditorContent');
            const saveBtn = document.getElementById('saveWatcherBtn');
            const status = document.getElementById('watcherEditorStatus');
            const content = textarea.value;

            if (content === watcherEditorOriginalContent) {
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
                    watcherEditorOriginalContent = content;
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

        function closeWatcherEditor() {
            const modal = document.getElementById('watcherEditorModal');
            const textarea = document.getElementById('watcherEditorContent');

            if (textarea.value !== watcherEditorOriginalContent) {
                if (!confirm('You have unsaved changes. Are you sure you want to close?')) {
                    return;
                }
            }

            modal.style.display = 'none';
        }

        // Escape key handler for all modals
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
    </script>

    <!-- Terminal Modal for viewing files -->
    <div id="terminalModal" class="terminalModal" onclick="if(event.target === this) closeTerminalModal()">
        <div class="terminalModalContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-terminal"></i>
                    <span id="terminalModalTitle">File Viewer</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm" onclick="refreshTerminalContent()" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" class="buttons btn-sm" onclick="closeTerminalModal()" title="Close">
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
    <div id="collectdViewerModal" class="terminalModal" onclick="if(event.target === this) closeCollectdViewer()">
        <div class="terminalModalContent configEditorContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-file-code"></i>
                    <span>collectd.conf</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm" onclick="closeCollectdViewer()" title="Close">
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
    <div id="watcherEditorModal" class="terminalModal" onclick="if(event.target === this) closeWatcherEditor()">
        <div class="terminalModalContent configEditorContent">
            <div class="terminalModalHeader">
                <div class="terminalModalTitle">
                    <i class="fas fa-sliders-h"></i>
                    <span>plugin.fpp-plugin-watcher</span>
                </div>
                <div class="terminalModalActions">
                    <button type="button" class="buttons btn-sm btn-success" id="saveWatcherBtn" onclick="saveWatcherConfig()" title="Save">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="buttons btn-sm" onclick="closeWatcherEditor()" title="Close">
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
