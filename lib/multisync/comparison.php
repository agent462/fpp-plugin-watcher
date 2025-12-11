<?php
/**
 * multiSyncComparison.php - Player vs Remote sync comparison
 *
 * Collects sync metrics from watcher C++ plugin on player and remotes,
 * compares states, and detects synchronization issues.
 */

include_once __DIR__ . '/../core/watcherCommon.php';
include_once __DIR__ . '/syncStatus.php';

// Issue severity levels
define('ISSUE_SEVERITY_INFO', 1);
define('ISSUE_SEVERITY_WARNING', 2);
define('ISSUE_SEVERITY_CRITICAL', 3);

// Default thresholds (can be made configurable later)
define('DRIFT_WARNING_THRESHOLD', 5);      // frames
define('DRIFT_CRITICAL_THRESHOLD', 10);    // frames
define('TIME_OFFSET_WARNING', 0.5);        // seconds
define('TIME_OFFSET_CRITICAL', 1.0);       // seconds

/**
 * Fetch sync metrics from a remote host's watcher plugin
 *
 * @param string $address Remote host IP address
 * @param int $timeout Request timeout in seconds
 * @return array|null Metrics data or null on failure
 */
function fetchRemoteSyncMetrics($address, $timeout = WATCHER_TIMEOUT_STANDARD) {
    $url = "http://{$address}/api/plugin-apis/fpp-plugin-watcher/multisync/status";
    return fetchJsonUrl($url, $timeout);
}

/**
 * Fetch FPP status from a remote host
 *
 * @param string $address Remote host IP address
 * @param int $timeout Request timeout in seconds
 * @return array|null Status data or null on failure
 */
function fetchRemoteFppStatus($address, $timeout = WATCHER_TIMEOUT_STATUS) {
    $url = "http://{$address}/api/fppd/status";
    return fetchJsonUrl($url, $timeout);
}

/**
 * Collect sync metrics from all remote systems in parallel
 * Uses the combined full-status endpoint to reduce HTTP calls (1 per remote instead of 2)
 *
 * @param array $remoteSystems Array of remote system info from getMultiSyncRemoteSystems()
 * @param int $timeout Request timeout per host
 * @return array Array of results keyed by address
 */
