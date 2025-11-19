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
    // Process testHosts into an array
    if (isset($config['testHosts'])) {
        $config['testHosts'] = array_map('trim', explode(',', $config['testHosts']));
    } else {
        $config['testHosts'] = ['8.8.8.8']; // Default host
    }
    return $config;
}

// Read plugin configuration file
function readPluginConfig() {
    global $settings;
    $configFile = WATCHERCONFIGFILELOCATION;
    
    if (!file_exists($configFile)) {
        logMessage('Watcher config file does not exist. Creating default config file.');
        setDefaultWatcherSettings();
    }
    
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