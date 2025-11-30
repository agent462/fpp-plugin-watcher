<?php
/**
 * Remote FPP Control Helper Functions
 *
 * Provides functions for proxying commands to remote FPP instances.
 */

include_once __DIR__ . '/watcherCommon.php';

/**
 * Get status from a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and data
 */
function getRemoteStatus($host) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    // Fetch system status (includes restartFlag and rebootFlag)
    $statusUrl = "http://{$host}/api/system/status";
    $status = apiCall('GET', $statusUrl, [], true, 5);

    if ($status === false) {
        return [
            'success' => false,
            'error' => 'Failed to connect to remote host',
            'host' => $host
        ];
    }

    // Fetch test mode status
    $testModeUrl = "http://{$host}/api/testmode";
    $testMode = apiCall('GET', $testModeUrl, [], true, 5);
    if ($testMode === false) {
        $testMode = ['enabled' => 0];
    }

    return [
        'success' => true,
        'host' => $host,
        'status' => $status,
        'testMode' => $testMode
    ];
}

/**
 * Send a command to a remote FPP instance
 *
 * @param string $host The remote host
 * @param string $command The command to send
 * @param array $args Optional command arguments
 * @param bool $multisyncCommand Whether this is a multisync command
 * @param string $multisyncHosts Hosts for multisync
 * @return array Result with success status
 */
function sendRemoteCommand($host, $command, $args = [], $multisyncCommand = false, $multisyncHosts = '') {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    // Build command payload as JSON string
    $commandData = json_encode([
        'command' => $command,
        'multisyncCommand' => $multisyncCommand,
        'multisyncHosts' => $multisyncHosts,
        'args' => $args
    ]);

    $commandUrl = "http://{$host}/api/command";
    $result = apiCall('POST', $commandUrl, $commandData, true, 10);

    if ($result === false) {
        return [
            'success' => false,
            'error' => 'Failed to send command to remote host',
            'host' => $host
        ];
    }

    return [
        'success' => true,
        'host' => $host,
        'result' => $result
    ];
}

/**
 * Restart fppd on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status
 */
function restartRemoteFPPD($host) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    $restartUrl = "http://{$host}/api/system/fppd/restart";
    apiCall('GET', $restartUrl, [], true, 10);

    return [
        'success' => true,
        'host' => $host,
        'message' => 'Restart command sent'
    ];
}

/**
 * Reboot a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status
 */
function rebootRemoteFPP($host) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    $rebootUrl = "http://{$host}/api/system/reboot";
    apiCall('GET', $rebootUrl, [], true, 10);

    return [
        'success' => true,
        'host' => $host,
        'message' => 'Reboot command sent'
    ];
}

/**
 * Upgrade a plugin on a remote FPP instance
 *
 * @param string $host The remote host
 * @param string $plugin The plugin name (defaults to watcher plugin)
 * @return array Result with success status
 */
function upgradeRemotePlugin($host, $plugin = null) {
    if ($plugin === null) {
        $plugin = WATCHERPLUGINNAME;
    }

    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    // Validate plugin name format (alphanumeric, dash, underscore)
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $plugin)) {
        return [
            'success' => false,
            'error' => 'Invalid plugin name format'
        ];
    }

    $upgradeUrl = "http://{$host}/api/plugin/{$plugin}/upgrade";
    $result = apiCall('POST', $upgradeUrl, [], true, 120);

    if ($result === false) {
        return [
            'success' => false,
            'error' => 'Failed to trigger plugin upgrade on remote host',
            'host' => $host,
            'plugin' => $plugin
        ];
    }

    return [
        'success' => true,
        'host' => $host,
        'plugin' => $plugin,
        'message' => 'Plugin upgrade initiated on remote host',
        'result' => $result
    ];
}

/**
 * Get list of installed plugins on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and plugins list
 */
function getRemotePlugins($host) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    $pluginsUrl = "http://{$host}/api/plugin";
    $plugins = apiCall('GET', $pluginsUrl, [], true, 10);

    if ($plugins === false) {
        return [
            'success' => false,
            'error' => 'Failed to fetch plugins from remote host',
            'host' => $host
        ];
    }

    return [
        'success' => true,
        'host' => $host,
        'plugins' => $plugins
    ];
}

/**
 * Check for plugin updates on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and updates list
 */
function checkRemotePluginUpdates($host) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    // Get list of installed plugins (returns array of plugin name strings)
    $pluginsUrl = "http://{$host}/api/plugin";
    $pluginNames = apiCall('GET', $pluginsUrl, [], true, 10);

    if ($pluginNames === false || !is_array($pluginNames)) {
        return [
            'success' => false,
            'error' => 'Failed to fetch plugins from remote host',
            'host' => $host
        ];
    }

    // Get latest Watcher version from GitHub for comparison
    $latestWatcherVersion = getLatestWatcherVersion();

    $updatesAvailable = [];

    // Check each plugin for updates
    foreach ($pluginNames as $repoName) {
        // Skip if not a string (unexpected format)
        if (!is_string($repoName)) continue;

        // Get full plugin info from /api/plugin/:repoName
        $pluginInfoUrl = "http://{$host}/api/plugin/{$repoName}";
        $pluginInfo = apiCall('GET', $pluginInfoUrl, [], true, 5);

        if (!$pluginInfo || !is_array($pluginInfo)) continue;

        $hasUpdate = false;
        $installedVersion = $pluginInfo['version'] ?? 'unknown';
        $pluginName = $pluginInfo['name'] ?? $repoName;

        // Check FPP's built-in update flag
        if (isset($pluginInfo['updatesAvailable']) && $pluginInfo['updatesAvailable']) {
            $hasUpdate = true;
        }

        // For Watcher plugin, also compare against GitHub version
        if ($repoName === WATCHERPLUGINNAME && $latestWatcherVersion && $installedVersion !== 'unknown') {
            if (version_compare($latestWatcherVersion, $installedVersion, '>')) {
                $hasUpdate = true;
            }
        }

        if ($hasUpdate) {
            $updateInfo = [
                'repoName' => $repoName,
                'name' => $pluginName,
                'installedVersion' => $installedVersion,
                'updatesAvailable' => true
            ];
            // Include latest version for Watcher plugin
            if ($repoName === WATCHERPLUGINNAME && $latestWatcherVersion) {
                $updateInfo['latestVersion'] = $latestWatcherVersion;
            }
            $updatesAvailable[] = $updateInfo;
        }
    }

    return [
        'success' => true,
        'host' => $host,
        'totalPlugins' => count($pluginNames),
        'updatesAvailable' => $updatesAvailable
    ];
}

/**
 * Stream FPP upgrade output from a remote host
 * This function handles output directly and does not return a value.
 *
 * @param string $host The remote host
 * @return void
 */
function streamRemoteFPPUpgrade($host) {
    if (!validateHost($host)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid host format'
        ]);
        return;
    }

    // Disable output buffering for streaming
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set headers for streaming text
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Disable nginx buffering

    // Flush headers
    flush();

    $upgradeUrl = "http://{$host}/manualUpdate.php?wrapped=1";

    echo "=== Starting FPP upgrade on {$host} ===\n";
    flush();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $upgradeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 900); // 15 minute timeout for compile
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher');

    // Stream output as it arrives
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
        echo $data;
        flush();
        return strlen($data);
    });

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false || $httpCode !== 200) {
        echo "\n=== ERROR: Failed to complete upgrade (HTTP {$httpCode}): {$error} ===\n";
    } else {
        echo "\n=== FPP upgrade completed on {$host} ===\n";
    }

    flush();
}
?>
