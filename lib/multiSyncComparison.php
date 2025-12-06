<?php
/**
 * multiSyncComparison.php - Player vs Remote sync comparison
 *
 * Collects sync metrics from watcher C++ plugin on player and remotes,
 * compares states, and detects synchronization issues.
 */

include_once __DIR__ . '/watcherCommon.php';
include_once __DIR__ . '/multiSyncMetrics.php';

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
function fetchRemoteSyncMetrics($address, $timeout = 3) {
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
function fetchRemoteFppStatus($address, $timeout = 2) {
    $url = "http://{$address}/api/fppd/status";
    return fetchJsonUrl($url, $timeout);
}

/**
 * Collect sync metrics from all remote systems in parallel
 *
 * @param array $remoteSystems Array of remote system info from getMultiSyncRemoteSystems()
 * @param int $timeout Request timeout per host
 * @return array Array of results keyed by address
 */
function collectRemoteSyncMetrics($remoteSystems, $timeout = 3) {
    if (empty($remoteSystems)) {
        return [];
    }

    $mh = curl_multi_init();
    $handles = [];

    // Create curl handles for each remote
    foreach ($remoteSystems as $system) {
        $address = $system['address'];
        $hostname = $system['hostname'];

        // Fetch watcher plugin metrics
        $ch = curl_init("http://{$address}/api/plugin-apis/fpp-plugin-watcher/multisync/status");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$address] = [
            'handle' => $ch,
            'hostname' => $hostname,
            'type' => 'watcher'
        ];
    }

    // Execute all requests in parallel
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    // Collect results
    $results = [];
    foreach ($handles as $address => $info) {
        $ch = $info['handle'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $result = [
            'address' => $address,
            'hostname' => $info['hostname'],
            'responseTime' => round($responseTime * 1000, 1), // ms
            'pluginInstalled' => false,
            'online' => false,
            'metrics' => null,
            'error' => null
        ];

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && !isset($data['error'])) {
                $result['online'] = true;
                $result['pluginInstalled'] = true;
                $result['metrics'] = $data;
            } else {
                $result['error'] = $data['error'] ?? 'Invalid response';
            }
        } elseif ($httpCode === 404) {
            // Plugin not installed on this remote
            $result['online'] = true; // Host is up, just no plugin
            $result['error'] = 'Watcher plugin not installed';
        } else {
            $result['error'] = $httpCode > 0 ? "HTTP $httpCode" : 'Connection failed';
        }

        $results[$address] = $result;
    }

    curl_multi_close($mh);
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

    // Compare sequence names
    $playerSeq = $playerMetrics['currentMasterSequence'] ?? '';
    $remoteSeq = $remoteMetrics['currentMasterSequence'] ?? '';

    if (!empty($playerSeq) && !empty($remoteSeq) && $playerSeq !== $remoteSeq) {
        $issues[] = [
            'type' => 'sequence_mismatch',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => "Playing different sequence",
            'expected' => $playerSeq,
            'actual' => $remoteSeq
        ];
    }

    // Compare playback state
    $playerPlaying = $playerMetrics['sequencePlaying'] ?? false;
    $remotePlaying = $remoteMetrics['sequencePlaying'] ?? false;

    if ($playerPlaying !== $remotePlaying) {
        $issues[] = [
            'type' => 'state_mismatch',
            'severity' => ISSUE_SEVERITY_WARNING,
            'host' => $hostname,
            'description' => $playerPlaying ? 'Remote not playing' : 'Remote playing but player idle',
            'expected' => $playerPlaying ? 'playing' : 'stopped',
            'actual' => $remotePlaying ? 'playing' : 'stopped'
        ];
    }

    // Check frame drift (only meaningful on remotes)
    $maxDrift = abs($remoteMetrics['maxFrameDrift'] ?? 0);
    $avgDrift = abs($remoteMetrics['avgFrameDrift'] ?? 0);

    if ($maxDrift > DRIFT_CRITICAL_THRESHOLD) {
        $issues[] = [
            'type' => 'sync_drift',
            'severity' => ISSUE_SEVERITY_CRITICAL,
            'host' => $hostname,
            'description' => "High frame drift: {$maxDrift} frames",
            'maxDrift' => $maxDrift,
            'avgDrift' => round($avgDrift, 1)
        ];
    } elseif ($maxDrift > DRIFT_WARNING_THRESHOLD) {
        $issues[] = [
            'type' => 'sync_drift',
            'severity' => ISSUE_SEVERITY_WARNING,
            'host' => $hostname,
            'description' => "Frame drift: {$maxDrift} frames",
            'maxDrift' => $maxDrift,
            'avgDrift' => round($avgDrift, 1)
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
    $localFppStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 2);

    $player = [
        'hostname' => $localFppStatus['host_name'] ?? 'Local',
        'mode' => $localFppStatus['mode_name'] ?? 'unknown',
        'online' => true,
        'pluginInstalled' => !isset($localMetrics['error']),
        'metrics' => $localMetrics,
        'fppStatus' => [
            'status' => $localFppStatus['status_name'] ?? 'unknown',
            'sequence' => $localFppStatus['current_sequence'] ?? '',
            'secondsPlayed' => floatval($localFppStatus['seconds_played'] ?? 0)
        ]
    ];

    // Get remote systems
    $remoteSystems = getMultiSyncRemoteSystems();

    // Collect metrics from all remotes in parallel
    $remoteResults = collectRemoteSyncMetrics($remoteSystems, 3);

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
    $localFppStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 2);

    $player = [
        'hostname' => $localFppStatus['host_name'] ?? 'Local',
        'mode' => $localFppStatus['mode_name'] ?? 'unknown',
        'online' => true,
        'pluginInstalled' => !isset($localMetrics['error']),
        'metrics' => $localMetrics
    ];

    // Fetch remote metrics
    $remoteMetrics = fetchRemoteSyncMetrics($address, 3);
    $remoteFppStatus = fetchRemoteFppStatus($address, 2);

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

/**
 * Get severity class name for CSS styling
 *
 * @param int $severity Severity level
 * @return string CSS class name
 */
function getComparisonSeverityClass($severity) {
    switch ($severity) {
        case ISSUE_SEVERITY_CRITICAL:
            return 'watcher-severity-critical';
        case ISSUE_SEVERITY_WARNING:
            return 'watcher-severity-warning';
        case ISSUE_SEVERITY_INFO:
            return 'watcher-severity-info';
        default:
            return '';
    }
}

/**
 * Get health status class for CSS styling
 *
 * @param string $status Health status (healthy, warning, critical)
 * @return string CSS class name
 */
function getHealthStatusClass($status) {
    switch ($status) {
        case 'critical':
            return 'watcher-health-critical';
        case 'warning':
            return 'watcher-health-warning';
        case 'healthy':
            return 'watcher-health-healthy';
        default:
            return '';
    }
}
