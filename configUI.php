    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/configUI.css&nopage=1">
    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/commonUI.js&nopage=1"></script>

<?php
// Include FPP common functions and configuration
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/lib/watcherCommon.php";
include_once __DIR__ . "/lib/config.php";

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
        // Note: collectd service will be enabled/disabled on FPP restart via postStart.sh
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
// This shows users what would be detected even if they have a specific interface selected
$actualAdapter = detectActiveNetworkInterface();

// Get network interfaces from system
$interfaces = [];
exec("ip link show | grep -E '^[0-9]+:' | awk -F': ' '{print $2}' | grep -v '^lo'", $interfaces);
$interfaces = array_map('trim', $interfaces);
$interfaces = array_filter($interfaces); // Remove empty values

// Ensure current adapter is in the list (if not using 'default')
if ($config['networkAdapter'] !== 'default' && !in_array($config['networkAdapter'], $interfaces)) {
    $interfaces[] = $config['networkAdapter'];
}

$gatewaySuggestion = detectGatewayForInterface($actualAdapter);
$gatewayAlreadyConfigured = $gatewaySuggestion && in_array($gatewaySuggestion, $config['testHosts'], true);
$hostPlaceholder = 'Enter hostname or IP address (e.g., 8.8.8.8';
if ($gatewaySuggestion) {
    $hostPlaceholder .= ' or ' . $gatewaySuggestion;
}
$hostPlaceholder .= ')';
$gatewayInputValue = ($gatewaySuggestion && !$gatewayAlreadyConfigured) ? $gatewaySuggestion : '';

// Detect if this FPP instance is in player mode (for multisync metrics feature)
$isPlayerMode = isPlayerMode();

// Check for connectivity reset state
$resetState = readResetState();

