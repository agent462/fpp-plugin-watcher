<?php
/**
 * Remote FPP Control Helper Functions
 *
 * Provides functions for proxying commands to remote FPP instances.
 */

include_once __DIR__ . '/../core/watcherCommon.php';

/**
 * Extract common status fields from fppd status response
 *
 * @param array $fppStatus Raw fppd status response
 * @return array Normalized status fields
 */
function extractRemoteStatusFields($fppStatus) {
    return [
        'platform' => $fppStatus['platform'] ?? '--',
        'branch' => $fppStatus['branch'] ?? '--',
        'mode_name' => $fppStatus['mode_name'] ?? '--',
        'status_name' => $fppStatus['status_name'] ?? 'idle',
        'rebootFlag' => $fppStatus['rebootFlag'] ?? 0,
        'restartFlag' => $fppStatus['restartFlag'] ?? 0
    ];
}

/**
 * Check if a plugin has an available update
 *
 * @param array $pluginInfo Plugin info from FPP API
 * @param string $repoName Repository name of the plugin
 * @param string|null $latestWatcherVersion Latest Watcher version from GitHub (for Watcher comparison)
 * @return array|null Update info array if update available, null otherwise
 */
function checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion = null) {
    $hasUpdate = false;
    $installedVersion = $pluginInfo['version'] ?? 'unknown';
    $pluginName = $pluginInfo['name'] ?? $repoName;

    if (!empty($pluginInfo['updatesAvailable'])) {
        $hasUpdate = true;
    }

    if ($repoName === WATCHERPLUGINNAME && $latestWatcherVersion && $installedVersion !== 'unknown') {
        if (version_compare($latestWatcherVersion, $installedVersion, '>')) {
            $hasUpdate = true;
        }
    }

    if (!$hasUpdate) {
        return null;
    }

    $updateInfo = [
        'repoName' => $repoName,
        'name' => $pluginName,
        'installedVersion' => $installedVersion,
        'updatesAvailable' => true
    ];

    if ($repoName === WATCHERPLUGINNAME && $latestWatcherVersion) {
        $updateInfo['latestVersion'] = $latestWatcherVersion;
    }

    return $updateInfo;
}

/**
 * Call a remote FPP API endpoint with host validation
 *
 * @param string $host The remote host (IP or hostname)
 * @param string $method HTTP method (GET, POST, PUT, DELETE)
 * @param string $endpoint API endpoint path (e.g., '/api/fppd/status')
 * @param mixed $data Request data (array or JSON string)
 * @param int $timeout Request timeout in seconds
 * @return array Result with 'success', 'data' or 'error', and 'host'
 */
function callRemoteApi($host, $method, $endpoint, $data = [], $timeout = WATCHER_TIMEOUT_LONG) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format',
            'host' => $host
        ];
    }

    $url = "http://{$host}{$endpoint}";
    $result = apiCall($method, $url, $data, true, $timeout);

    if ($result === false) {
        return [
            'success' => false,
            'error' => 'Failed to connect to remote host',
            'host' => $host
        ];
    }

    return [
        'success' => true,
        'data' => $result,
        'host' => $host
    ];
}

/**
 * Get status from a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and data
 */
