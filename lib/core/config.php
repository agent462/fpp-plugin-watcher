<?php
include_once __DIR__ . '/watcherCommon.php';

use Watcher\Core\Settings;
use Watcher\Http\ApiClient;

/**
 * Bootstrap default settings
 */
function setDefaultWatcherSettings() {
    logMessage("Setting default Watcher Config");

    $settings = Settings::getInstance();
    foreach (WATCHERDEFAULTSETTINGS as $settingName => $settingValue) {
        logMessage("Setting $settingName = $settingValue");
        $settings->writeSettingToFile($settingName, (string)$settingValue, WATCHERPLUGINNAME);
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

/**
 * Check if any changed settings require an FPP restart
 * Compares old config values to new values for settings marked in WATCHERSETTINGSRESTARTREQUIRED
 *
 * @param array $oldConfig Previous configuration (from readPluginConfig)
 * @param array $newSettings New settings being saved (key => value)
 * @return bool True if restart is required, false otherwise
 */
function settingsRequireRestart($oldConfig, $newSettings) {
    foreach ($newSettings as $key => $newValue) {
        // Skip if this setting doesn't require restart
        if (!isset(WATCHERSETTINGSRESTARTREQUIRED[$key]) || !WATCHERSETTINGSRESTARTREQUIRED[$key]) {
            continue;
        }

        // Get old value (handle both array and string formats for testHosts)
        $oldValue = $oldConfig[$key] ?? null;

        // Normalize for comparison
        if ($key === 'testHosts') {
            // testHosts: old is array, new is comma-separated string
            $oldNormalized = is_array($oldValue) ? implode(',', $oldValue) : $oldValue;
            $newNormalized = $newValue;
        } elseif (is_bool($oldValue)) {
            // Boolean settings: normalize string to bool for comparison
            $oldNormalized = $oldValue;
            $newNormalized = filter_var($newValue, FILTER_VALIDATE_BOOLEAN);
        } else {
            // Other settings: compare as strings
            $oldNormalized = (string)$oldValue;
            $newNormalized = (string)$newValue;
        }

        if ($oldNormalized !== $newNormalized) {
            logMessage("Setting '$key' changed from '$oldNormalized' to '$newNormalized' - restart required");
            return true;
        }
    }

    return false;
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
    normalizeBoolean($config, 'issueCheckOutputs', true);
    normalizeBoolean($config, 'issueCheckSequences', true);
    normalizeBoolean($config, 'efuseMonitorEnabled', false);

    // Parse retention days as integer
    if (isset($config['mqttRetentionDays'])) {
        $config['mqttRetentionDays'] = max(1, min(365, intval($config['mqttRetentionDays'])));
    } else {
        $config['mqttRetentionDays'] = WATCHERDEFAULTSETTINGS['mqttRetentionDays'];
    }

    // Parse eFuse collection interval (1-60 seconds)
    if (isset($config['efuseCollectionInterval'])) {
        $config['efuseCollectionInterval'] = max(1, min(60, intval($config['efuseCollectionInterval'])));
    } else {
        $config['efuseCollectionInterval'] = WATCHERDEFAULTSETTINGS['efuseCollectionInterval'];
    }

    // Parse eFuse retention days (1-90 days)
    if (isset($config['efuseRetentionDays'])) {
        $config['efuseRetentionDays'] = max(1, min(90, intval($config['efuseRetentionDays'])));
    } else {
        $config['efuseRetentionDays'] = WATCHERDEFAULTSETTINGS['efuseRetentionDays'];
    }

    // Process testHosts into an array
    if (isset($config['testHosts'])) {
        $config['testHosts'] = array_map('trim', explode(',', $config['testHosts']));
    } else {
        $config['testHosts'] = ['8.8.8.8'];
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
        $response = ApiClient::getInstance()->put(
            "http://127.0.0.1/api/settings/{$settingName}",
            $settingValue,
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
