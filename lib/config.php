<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/watcherCommon.php';

/**
 * Bootstrap default settings
 */
function setDefaultWatcherSettings() {
    logMessage("Setting default Watcher Config");

    foreach (WATCHERDEFAULTSETTINGS as $settingName => $settingValue) {
        logMessage("Setting $settingName = $settingValue");
        /** @disregard P1010 */
        WriteSettingToFile($settingName, $settingValue, $plugin = WATCHERPLUGINNAME);
    }
}

// Helper function to normalize boolean config values
function normalizeBoolean(&$config, $key, $default = false) {
    if (isset($config[$key])) {
        $config[$key] = filter_var($config[$key], FILTER_VALIDATE_BOOLEAN);
    } else {
        $config[$key] = $default;
    }
}

// Prepare configuration by processing specific fields
function prepareConfig($config) {
    // Normalize boolean flags (INI parsing returns strings)
    normalizeBoolean($config, 'connectivityCheckEnabled', false);
    normalizeBoolean($config, 'collectdEnabled', true);
    normalizeBoolean($config, 'multiSyncMetricsEnabled', false);
    normalizeBoolean($config, 'multiSyncPingEnabled', false);
    normalizeBoolean($config, 'falconMonitorEnabled', false);

    // Process testHosts into an array
    if (isset($config['testHosts'])) {
        $config['testHosts'] = array_map('trim', explode(',', $config['testHosts']));
    } else {
        $config['testHosts'] = ['8.8.8.8']; // Default host
    }

    return $config;
}

// Manage collectd service (enable or disable)
function manageCollectdService($enable) {
    $action = $enable ? 'enable' : 'disable';
    $logAction = $enable ? 'Enabling' : 'Disabling';

    logMessage("$logAction collectd service...");

    // Use systemctl to enable/disable and start/stop the service
    // --now flag makes systemctl start/stop the service immediately
    $command = "sudo systemctl --now $action collectd.service 2>&1";
    $output = [];
    $returnCode = 0;

    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        logMessage("Successfully {$action}d collectd service");
        return true;
    } else {
        $errorMsg = implode("\n", $output);
        logMessage("ERROR: Failed to $action collectd service. Return code: $returnCode. Output: $errorMsg");
        return false;
    }
}

// Read plugin configuration file
function readPluginConfig() {
    global $settings;
    $configFile = WATCHERCONFIGFILELOCATION;

    if (!file_exists($configFile)) {
        logMessage('Watcher config file does not exist. Creating default config file.');
        setDefaultWatcherSettings();
    }

    ensureFppOwnership($configFile);

    logMessage("Loading existing Watcher config file: ".WATCHERCONFIGFILELOCATION);
    $fd = fopen($configFile, 'r');
    if ($fd === false) {
        logMessage("ERROR: Failed to open config file: " . WATCHERCONFIGFILELOCATION);
        return prepareConfig(WATCHERDEFAULTSETTINGS);
    }

    flock($fd, LOCK_SH);
    $config = parse_ini_file($configFile);
    flock($fd, LOCK_UN);
    fclose($fd);

    return prepareConfig($config);
}
?>