function getRemoteStatus($host) {
    $result = callRemoteApi($host, 'GET', '/api/fppd/status', [], 5);
    if (!$result['success']) {
        return $result;
    }
    $fppStatus = $result['data'];
    $status = extractRemoteStatusFields($fppStatus);

    // Fetch actual test mode status from dedicated endpoint
    $testModeResult = callRemoteApi($host, 'GET', '/api/testmode', [], 5);
    $testMode = ['enabled' => 0];
    if ($testModeResult['success'] && isset($testModeResult['data']['enabled'])) {
        $testMode['enabled'] = $testModeResult['data']['enabled'] ? 1 : 0;
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
    $commandData = json_encode([
        'command' => $command,
        'multisyncCommand' => $multisyncCommand,
        'multisyncHosts' => $multisyncHosts,
        'args' => $args
    ]);

    $result = callRemoteApi($host, 'POST', '/api/command', $commandData, 10);
    if (!$result['success']) {
        $result['error'] = 'Failed to send command to remote host';
        return $result;
    }

    return [
        'success' => true,
        'host' => $host,
        'result' => $result['data']
    ];
}

/**
 * Send a simple GET action to a remote FPP instance
 *
 * @param string $host The remote host
 * @param string $endpoint The API endpoint path
 * @param string $message Success message to return
 * @return array Result with success status
 */
function sendSimpleRemoteAction($host, $endpoint, $message) {
    $result = callRemoteApi($host, 'GET', $endpoint, [], 10);
    if (!$result['success']) {
        return $result;
    }

    return [
        'success' => true,
        'host' => $host,
        'message' => $message
    ];
}

/**
 * Restart fppd on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status
 */
function restartRemoteFPPD($host) {
    return sendSimpleRemoteAction($host, '/api/system/fppd/restart', 'Restart command sent');
}

/**
 * Reboot a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status
 */
function rebootRemoteFPP($host) {
    return sendSimpleRemoteAction($host, '/api/system/reboot', 'Reboot command sent');
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

    // Validate plugin name format (alphanumeric, dash, underscore)
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $plugin)) {
        return [
            'success' => false,
            'error' => 'Invalid plugin name format'
        ];
    }

    $result = callRemoteApi($host, 'POST', "/api/plugin/{$plugin}/upgrade", [], 120);
    if (!$result['success']) {
        $result['error'] = 'Failed to trigger plugin upgrade on remote host';
        $result['plugin'] = $plugin;
        return $result;
    }

    return [
        'success' => true,
        'host' => $host,
        'plugin' => $plugin,
        'message' => 'Plugin upgrade initiated on remote host',
        'result' => $result['data']
    ];
}

/**
 * Get list of installed plugins on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and plugins list
 */
function getRemotePlugins($host) {
    $result = callRemoteApi($host, 'GET', '/api/plugin', [], 10);
    if (!$result['success']) {
        $result['error'] = 'Failed to fetch plugins from remote host';
        return $result;
    }

    return [
        'success' => true,
        'host' => $host,
        'plugins' => $result['data']
    ];
}

/**
 * Check for plugin updates on a remote FPP instance
 *
 * @param string $host The remote host
 * @return array Result with success status and updates list
 */
function checkRemotePluginUpdates($host) {
    // Get list of installed plugins using shared function
    $pluginsResult = getRemotePlugins($host);
    if (!$pluginsResult['success']) {
        return $pluginsResult;
    }
    $pluginNames = $pluginsResult['plugins'];

    if (!is_array($pluginNames)) {
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
        $pluginInfo = apiCall('GET', $pluginInfoUrl, [], true, WATCHER_TIMEOUT_STANDARD);

        if (!$pluginInfo || !is_array($pluginInfo)) continue;

        $updateInfo = checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion);
        if ($updateInfo) {
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
 * @param string|null $targetVersion Optional target version for cross-version upgrades (e.g., "v9.3")
 * @return void
 */
function streamRemoteFPPUpgrade($host, $targetVersion = null) {
    if (!validateHost($host)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Invalid host format'
        ]);
        return;
    }

    // Validate target version format if provided
    if ($targetVersion !== null) {
        $targetVersion = trim($targetVersion);
        // Ensure it starts with 'v' and has valid format (e.g., v9.3)
        if (!preg_match('/^v?\d+\.\d+/', $targetVersion)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid version format'
            ]);
            return;
        }
        // Ensure version starts with 'v'
        if (!str_starts_with($targetVersion, 'v')) {
            $targetVersion = 'v' . $targetVersion;
        }
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

    // Determine the upgrade URL based on whether this is a cross-version upgrade
    if ($targetVersion !== null) {
        // Cross-version upgrade: use upgradefpp.php with target version
        $upgradeUrl = "http://{$host}/upgradefpp.php?version=" . urlencode($targetVersion) . "&wrapped=1";
        echo "=== Starting FPP cross-version upgrade on {$host} to {$targetVersion} ===\n";
    } else {
        // Same-branch update: use manualUpdate.php
        $upgradeUrl = "http://{$host}/manualUpdate.php?wrapped=1";
        echo "=== Starting FPP upgrade on {$host} ===\n";
    }
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

/**
 * Stream Watcher plugin upgrade output in real-time
 * Uses FPP's native plugin upgrade endpoint with streaming support
 *
 * @param string $host The remote host
 */
function streamRemoteWatcherUpgrade($host) {
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

    echo "=== Starting Watcher plugin upgrade on {$host} ===\n\n";
    flush();

    // Use FPP's native plugin upgrade endpoint with streaming
    $upgradeUrl = "http://{$host}/api/plugin/fpp-plugin-watcher/upgrade?stream=true";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $upgradeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minute timeout
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

    if ($result === false || ($httpCode !== 200 && $httpCode !== 0)) {
        echo "\n=== ERROR: Failed to complete upgrade (HTTP {$httpCode}): {$error} ===\n";
    } else {
        echo "\n=== Watcher plugin upgrade completed on {$host} ===\n";
    }

    flush();
}

