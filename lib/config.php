<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . '/watcherCommon.php';

define("WATCHERCONFIGFILE", readPluginConfig(WATCHERPLUGINNAME));
/**
 * Bootstrap default settings
 */
function setDefaultWatcherSettings() {
    logMessage("Setting default Watcher Config");

    foreach (WATCHERDEFAULTSETTINGS as $settingName => $settingValue) {        
        logMessage("Setting $settingName = $settingValue");
        /** @disregard P1010 */     
        WriteSettingToFile($settingName, $settingValue, $plugin = WATCHERPLUGINNAME); //Call WriteSettingToFile from common.php
    }
}
// Prepare configuration by processing specific fields
function prepareConfig($config) {
    // Normalize enabled flag to a real boolean since INI parsing returns strings
    if (isset($config['enabled'])) {
        $config['enabled'] = filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN);
    } else {
        $config['enabled'] = false;
    }

    // Normalize collectdEnabled flag to a real boolean
    if (isset($config['collectdEnabled'])) {
        $config['collectdEnabled'] = filter_var($config['collectdEnabled'], FILTER_VALIDATE_BOOLEAN);
    } else {
        $config['collectdEnabled'] = true; // Default to enabled
    }

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
