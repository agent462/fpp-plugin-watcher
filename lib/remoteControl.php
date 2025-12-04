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
 * Send a simple GET action to a remote FPP instance
 *
 * @param string $host The remote host
 * @param string $endpoint The API endpoint path
 * @param string $message Success message to return
 * @return array Result with success status
 */
function sendSimpleRemoteAction($host, $endpoint, $message) {
    if (!validateHost($host)) {
        return [
            'success' => false,
            'error' => 'Invalid host format'
        ];
    }

    $url = "http://{$host}{$endpoint}";
    apiCall('GET', $url, [], true, 10);

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
 * Check for configuration issues between player and remote systems
 * Includes output discrepancies and missing sequences
 * Results are cached for 60 seconds to reduce API calls
 * Respects issueCheckOutputs and issueCheckSequences config settings
 */
function getOutputDiscrepancies() {
    include_once __DIR__ . '/config.php';

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
        $outputsData = apiCall('GET', 'http://127.0.0.1/api/channel/output/universeOutputs', [], true, 5);

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
        $localSequences = apiCall('GET', 'http://127.0.0.1/api/sequence', [], true, 5);

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
            $timeout = 3;

            foreach ($remoteSystems as $system) {
                $ch = curl_init("http://{$system['address']}/api/sequence");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => ['Accept: application/json']
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$system['address']] = ['handle' => $ch, 'hostname' => $system['hostname']];
            }

            // Execute all requests in parallel
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh);
            } while ($active && $status === CURLM_OK);

            // Collect results and check for missing sequences
            foreach ($handles as $address => $info) {
                $ch = $info['handle'];
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

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
?>
