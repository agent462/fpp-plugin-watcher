<?php
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
    normalizeBoolean($config, 'controlUIEnabled', true);
    normalizeBoolean($config, 'mqttMonitorEnabled', false);

    // Parse retention days as integer
    if (isset($config['mqttRetentionDays'])) {
        $config['mqttRetentionDays'] = max(1, min(365, intval($config['mqttRetentionDays'])));
    } else {
        $config['mqttRetentionDays'] = WATCHERDEFAULTSETTINGS['mqttRetentionDays'];
    }

    // Process testHosts into an array
    if (isset($config['testHosts'])) {
        $config['testHosts'] = array_map('trim', explode(',', $config['testHosts']));
    } else {
        $config['testHosts'] = ['8.8.8.8']; // Default host
    }

    return $config;
}

/**
 * Configure FPP's MQTT settings when MQTT Monitor is enabled/disabled
 * Sets up or tears down local broker, credentials, and publish frequencies
 *
 * @param bool $enable - true to enable/configure, false to disable
 * @return array - result with 'success' and 'messages' keys
 */
function configureFppMqttSettings($enable) {
    include_once __DIR__ . '/apiCall.php';

    $result = ['success' => true, 'messages' => []];

    if ($enable) {
        // Settings to configure when enabling MQTT Monitor
        $mqttSettings = [
            'Service_MQTT_localbroker' => '1',      // Enable local MQTT broker
            'MQTTHost' => 'localhost',               // Connect to local broker
            'MQTTUsername' => 'fpp',                 // Default FPP MQTT user
            'MQTTPassword' => 'falcon',              // Default FPP MQTT password
        ];
    } else {
        // Settings to configure when disabling MQTT Monitor
        $mqttSettings = [
            'Service_MQTT_localbroker' => '0',      // Disable local MQTT broker
            'MQTTHost' => '',                        // Clear broker host
            'MQTTUsername' => '',                    // Clear username
            'MQTTPassword' => '',                    // Clear password
        ];
    }

    foreach ($mqttSettings as $settingName => $settingValue) {
        $response = apiCall(
            'PUT',
            "http://127.0.0.1/api/settings/{$settingName}",
            $settingValue,
            true,
            5,
            ['Content-Type: text/plain']
        );

        if ($response === false) {
            $result['messages'][] = "Failed to set {$settingName}";
            $result['success'] = false;
        } else {
            $result['messages'][] = "Set {$settingName} = {$settingValue}";
        }
    }

    return $result;
}

// Read plugin configuration file (cached per request)
function readPluginConfig($forceReload = false) {
    static $cachedConfig = null;

    // Return cached config if available (unless forced reload)
    if ($cachedConfig !== null && !$forceReload) {
        return $cachedConfig;
    }

    global $settings;
    $configFile = WATCHERCONFIGFILELOCATION;

    if (!file_exists($configFile)) {
        logMessage('Watcher config file does not exist. Creating default config file.');
        setDefaultWatcherSettings();
    }

    ensureFppOwnership($configFile);

    #logMessage("Loading Watcher config file: ".WATCHERCONFIGFILELOCATION); \\ make this debug in future
    $fd = fopen($configFile, 'r');
    if ($fd === false) {
        logMessage("ERROR: Failed to open config file: " . WATCHERCONFIGFILELOCATION);
        return prepareConfig(WATCHERDEFAULTSETTINGS);
    }

    flock($fd, LOCK_SH);
    $config = parse_ini_file($configFile);
    flock($fd, LOCK_UN);
    fclose($fd);

    $cachedConfig = prepareConfig($config);
    return $cachedConfig;
}
?>
