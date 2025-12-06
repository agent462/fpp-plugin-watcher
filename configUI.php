    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/configUI.css&nopage=1">
    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/commonUI.js&nopage=1"></script>

<?php
// Include FPP common functions and configuration
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/lib/core/watcherCommon.php";
include_once __DIR__ . "/lib/core/config.php";

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
            'issueCheckSequences' => $issueCheckSequences
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
        if (settingsRequireRestart($oldConfig, $settingsToSave)) {
            /** @disregard P1010 */
            WriteSettingToFile('restartFlag', 1);
            $statusMessage = 'Settings saved successfully! FPP restart required.';
        } else {
            $statusMessage = 'Settings saved successfully!';
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

// Check for connectivity reset state
$resetState = readResetState();

// Count remote systems for display
$remoteSystemCount = 0;
if ($isPlayerMode) {
    include_once __DIR__ . '/lib/core/apiCall.php';
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
                        Collectd gathers CPU, memory, disk, network, and temperature metrics. Uses ~60MB storage for historical data.
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
    </div>

    <script>
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
    </script>