function collectRemoteSyncMetrics($remoteSystems, $timeout = WATCHER_TIMEOUT_STANDARD) {
    if (empty($remoteSystems)) {
        return [];
    }

    $mh = curl_multi_init();
    $handles = [];

    // Create curl handles for each remote - use combined endpoint for efficiency
    foreach ($remoteSystems as $system) {
        $address = $system['address'];
        $hostname = $system['hostname'];

        // Try the combined endpoint first (available on systems with watcher plugin)
        $ch = createCurlHandle("http://{$address}/api/plugin/fpp-plugin-watcher/multisync/full-status", $timeout);
        curl_multi_add_handle($mh, $ch);
        $handles[$address] = [
            'handle' => $ch,
            'address' => $address,
            'hostname' => $hostname
        ];
    }

    // Execute all requests in parallel
    executeCurlMulti($mh);

    // Collect results
    $combinedResults = [];
    $needsFallback = [];

    foreach ($handles as $address => $info) {
        $ch = $info['handle'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        cleanupCurlHandle($mh, $ch);

        $combinedResults[$address] = [
            'httpCode' => $httpCode,
            'response' => $response,
            'responseTime' => round($responseTime * 1000, 1),
            'hostname' => $info['hostname']
        ];

        // If combined endpoint failed (404 = no watcher plugin), mark for fallback
        if ($httpCode === 404 || $httpCode === 0) {
            $needsFallback[$address] = $info['hostname'];
        }
    }

    curl_multi_close($mh);

    // Fallback: fetch FPP status directly for systems without watcher plugin
    if (!empty($needsFallback)) {
        $mh2 = curl_multi_init();
        $fallbackHandles = [];

        foreach ($needsFallback as $address => $hostname) {
            $ch = curl_init("http://{$address}/api/fppd/status");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            curl_multi_add_handle($mh2, $ch);
            $fallbackHandles[$address] = $ch;
        }

        do {
            $status = curl_multi_exec($mh2, $active);
            if ($active) {
                curl_multi_select($mh2);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($fallbackHandles as $address => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh2, $ch);
            curl_close($ch);

            // Store fallback FPP response
            $combinedResults[$address]['fallbackFpp'] = [
                'httpCode' => $httpCode,
                'response' => $response
            ];
        }

        curl_multi_close($mh2);
    }

    // Process all results
    $results = [];
    foreach ($combinedResults as $address => $data) {
        $result = [
            'address' => $address,
            'hostname' => $data['hostname'],
            'responseTime' => $data['responseTime'],
            'pluginInstalled' => false,
            'online' => false,
            'metrics' => null,
            'fppStatus' => null,
            'error' => null
        ];

        // Process combined endpoint response
        if ($data['httpCode'] === 200 && $data['response']) {
            $combined = json_decode($data['response'], true);
            if ($combined && isset($combined['success']) && $combined['success']) {
                $result['online'] = true;
                $result['pluginInstalled'] = $combined['watcherLoaded'] ?? false;

                // Extract watcher metrics
                if (isset($combined['watcher']) && !isset($combined['watcher']['error'])) {
                    $result['metrics'] = $combined['watcher'];
                }

                // Extract FPP status
                if (isset($combined['fpp'])) {
                    $result['fppStatus'] = $combined['fpp'];
                }
            } else {
                $result['error'] = $combined['error'] ?? 'Invalid response';
            }
        } elseif ($data['httpCode'] === 404) {
            // No watcher plugin - check fallback
            $result['error'] = 'Watcher plugin not installed';

            // Process fallback FPP status
            $fallback = $data['fallbackFpp'] ?? null;
            if ($fallback && $fallback['httpCode'] === 200 && $fallback['response']) {
                $fppData = json_decode($fallback['response'], true);
                if ($fppData) {
                    $result['online'] = true;
                    $result['fppStatus'] = [
                        'status' => $fppData['status_name'] ?? 'unknown',
                        'sequence' => $fppData['current_sequence'] ?? '',
                        'secondsPlayed' => floatval($fppData['seconds_played'] ?? 0),
                        'secondsRemaining' => floatval($fppData['seconds_remaining'] ?? 0)
                    ];
                }
            }
        } elseif ($data['httpCode'] === 0) {
            // Connection failed - check fallback for online status
            $fallback = $data['fallbackFpp'] ?? null;
            if ($fallback && $fallback['httpCode'] === 200) {
                $fppData = json_decode($fallback['response'], true);
                if ($fppData) {
                    $result['online'] = true;
                    $result['fppStatus'] = [
                        'status' => $fppData['status_name'] ?? 'unknown',
                        'sequence' => $fppData['current_sequence'] ?? '',
                        'secondsPlayed' => floatval($fppData['seconds_played'] ?? 0),
                        'secondsRemaining' => floatval($fppData['seconds_remaining'] ?? 0)
                    ];
                }
            } else {
                $result['error'] = 'Connection failed';
            }
        } else {
            $result['error'] = "HTTP {$data['httpCode']}";
        }

        $results[$address] = $result;
    }

    return $results;
}

/**
 * Compare player status with a remote's status
 *
 * @param array $player Player metrics/status
 * @param array $remote Remote metrics/status
 * @return array Array of detected issues
 */
function comparePlayerToRemote($player, $remote) {
    $issues = [];
    $hostname = $remote['hostname'] ?? $remote['address'];

    // Check if remote is online
    if (!$remote['online']) {
        $issues[] = [
            'type' => 'offline',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => 'Remote is offline or unreachable'
        ];
        return $issues;
    }

    // Check if plugin is installed
    if (!$remote['pluginInstalled']) {
        $issues[] = [
            'type' => 'no_plugin',
            'severity' => ISSUE_SEVERITY_INFO,
            'host' => $hostname,
            'description' => 'Watcher plugin not installed on remote'
        ];
        return $issues;
    }

    $remoteMetrics = $remote['metrics'] ?? [];
    $playerMetrics = $player['metrics'] ?? [];
    $remoteFppStatus = $remote['fppStatus'] ?? null;
    $playerFppStatus = $player['fppStatus'] ?? null;

    // Get player's current sequence from FPP status (what's actually playing)
    $playerSeq = $playerFppStatus['sequence'] ?? ($playerMetrics['currentMasterSequence'] ?? '');
    // Get what sync packets told the remote to play
    $remoteSyncSeq = $remoteMetrics['currentMasterSequence'] ?? '';
    // Get what the remote is actually playing according to FPP
    $remoteActualSeq = $remoteFppStatus['sequence'] ?? '';
    $remoteActualStatus = $remoteFppStatus['status'] ?? '';

    // Player playing state
    $playerPlaying = $playerMetrics['sequencePlaying'] ?? false;
    // What sync packets say remote should be doing
    $remoteSyncPlaying = $remoteMetrics['sequencePlaying'] ?? false;
    // What remote is actually doing according to FPP
    $remoteActuallyPlaying = ($remoteActualStatus === 'playing');

    // Check for missing sequence - player playing, remote got sync packets, but remote isn't actually playing
    if ($playerPlaying && $remoteSyncPlaying && !$remoteActuallyPlaying && $remoteFppStatus !== null) {
        $issues[] = [
            'type' => 'missing_sequence',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => "Missing sequence file: {$remoteSyncSeq}",
            'expected' => $playerSeq,
            'actual' => $remoteActualSeq ?: '(not playing)'
        ];
        // Don't add other issues since this is the root cause
        return $issues;
    }

    // Compare what's actually playing (using FPP status when available)
    if ($playerPlaying && !empty($playerSeq) && !empty($remoteActualSeq) && $playerSeq !== $remoteActualSeq) {
        $issues[] = [
            'type' => 'sequence_mismatch',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => "Playing different sequence",
            'expected' => $playerSeq,
            'actual' => $remoteActualSeq
        ];
    }

    // Compare playback state using actual FPP status
    $effectiveRemotePlaying = $remoteFppStatus !== null ? $remoteActuallyPlaying : $remoteSyncPlaying;

    if ($playerPlaying !== $effectiveRemotePlaying) {
        $issues[] = [
            'type' => 'state_mismatch',
            'severity' => ISSUE_SEVERITY_WARNING,
            'host' => $hostname,
            'description' => $playerPlaying ? 'Remote not playing' : 'Remote playing but player idle',
            'expected' => $playerPlaying ? 'playing' : 'stopped',
            'actual' => $effectiveRemotePlaying ? 'playing' : 'stopped'
        ];
    }

    // Check frame drift (only meaningful on remotes) - use avg drift for alerting
    $maxDrift = abs($remoteMetrics['maxFrameDrift'] ?? 0);
    $avgDrift = abs($remoteMetrics['avgFrameDrift'] ?? 0);
    $avgDriftRounded = round($avgDrift, 1);

    if ($avgDrift > DRIFT_CRITICAL_THRESHOLD) {
        $issues[] = [
            'type' => 'sync_drift',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => "High average frame drift: {$avgDriftRounded} frames",
            'maxDrift' => $maxDrift,
            'avgDrift' => $avgDriftRounded
        ];
    } elseif ($avgDrift > DRIFT_WARNING_THRESHOLD) {
        $issues[] = [
            'type' => 'sync_drift',
            'severity' => ISSUE_SEVERITY_WARNING,
            'host' => $hostname,
            'description' => "Average frame drift: {$avgDriftRounded} frames",
            'maxDrift' => $maxDrift,
            'avgDrift' => $avgDriftRounded
        ];
    }

    // Check time since last sync (remote should be receiving packets)
    $secondsSinceSync = $remoteMetrics['secondsSinceLastSync'] ?? -1;
    if ($secondsSinceSync > 30 && $playerPlaying) {
        $issues[] = [
            'type' => 'no_sync_packets',
            'severity' => ISSUE_SEVERITY_WARNING,
            'host' => $hostname,
            'description' => "No sync packets received for {$secondsSinceSync}s",
            'secondsSinceSync' => $secondsSinceSync
        ];
    }

    return $issues;
}

/**
 * Get comprehensive sync comparison data
 *
 * Fetches metrics from player and all remotes, compares, and returns
 * aggregated results with issues.
 *
 * @return array Comparison results
 */
function getSyncComparison() {
    $startTime = microtime(true);

    // Get local/player metrics
    $localMetrics = getMultiSyncStatus();
    $localFppStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, WATCHER_TIMEOUT_STATUS);

    $player = [
        'hostname' => $localFppStatus['host_name'] ?? 'Local',
        'mode' => $localFppStatus['mode_name'] ?? 'unknown',
        'online' => true,
        'pluginInstalled' => !isset($localMetrics['error']),
        'metrics' => $localMetrics,
        'fppStatus' => [
            'status' => $localFppStatus['status_name'] ?? 'unknown',
            'sequence' => $localFppStatus['current_sequence'] ?? '',
            'currentFrame' => intval($localFppStatus['current_frame'] ?? 0),
            'secondsPlayed' => floatval($localFppStatus['seconds_played'] ?? 0)
        ]
    ];

    // Get remote systems
    $remoteSystems = getMultiSyncRemoteSystems();

    // Collect metrics from all remotes in parallel
    $remoteResults = collectRemoteSyncMetrics($remoteSystems);

    // Compare player to each remote and collect issues
    $allIssues = [];
    $remotes = [];

    foreach ($remoteResults as $address => $remote) {
        $issues = comparePlayerToRemote($player, $remote);
        $allIssues = array_merge($allIssues, $issues);

        // Add summary info
        $remote['issues'] = $issues;
        $remote['issueCount'] = count($issues);
        $remote['hasIssues'] = count($issues) > 0;
        $remote['maxSeverity'] = 0;
        foreach ($issues as $issue) {
            $remote['maxSeverity'] = max($remote['maxSeverity'], $issue['severity']);
        }

        $remotes[] = $remote;
    }

    // Sort remotes by IP
    usort($remotes, function($a, $b) {
        return ip2long($a['address'] ?? '0.0.0.0') - ip2long($b['address'] ?? '0.0.0.0');
    });

    // Calculate overall health
    $healthStatus = 'healthy';
    $maxSeverity = 0;
    foreach ($allIssues as $issue) {
        $maxSeverity = max($maxSeverity, $issue['severity']);
    }

    if ($maxSeverity >= ISSUE_SEVERITY_CRITICAL) {
        $healthStatus = 'critical';
    } elseif ($maxSeverity >= ISSUE_SEVERITY_WARNING) {
        $healthStatus = 'warning';
    }

    // Count stats
    $onlineCount = 0;
    $pluginInstalledCount = 0;
    foreach ($remotes as $r) {
        if ($r['online']) $onlineCount++;
        if ($r['pluginInstalled']) $pluginInstalledCount++;
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 1);

    return [
        'success' => true,
        'timestamp' => time(),
        'elapsed_ms' => $elapsed,
        'player' => $player,
        'remotes' => $remotes,
        'issues' => $allIssues,
        'summary' => [
            'healthStatus' => $healthStatus,
            'totalRemotes' => count($remotes),
            'onlineCount' => $onlineCount,
            'pluginInstalledCount' => $pluginInstalledCount,
            'issueCount' => count($allIssues),
            'criticalCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === ISSUE_SEVERITY_CRITICAL)),
            'warningCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === ISSUE_SEVERITY_WARNING)),
            'infoCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === ISSUE_SEVERITY_INFO))
        ]
    ];
}

/**
 * Get comparison data for a specific remote
 *
 * @param string $address Remote IP address
 * @return array Comparison result for single remote
 */
function getSyncComparisonForHost($address) {
    // Get local/player metrics
    $localMetrics = getMultiSyncStatus();
    $localFppStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, WATCHER_TIMEOUT_STATUS);

    $player = [
        'hostname' => $localFppStatus['host_name'] ?? 'Local',
        'mode' => $localFppStatus['mode_name'] ?? 'unknown',
        'online' => true,
        'pluginInstalled' => !isset($localMetrics['error']),
        'metrics' => $localMetrics
    ];

    // Fetch remote metrics
    $remoteMetrics = fetchRemoteSyncMetrics($address);
    $remoteFppStatus = fetchRemoteFppStatus($address);

    $remote = [
        'address' => $address,
        'hostname' => $remoteFppStatus['host_name'] ?? $address,
        'online' => $remoteMetrics !== null || $remoteFppStatus !== null,
        'pluginInstalled' => $remoteMetrics !== null && !isset($remoteMetrics['error']),
        'metrics' => $remoteMetrics,
        'fppStatus' => $remoteFppStatus ? [
            'status' => $remoteFppStatus['status_name'] ?? 'unknown',
            'sequence' => $remoteFppStatus['current_sequence'] ?? '',
            'secondsPlayed' => floatval($remoteFppStatus['seconds_played'] ?? 0)
        ] : null
    ];

    $issues = comparePlayerToRemote($player, $remote);

    return [
        'success' => true,
        'timestamp' => time(),
        'player' => $player,
        'remote' => $remote,
        'issues' => $issues,
        'issueCount' => count($issues)
    ];
}
