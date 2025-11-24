<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - Connectivity Check Settings</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <style>
        .watcherSettingsContainer {
            max-width: 900px;
            margin: 0 auto;
        }

        .settingRow {
            margin-bottom: 1.5rem;
            padding: 0.5rem;
        }

        .settingLabel {
            font-weight: 500;
            padding-top: 0.5rem;
        }

        .settingDescription {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.25rem;
            font-style: italic;
        }

        .testHostsList {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.75rem;
            background-color: #f8f9fa;
            min-height: 100px;
        }

        .testHostItem {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }

        .testHostItem:last-child {
            margin-bottom: 0;
        }

        .hostInputGroup {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .hostInputGroup input {
            flex: 1;
        }

        .hostInputGroup button {
            min-width: 100px;
        }

        .formActions {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .statusMessage {
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
            display: none;
        }

        .statusMessage.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }

        .statusMessage.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }

        .settingsPanel {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1.5rem;
        }

        .panelTitle {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #212529;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
        }

        .inputGroup {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-control {
            border-radius: 0.375rem;
            border: 1px solid #dee2e6;
            padding: 0.375rem 0.75rem;
        }

        .form-control:focus {
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            outline: none;
        }
    </style>
</head>
<body>

<?php
// Include FPP common functions and configuration
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/lib/watcherCommon.php";
include_once __DIR__ . "/lib/config.php";

$statusMessage = '';
$statusType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Get form values
    $connectivityCheckEnabled = isset($_POST['connectivityCheckEnabled']) ? 'true' : 'false';
    $checkInterval = intval($_POST['checkInterval']);
    $maxFailures = intval($_POST['maxFailures']);
    $networkAdapter = trim($_POST['networkAdapter']);
    $collectdEnabled = isset($_POST['collectdEnabled']) ? 'true' : 'false';

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

    if (empty($errors)) {
        // Check if collectdEnabled changed
        $currentConfig = readPluginConfig();
        $collectdChanged = ($currentConfig['collectdEnabled'] != ($collectdEnabled === 'true'));

        // Save settings using FPP's WriteSettingToFile
        $settingsToSave = [
            'connectivityCheckEnabled' => $connectivityCheckEnabled,
            'checkInterval' => $checkInterval,
            'maxFailures' => $maxFailures,
            'networkAdapter' => $networkAdapter,
            'testHosts' => implode(',', $testHosts),
            'collectdEnabled' => $collectdEnabled
        ];

        foreach ($settingsToSave as $settingName => $settingValue) {
            /** @disregard P1010 */
            WriteSettingToFile($settingName, $settingValue, WATCHERPLUGINNAME);
        }

        // Manage collectd service if setting changed
        if ($collectdChanged) {
            $collectdSuccess = manageCollectdService($collectdEnabled === 'true');
            if (!$collectdSuccess) {
                $statusMessage = 'Settings saved, but failed to ' . ($collectdEnabled === 'true' ? 'enable' : 'disable') . ' collectd service. Check logs for details.';
                $statusType = 'error';
            }
        }

        // Set success message if not already set by collectd error
        if (empty($statusMessage)) {
            // Set FPP restart flag to notify that fppd restart is needed
            /** @disregard P1010 */
            WriteSettingToFile('restartFlag', 1);

            $statusMessage = 'Settings saved successfully!';
            $statusType = 'success';
        }

        // Reload config
        $config = readPluginConfig();
    } else {
        $statusMessage = 'Error saving settings: ' . implode(', ', $errors);
        $statusType = 'error';
    }
}

// Load current settings
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
?>

    <div class="watcherSettingsContainer">
        <?php if ($statusMessage): ?>
        <div class="statusMessage <?php echo htmlspecialchars($statusType); ?>">
            <?php echo htmlspecialchars($statusMessage); ?>
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
                            These metrics are displayed in the. "Watcher - Metrics" dashboard. 
                            Disabling this service will stop metric collection and reduce system overhead, but historical data will be preserved.
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
                <span><strong>${EscapeHtml(host)}</strong></span>
                <input type="hidden" name="testHosts[]" value="${EscapeHtml(host)}">
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

        // Escape HTML to prevent XSS
        function EscapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
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
    </script>
</body>
</html>