/**
 * Check for configuration issues between player and remote systems
 * Includes output discrepancies and missing sequences
 * Results are cached for 60 seconds to reduce API calls
 * Respects issueCheckOutputs and issueCheckSequences config settings
 */
function getOutputDiscrepancies() {
    include_once __DIR__ . '/../core/config.php';

    $cacheFile = WATCHERLOGDIR . '/watcher-discrepancies-cache.json';
    $cacheMaxAge = 60; // seconds

    // Read config to check which issue checks are enabled
    $config = readPluginConfig();
    $checkOutputs = !empty($config['issueCheckOutputs']);
    $checkSequences = !empty($config['issueCheckSequences']);

    // If all checks are disabled, return empty result (no cache needed)
    if (!$checkOutputs && !$checkSequences) {
        return [
            'success' => true,
            'discrepancies' => [],
            'remoteCount' => 0
        ];
    }

    // Check cache
    if (file_exists($cacheFile)) {
        $cacheAge = time() - filemtime($cacheFile);
        if ($cacheAge < $cacheMaxAge) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['success'])) {
                // Filter cached results based on current config
                if (isset($cached['discrepancies']) && is_array($cached['discrepancies'])) {
                    $cached['discrepancies'] = array_values(array_filter($cached['discrepancies'], function($d) use ($checkOutputs, $checkSequences) {
                        if ($d['type'] === 'output_to_remote' && !$checkOutputs) return false;
                        if ($d['type'] === 'missing_sequences' && !$checkSequences) return false;
                        return true;
                    }));
                }
                return $cached;
            }
        }
    }

    $discrepancies = [];

    // Get remote systems
    $remoteSystems = getMultiSyncRemoteSystems();
    $remotesByIP = [];
    foreach ($remoteSystems as $remote) {
        if (!empty($remote['address'])) {
            $remotesByIP[$remote['address']] = $remote;
        }
    }

    // =========================================================================
    // Check 1: Output configuration issues
    // =========================================================================
    if ($checkOutputs) {
        $outputsData = apiCall('GET', 'http://127.0.0.1/api/channel/output/universeOutputs', [], true, WATCHER_TIMEOUT_STANDARD);

        if ($outputsData && isset($outputsData['channelOutputs'])) {
            // Build map of active outputs per remote IP
            $activeOutputsByIP = [];
            foreach ($outputsData['channelOutputs'] as $outputGroup) {
                if (!isset($outputGroup['universes']) || !is_array($outputGroup['universes'])) {
                    continue;
                }
                foreach ($outputGroup['universes'] as $universe) {
                    $address = $universe['address'] ?? '';
                    $active = ($universe['active'] ?? 0) == 1;
                    if (empty($address) || !filter_var($address, FILTER_VALIDATE_IP) || !$active) {
                        continue;
                    }
                    if (!isset($activeOutputsByIP[$address])) {
                        $activeOutputsByIP[$address] = [];
                    }
                    $activeOutputsByIP[$address][] = $universe;
                }
            }

            // Check for active outputs to systems in remote mode
            foreach ($activeOutputsByIP as $ip => $outputs) {
                if (!isset($remotesByIP[$ip])) {
                    continue;
                }
                $remote = $remotesByIP[$ip];
                $remoteMode = $remote['fppModeString'] ?? '';
                $hostname = $remote['hostname'] ?? $ip;

                if ($remoteMode === 'remote') {
                    foreach ($outputs as $output) {
                        $discrepancies[] = [
                            'type' => 'output_to_remote',
                            'severity' => 'warning',
                            'address' => $ip,
                            'hostname' => $hostname,
                            'description' => $output['description'] ?? '',
                            'startChannel' => $output['startChannel'] ?? 0,
                            'channelCount' => $output['channelCount'] ?? 0,
                            'message' => "Output enabled to {$hostname} but it's in remote mode (receives via multisync)"
                        ];
                    }
                }
            }
        }
    }

    // =========================================================================
    // Check 2: Missing sequences on remote systems
    // =========================================================================
    if ($checkSequences) {
        $localSequences = apiCall('GET', 'http://127.0.0.1/api/sequence', [], true, WATCHER_TIMEOUT_STANDARD);

        if ($localSequences && is_array($localSequences) && count($localSequences) > 0 && count($remoteSystems) > 0) {
            // Build set of local sequence filenames
            $localSeqSet = [];
            foreach ($localSequences as $seq) {
                $name = is_array($seq) ? ($seq['Name'] ?? '') : $seq;
                if (!empty($name)) {
                    $localSeqSet[$name] = true;
                }
            }

            // Fetch sequences from remotes in parallel using curl_multi
            $mh = curl_multi_init();
            $handles = [];

            foreach ($remoteSystems as $system) {
                $ch = createCurlHandle("http://{$system['address']}/api/sequence", WATCHER_TIMEOUT_STATUS);
                curl_multi_add_handle($mh, $ch);
                $handles[$system['address']] = ['handle' => $ch, 'hostname' => $system['hostname']];
            }

            // Execute all requests in parallel
            executeCurlMulti($mh);

            // Collect results and check for missing sequences
            foreach ($handles as $address => $info) {
                $ch = $info['handle'];
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                cleanupCurlHandle($mh, $ch);

                if ($httpCode !== 200 || !$response) {
                    continue; // Skip offline/unreachable remotes
                }

                $remoteSequences = json_decode($response, true);
                if (!is_array($remoteSequences)) {
                    continue;
                }

                // Build set of remote sequence filenames
                $remoteSeqSet = [];
                foreach ($remoteSequences as $seq) {
                    $name = is_array($seq) ? ($seq['Name'] ?? '') : $seq;
                    if (!empty($name)) {
                        $remoteSeqSet[$name] = true;
                    }
                }

                // Find sequences on player that are missing from this remote
                $missingSequences = [];
                foreach ($localSeqSet as $seqName => $_) {
                    if (!isset($remoteSeqSet[$seqName])) {
                        $missingSequences[] = $seqName;
                    }
                }

                if (count($missingSequences) > 0) {
                    $hostname = $info['hostname'] ?: $address;
                    $count = count($missingSequences);
                    $discrepancies[] = [
                        'type' => 'missing_sequences',
                        'severity' => 'warning',
                        'address' => $address,
                        'hostname' => $hostname,
                        'sequences' => $missingSequences,
                        'message' => "{$count} sequence" . ($count > 1 ? 's' : '') . " missing from {$hostname}"
                    ];
                }
            }
            curl_multi_close($mh);
        }
    }

    $result = [
        'success' => true,
        'discrepancies' => $discrepancies,
        'remoteCount' => count($remoteSystems)
    ];

    // Cache the result
    file_put_contents($cacheFile, json_encode($result));
    ensureFppOwnership($cacheFile);

    return $result;
}