// Count remote systems for display
$remoteSystemCount = 0;
if ($isPlayerMode) {
    include_once __DIR__ . '/lib/apiCall.php';
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
        <div class="settingsPanel" style="background: #fff3cd; border: 1px solid #ffc107; margin-bottom: 1.5rem;">
            <div class="panelTitle" style="color: #856404;">
                <i class="fas fa-exclamation-triangle"></i> Network Adapter Reset Occurred
            </div>
            <div class="row settingRow">
                <div class="col-12">
                    <p style="margin-bottom: 0.5rem;">
                        The connectivity check daemon reset the network adapter <strong><?php echo htmlspecialchars($resetState['adapter'] ?? 'unknown'); ?></strong>
                        on <strong><?php echo htmlspecialchars(date('Y-m-d H:i:s', $resetState['resetTimestamp'] ?? 0)); ?></strong>
                        and has stopped monitoring to prevent a reset loop.
                    </p>
                    <p style="margin-bottom: 1rem; color: #666;">
                        If your network is now stable, click the button below to clear this state and restart the connectivity daemon.
                    </p>
                    <button type="button" class="buttons btn-warning" id="clearResetStateBtn" onclick="clearResetState()">
                        <i class="fas fa-redo"></i> Clear State &amp; Restart Daemon
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" action="" id="watcherSettingsForm">
            <div class="settingsPanel">
                <div class="panelTitle">
                    <i class="fas fa-wifi"></i> Connectivity Check Settings
                </div>

                <!-- Enable/Disable Toggle -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="watcherEnabled" name="connectivityCheckEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['connectivityCheckEnabled'])) ? 'checked' : ''; ?>>
                            Enable Connectivity Check
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable or disable the connectivity check service. When disabled, the service will not monitor network connectivity.
                        </div>
                    </div>
                </div>

                <!-- Check Interval -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel" for="checkInterval">Check Interval (seconds)</label>
                    </div>
                    <div class="col-md-8">
                        <input type="number" id="checkInterval" name="checkInterval" class="form-control"
                            min="5" max="3600" value="<?php echo htmlspecialchars($config['checkInterval']); ?>">
                        <div class="settingDescription">
                            How frequently to check network connectivity (5-3600 seconds). Lower values check more often but consume more resources.
                        </div>
                    </div>
                </div>

                <!-- Max Failures -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel" for="maxFailures">Max Failures Before Reset</label>
                    </div>
                    <div class="col-md-8">
                        <input type="number" id="maxFailures" name="maxFailures" class="form-control"
                            min="1" max="100" value="<?php echo htmlspecialchars($config['maxFailures']); ?>">
                        <div class="settingDescription">
                            Number of consecutive connectivity check failures before attempting to reset the network adapter (1-100).
                        </div>
                    </div>
                </div>

                <!-- Network Adapter Selection -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel" for="networkAdapter">Network Adapter</label>
                    </div>
                    <div class="col-md-8">
                        <select id="networkAdapter" name="networkAdapter" class="form-control">
                            <option value="default" <?php echo ($config['networkAdapter'] === 'default') ? 'selected' : ''; ?>>
                                Auto-detect (detected: <?php echo htmlspecialchars($actualAdapter); ?>)
                            </option>
                            <?php foreach ($interfaces as $iface): ?>
                            <option value="<?php echo htmlspecialchars($iface); ?>"
                                <?php echo ($config['networkAdapter'] === $iface) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($iface); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="settingDescription">
                            Select the network adapter to monitor and reset if necessary. Use "Auto-detect" to automatically select the active interface, or choose a specific interface from the detected network interfaces.
                        </div>
                    </div>
                </div>

                <!-- Test Hosts -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">Test Hosts/IPs</label>
                    </div>
                    <div class="col-md-8">
                        <div class="hostInputGroup">
                            <input type="text" id="newHostInput" class="form-control"
                                placeholder="<?php echo htmlspecialchars($hostPlaceholder); ?>"
                                value="<?php echo htmlspecialchars($gatewayInputValue); ?>">
                            <button type="button" class="buttons btn-success" onclick="AddTestHost()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        <?php if ($gatewaySuggestion): ?>
                        <div class="settingDescription" style="margin-top: -0.5rem;">
                            Detected reachable gateway <?php echo htmlspecialchars($gatewaySuggestion); ?>.
                            Click "Add" to include it as a test host.
                        </div>
                        <?php endif; ?>

                        <div class="testHostsList" id="testHostsList">
                            <?php
                            $testHosts = $config['testHosts'];
                            if (!empty($testHosts)):
                                foreach ($testHosts as $index => $host):
                            ?>
                            <div class="testHostItem" data-index="<?php echo $index; ?>">
                                <span><strong><?php echo htmlspecialchars($host); ?></strong></span>
                                <input type="hidden" name="testHosts[]" value="<?php echo htmlspecialchars($host); ?>">
                                <button type="button" class="buttons btn-danger btn-sm" onclick="RemoveTestHost(this)">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                            <?php
                                endforeach;
                            else:
                            ?>
                            <div style="padding: 1rem; text-align: center; color: #6c757d;">No test hosts configured. Add at least one.</div>
                            <?php endif; ?>
                        </div>

                        <div class="settingDescription">
                            List of hostnames or IP addresses to check for connectivity. At least one host should be reachable (e.g., gateway, public DNS like 8.8.8.8 or 1.1.1.1).
                            We don't recommend more than 3 hosts to avoid excessive network traffic and metric fize size.
                        </div>
                    </div>
                </div>

            </div>

            <!-- System Metrics Collection Settings -->
            <div class="settingsPanel" style="margin-top: 2rem;">
                <div class="panelTitle">
                    <i class="fas fa-chart-line"></i> System Metrics Collection
                </div>

                <!-- Enable/Disable collectd -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="collectdEnabled" name="collectdEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['collectdEnabled'])) ? 'checked' : ''; ?>>
                            Enable collectd Service
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable or disable the collectd service. Collectd collects system metrics including CPU usage, memory usage,
                            disk space, network interface statistics, temperature and system load averages. On average collectd will use about 60MB of
                            storage to keep historical data for these metrics.  On Linux we also run this process at a lower priority.  This ensures FPP
                            performance is not impacted while still collecting useful system metrics.
                            <p></p>
                            These metrics are displayed in the. "Watcher - Local Metrics" dashboard.
                            Disabling this service will stop metric collection and reduce system overhead, but historical data will be preserved.
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($isPlayerMode): ?>
            <!-- Remote Metrics Settings (Player Mode Only) -->
            <div class="settingsPanel" style="margin-top: 2rem;">
                <div class="panelTitle">
                    <i class="fas fa-network-wired"></i> Player Mode Options
                </div>

                <!-- Enable/Disable Remote Metrics -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="multiSyncMetricsEnabled" name="multiSyncMetricsEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['multiSyncMetricsEnabled'])) ? 'checked' : ''; ?>>
                            Enable Remote Metrics Dashboard
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable a dashboard that aggregates system metrics from all remote FPP systems in your multi-sync setup.
                            This allows you to monitor CPU, memory, disk, and other metrics for all connected remotes from a single page.
                            <p></p>
                            <strong>Note:</strong> Remote systems must also have the Watcher plugin installed with collectd enabled
                            for their metrics to be collected.
                            <p></p>
                            <span style="display: inline-block; background: #e9ecef; border: 1px solid #adb5bd; border-radius: 4px; padding: 0.4em 0.8em; font-weight: 500;">
                                <i class="fas fa-server" style="color: #495057;"></i> <?php echo $remoteSystemCount; ?> remote system(s) detected
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Enable/Disable Remote Ping Monitoring -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="multiSyncPingEnabled" name="multiSyncPingEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['multiSyncPingEnabled'])) ? 'checked' : ''; ?>>
                            Enable Remote Ping Monitoring
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable continuous ping monitoring of all remote multi-sync systems.
                            This tracks network latency and availability between this player and all remote FPP systems,
                            providing historical ping metrics and charts similar to the connectivity check.
                            <p></p>
                            <strong>Note:</strong> This does not require the Watcher plugin on remote systems.
                        </div>
                    </div>
                </div>

                <!-- Remote Ping Interval (sub-setting) -->
                <div class="row settingRow" style="margin-left: 2rem; padding-left: 1rem; border-left: 3px solid #dee2e6;">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel" for="multiSyncPingInterval">Ping Interval (seconds)</label>
                    </div>
                    <div class="col-md-8">
                        <input type="number" id="multiSyncPingInterval" name="multiSyncPingInterval" class="form-control"
                            min="10" max="300" value="<?php echo htmlspecialchars($config['multiSyncPingInterval'] ?? WATCHERDEFAULTSETTINGS['multiSyncPingInterval']); ?>">
                        <div class="settingDescription">
                            How frequently to ping each remote multi-sync system (10-300 seconds).
                            Lower values provide more granular monitoring but increase network traffic and storage.
                        </div>
                    </div>
                </div>

                <!-- Enable/Disable Remote Control UI -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="controlUIEnabled" name="controlUIEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['controlUIEnabled'])) ? 'checked' : ''; ?>>
                            Enable Remote Control Dashboard
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable a dashboard to control remote FPP systems in your multi-sync setup.
                            Provides quick actions like toggling test mode, restarting FPPD, rebooting devices,
                            and viewing system information for all connected remotes from a single page.
                            <p></p>
                            <strong>Note:</strong> Remote systems must be accessible over the network.
                            Control actions are sent directly to each remote FPP instance.
                        </div>
                    </div>
                </div>

                <!-- Issue Checkers (sub-setting) -->
                <div class="row settingRow" style="margin-left: 2rem; padding-left: 1rem; border-left: 3px solid #dee2e6;">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">Issue Checks</label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription" style="margin-top: 0;">
                            <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; margin-bottom: 0.3rem;">
                                <input type="checkbox" name="issueCheckOutputs" value="1" style="flex-shrink: 0; margin-top: 0.15rem;"
                                    <?php echo (!empty($config['issueCheckOutputs'])) ? 'checked' : ''; ?>>
                                <span><strong>Output to Remote</strong> - Warn when outputs target systems in remote mode</span>
                            </label>
                            <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; margin-bottom: 0;">
                                <input type="checkbox" name="issueCheckSequences" value="1" style="flex-shrink: 0; margin-top: 0.15rem;"
                                    <?php echo (!empty($config['issueCheckSequences'])) ? 'checked' : ''; ?>>
                                <span><strong>Missing Sequences</strong> - Warn when remotes are missing sequences from the player</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Enable/Disable MQTT Monitor -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="mqttMonitorEnabled" name="mqttMonitorEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['mqttMonitorEnabled'])) ? 'checked' : ''; ?>>
                            Enable MQTT Event Monitor
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable a dashboard that captures and displays MQTT events from FPP.
                            Tracks sequence start/stop, playlist events, and status updates from all FPP instances publishing to the MQTT broker.
                            <p></p>
                            <span class="watcher-auto-config-note">
                                <i class="fas fa-magic"></i> <strong>Auto-configured:</strong> Enabling this will automatically configure FPP's built-in MQTT broker.
                            </span>
                            <p></p>
                            <strong>Subscribed Topics:</strong> Sequence events, Playlist events, Status updates, Command Events
                        </div>
                    </div>
                </div>

                <!-- MQTT Retention Days (sub-setting) -->
                <div class="row settingRow" style="margin-left: 2rem; padding-left: 1rem; border-left: 3px solid #dee2e6;">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel" for="mqttRetentionDays">Event Retention (days)</label>
                    </div>
                    <div class="col-md-8">
                        <input type="number" id="mqttRetentionDays" name="mqttRetentionDays" class="form-control"
                            min="1" max="365" value="<?php echo htmlspecialchars($config['mqttRetentionDays'] ?? WATCHERDEFAULTSETTINGS['mqttRetentionDays']); ?>">
                        <div class="settingDescription">
                            How long to retain MQTT event data for historical graphing (1-365 days).
                            Default is 60 days (2 months). Longer retention uses more disk space.
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Falcon Controller Monitor Settings -->
            <div class="settingsPanel" style="margin-top: 2rem;">
                <div class="panelTitle">
                    <i class="fas fa-broadcast-tower"></i> Falcon Controller Monitor
                </div>

                <!-- Enable/Disable Falcon Monitor -->
                <div class="row settingRow">
                    <div class="col-md-4 col-lg-3">
                        <label class="settingLabel">
                            <input type="checkbox" id="falconMonitorEnabled" name="falconMonitorEnabled" class="form-check-input" value="1"
                                <?php echo (!empty($config['falconMonitorEnabled'])) ? 'checked' : ''; ?>>
                            Enable Falcon Monitor Dashboard
                        </label>
                    </div>
                    <div class="col-md-8">
                        <div class="settingDescription">
                            Enable a dashboard for monitoring Falcon pixel controllers on your network.
                            Displays real-time status including temperatures, voltages, pixel counts, and firmware versions.
                            <p></p>Allows triggering test mode and rebooting the controllers directly from the dashboard.
                            <p></p>
                            <span style="display: inline-block; background: #e7f3ff; border: 1px solid #b3d7ff; border-radius: 4px; padding: 0.4em 0.8em; font-weight: 500; margin: 0.25em 0;">
                                <i class="fas fa-microchip" style="color: #0066cc;"></i> <strong>Supported Controllers:</strong> F4V2, F16V2, F4V3, F16V3, F48
                            </span>
                            <p></p>
                            <strong>Note:</strong> Configure controller IP addresses in the Falcon Monitor dashboard once enabled.
                            Controllers must be network-accessible from this FPP instance.
                        </div>
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
        // Add a test host dynamically
        function AddTestHost() {
            const input = document.getElementById('newHostInput');
            const host = input.value.trim();

            if (!host) {
                alert('Please enter a hostname or IP address');
                return;
            }

            // Check for duplicates
            const existingHosts = Array.from(document.querySelectorAll('input[name="testHosts[]"]'))
                .map(el => el.value);

            if (existingHosts.includes(host)) {
                alert('This host is already in the list');
                return;
            }

            // Add to the list
            const container = document.getElementById('testHostsList');

            // Remove "no hosts" message if present
            const noHostsMsg = container.querySelector('[style*="text-align: center"]');
            if (noHostsMsg) {
                noHostsMsg.remove();
            }

            const div = document.createElement('div');
            div.className = 'testHostItem';
            div.innerHTML = `
                <span><strong>${escapeHtml(host)}</strong></span>
                <input type="hidden" name="testHosts[]" value="${escapeHtml(host)}">
                <button type="button" class="buttons btn-danger btn-sm" onclick="RemoveTestHost(this)">
                    <i class="fas fa-trash"></i> Remove
                </button>
            `;
            container.appendChild(div);

            input.value = '';
        }

        // Remove a test host
        function RemoveTestHost(button) {
            const item = button.closest('.testHostItem');
            item.remove();

            // Check if list is now empty
            const container = document.getElementById('testHostsList');
            if (container.children.length === 0) {
                container.innerHTML = '<div style="padding: 1rem; text-align: center; color: #6c757d;">No test hosts configured. Add at least one.</div>';
            }
        }

        // Allow Enter key to add test host
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('newHostInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    AddTestHost();
                }
            });

            // Form validation before submit
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
                    // Reload page to reflect new state
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