/**
 * Bulk fetch status data from all remote hosts in parallel
 * Consolidates: fppd/status, system/status, connectivity/state
 *
 * @return array Result with host data keyed by address
 */
function getBulkRemoteStatus() {
    $remoteSystems = getMultiSyncRemoteSystems();
    if (empty($remoteSystems)) {
        return ['success' => true, 'hosts' => []];
    }

    $mh = curl_multi_init();
    $handles = [];

    // Create handles for each host and each endpoint
    foreach ($remoteSystems as $system) {
        $address = $system['address'];
        $hostname = $system['hostname'];

        $endpoints = [
            'fppd' => "http://{$address}/api/fppd/status",
            'sysStatus' => "http://{$address}/api/system/status",
            'connectivity' => "http://{$address}/api/plugin/fpp-plugin-watcher/connectivity/state",
            'testmode' => "http://{$address}/api/testmode"
        ];

        foreach ($endpoints as $key => $url) {
            $ch = createCurlHandle($url, WATCHER_TIMEOUT_STANDARD);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [
                'handle' => $ch,
                'address' => $address,
                'hostname' => $hostname,
                'endpoint' => $key
            ];
        }
    }

    // Execute all requests in parallel
    executeCurlMulti($mh);

    // Collect results grouped by host
    $hostData = [];
    foreach ($handles as $info) {
        $ch = $info['handle'];
        $address = $info['address'];
        $endpoint = $info['endpoint'];

        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        cleanupCurlHandle($mh, $ch);

        // Initialize host entry if needed
        if (!isset($hostData[$address])) {
            $hostData[$address] = [
                'success' => true,
                'hostname' => $info['hostname'],
                'status' => null,
                'testMode' => null,
                'sysStatus' => null,
                'connectivity' => null
            ];
        }

        // Parse response based on endpoint type
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                switch ($endpoint) {
                    case 'fppd':
                        $hostData[$address]['status'] = extractRemoteStatusFields($data);
                        break;

                    case 'sysStatus':
                        $hostData[$address]['sysStatus'] = $data;
                        break;

                    case 'connectivity':
                        $hostData[$address]['connectivity'] = $data;
                        break;

                    case 'testmode':
                        $hostData[$address]['testMode'] = [
                            'enabled' => !empty($data['enabled']) ? 1 : 0
                        ];
                        break;
                }
            }
        } else {
            // Mark as failed if fppd endpoint fails (primary indicator of host being offline)
            if ($endpoint === 'fppd') {
                $hostData[$address]['success'] = false;
                $hostData[$address]['error'] = 'Connection failed';
            }
        }
    }

    curl_multi_close($mh);

    return ['success' => true, 'hosts' => $hostData];
}

/**
 * Bulk fetch update data from all remote hosts in parallel
 * Consolidates: watcher version, plugin updates
 *
 * @return array Result with host data keyed by address
 */
function getBulkRemoteUpdates() {
    $remoteSystems = getMultiSyncRemoteSystems();
    if (empty($remoteSystems)) {
        return ['success' => true, 'hosts' => []];
    }

    // Get latest Watcher version from GitHub once for all comparisons
    $latestWatcherVersion = getLatestWatcherVersion();

    $mh = curl_multi_init();
    $handles = [];

    // Create handles for each host - version and plugin list
    foreach ($remoteSystems as $system) {
        $address = $system['address'];
        $hostname = $system['hostname'];

        $endpoints = [
            'version' => "http://{$address}/api/plugin/fpp-plugin-watcher/version",
            'plugins' => "http://{$address}/api/plugin"
        ];

        foreach ($endpoints as $key => $url) {
            $ch = createCurlHandle($url, WATCHER_TIMEOUT_STANDARD);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [
                'handle' => $ch,
                'address' => $address,
                'hostname' => $hostname,
                'endpoint' => $key
            ];
        }
    }

    // Execute all requests in parallel
    executeCurlMulti($mh);

    // Collect results grouped by host
    $hostData = [];
    $pluginLists = []; // Temporary storage for plugin lists

    foreach ($handles as $info) {
        $ch = $info['handle'];
        $address = $info['address'];
        $endpoint = $info['endpoint'];

        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        cleanupCurlHandle($mh, $ch);

        // Initialize host entry if needed
        if (!isset($hostData[$address])) {
            $hostData[$address] = [
                'success' => true,
                'hostname' => $info['hostname'],
                'version' => null,
                'updates' => []
            ];
        }

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                switch ($endpoint) {
                    case 'version':
                        $hostData[$address]['version'] = $data['version'] ?? null;
                        break;

                    case 'plugins':
                        // Store plugin list for second phase
                        if (is_array($data)) {
                            $pluginLists[$address] = $data;
                        }
                        break;
                }
            }
        } else {
            if ($endpoint === 'version') {
                $hostData[$address]['success'] = false;
                $hostData[$address]['error'] = 'Connection failed';
            }
        }
    }

    curl_multi_close($mh);

    // Second phase: fetch plugin details to check for updates
    if (!empty($pluginLists)) {
        $mh2 = curl_multi_init();
        $handles2 = [];

        foreach ($pluginLists as $address => $pluginNames) {
            foreach ($pluginNames as $repoName) {
                if (!is_string($repoName)) continue;
                $ch = curl_init("http://{$address}/api/plugin/{$repoName}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                curl_multi_add_handle($mh2, $ch);
                $handles2[] = [
                    'handle' => $ch,
                    'address' => $address,
                    'repoName' => $repoName
                ];
            }
        }

        // Execute plugin detail requests
        do {
            $status = curl_multi_exec($mh2, $active);
            if ($active) curl_multi_select($mh2);
        } while ($active && $status === CURLM_OK);

        // Process plugin details
        foreach ($handles2 as $info) {
            $ch = $info['handle'];
            $address = $info['address'];
            $repoName = $info['repoName'];

            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh2, $ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $pluginInfo = json_decode($response, true);
                if ($pluginInfo && is_array($pluginInfo)) {
                    $updateInfo = checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion);
                    if ($updateInfo) {
                        $hostData[$address]['updates'][] = $updateInfo;
                    }
                }
            }
        }

        curl_multi_close($mh2);
    }

    return ['success' => true, 'hosts' => $hostData];
}
?>
