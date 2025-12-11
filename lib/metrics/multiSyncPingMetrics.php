<?php
/**
 * Multi-Sync Ping Metrics Collection and Rollup
 *
 * This module handles pinging remote multi-sync systems and storing
 * metrics in a similar format to the connectivity check ping metrics.
 * Metrics are stored per-host for historical analysis.
 */

include_once __DIR__ . "/rollupBase.php";

// Define rollup file paths (using centralized data directory)
define("WATCHERMULTISYNCROLLUPDIR", WATCHERMULTISYNCPINGDIR);
define("WATCHERMULTISYNCROLLUPSTATEFILE", WATCHERMULTISYNCPINGDIR . "/rollup-state.json");

// Retention period for raw multi-sync metrics (25 hours)
define("WATCHERMULTISYNCMETRICSRETENTIONSECONDS", 25 * 60 * 60);

// Jitter state storage (per-host previous latency for RFC 3550 calculation)
$_multiSyncJitterState = [];

/**
 * Get rollup file path for a specific tier
 */
function getMultiSyncRollupFilePath($tier) {
    return WATCHERMULTISYNCPINGDIR . "/{$tier}.log";
}

/**
 * Calculate jitter using RFC 3550 algorithm
 * Delegates to shared function in rollupBase.php
 *
 * @param string $hostname Host identifier
 * @param float $latency Current latency measurement in ms
 * @return float|null Calculated jitter or null if first sample
 */
function calculateMultiSyncJitter($hostname, $latency) {
    global $_multiSyncJitterState;
    return calculateJitterRFC3550Generic($hostname, $latency, $_multiSyncJitterState);
}

/**
 * Ping a single remote host and return results
 * Uses 2 second timeout for remote hosts which may have higher latency
 *
 * @param string $address IP address or hostname to ping
 * @param string $networkAdapter Network interface to use for ping
 * @return array Ping result with latency and status
 */
function pingRemoteHost($address, $networkAdapter) {
    return pingHost($address, $networkAdapter, 2);
}

/**
 * Ping all multi-sync remote systems and collect metrics
 *
 * @param array $remoteSystems Array of remote systems from getMultiSyncRemoteSystems()
 * @param string $networkAdapter Network interface to use for pings
 * @return array Results keyed by hostname
 */
function pingMultiSyncSystems($remoteSystems, $networkAdapter) {
    $checkTimestamp = time();
    $results = [];
    $metricsBuffer = [];

    foreach ($remoteSystems as $system) {
        $hostname = $system['hostname'] ?? '';
        $address = $system['address'] ?? '';

        if (empty($address)) {
            continue;
        }

        $pingResult = pingRemoteHost($address, $networkAdapter);

        // Calculate jitter if we have a valid latency
        $jitter = null;
        if ($pingResult['success'] && $pingResult['latency'] !== null) {
            $jitter = calculateMultiSyncJitter($hostname, $pingResult['latency']);
        }

        $results[$hostname] = [
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $pingResult['latency'],
            'jitter' => $jitter,
            'success' => $pingResult['success']
        ];

        $metricsBuffer[] = [
            'timestamp' => $checkTimestamp,
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $pingResult['latency'],
            'jitter' => $jitter,
            'status' => $pingResult['success'] ? 'success' : 'failure'
        ];
    }

    writeMultiSyncMetricsBatch($metricsBuffer);

    return $results;
}

/**
 * Write multiple metrics entries in a single file operation
 * Delegates to shared function in rollupBase.php
 */
function writeMultiSyncMetricsBatch($entries) {
    return writeMetricsBatchGeneric(WATCHERMULTISYNCPINGMETRICSFILE, $entries);
}

/**
 * Rotate multi-sync metrics file to remove old entries
 * Delegates to shared function in rollupBase.php
 */
function rotateMultiSyncMetricsFile() {
    rotateRawMetricsFileGeneric(WATCHERMULTISYNCPINGMETRICSFILE, WATCHERMULTISYNCMETRICSRETENTIONSECONDS);
}

/**
 * Get or initialize multi-sync rollup state
 */
function getMultiSyncRollupState() {
    return getRollupStateGeneric(WATCHERMULTISYNCROLLUPSTATEFILE, WATCHER_ROLLUP_TIERS);
}

/**
 * Save multi-sync rollup state to disk
 */
function saveMultiSyncRollupState($state) {
    return saveRollupStateGeneric(WATCHERMULTISYNCROLLUPSTATEFILE, $state);
}

/**
 * Read raw multi-sync ping metrics from the metrics file
 */
function readRawMultiSyncMetrics($sinceTimestamp = 0) {
    return readMetricsFileGeneric(WATCHERMULTISYNCPINGMETRICSFILE, $sinceTimestamp);
}

/**
 * Aggregate multi-sync metrics for a time period, grouped by hostname
 */
function aggregateMultiSyncMetrics($metrics) {
    if (empty($metrics)) {
        return null;
    }

    // Group by hostname
    $byHost = [];
    foreach ($metrics as $entry) {
        $hostname = $entry['hostname'] ?? 'unknown';
        if (!isset($byHost[$hostname])) {
            $byHost[$hostname] = [
                'latencies' => [],
                'jitters' => [],
                'success_count' => 0,
                'failure_count' => 0,
                'address' => $entry['address'] ?? ''
            ];
        }

        if (isset($entry['latency']) && $entry['latency'] !== null) {
            $byHost[$hostname]['latencies'][] = floatval($entry['latency']);
        }

        if (isset($entry['jitter']) && $entry['jitter'] !== null) {
            $byHost[$hostname]['jitters'][] = floatval($entry['jitter']);
        }

        if (isset($entry['status']) && $entry['status'] === 'success') {
            $byHost[$hostname]['success_count']++;
        } else {
            $byHost[$hostname]['failure_count']++;
        }
    }

    $aggregated = [];
    foreach ($byHost as $hostname => $data) {
        $hostResult = [
            'hostname' => $hostname,
            'address' => $data['address'],
            'sample_count' => $data['success_count'] + $data['failure_count'],
            'success_count' => $data['success_count'],
            'failure_count' => $data['failure_count']
        ];

        if (!empty($data['latencies'])) {
            $hostResult['min_latency'] = round(min($data['latencies']), 3);
            $hostResult['max_latency'] = round(max($data['latencies']), 3);
            $hostResult['avg_latency'] = round(array_sum($data['latencies']) / count($data['latencies']), 3);
        } else {
            $hostResult['min_latency'] = null;
            $hostResult['max_latency'] = null;
            $hostResult['avg_latency'] = null;
        }

        // Jitter aggregation
        if (!empty($data['jitters'])) {
            $hostResult['avg_jitter'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
            $hostResult['max_jitter'] = round(max($data['jitters']), 2);
        } else {
            $hostResult['avg_jitter'] = null;
            $hostResult['max_jitter'] = null;
        }

        $aggregated[] = $hostResult;
    }

    return $aggregated;
}

/**
 * Aggregation function wrapper for generic rollup processing
 * Returns array of entries (one per host) instead of single entry
 */
function aggregateMultiSyncMetricsForRollup($bucketMetrics, $bucketStart, $interval) {
    $aggregated = aggregateMultiSyncMetrics($bucketMetrics);

    if ($aggregated === null || empty($aggregated)) {
        return null;
    }

    // Create one rollup entry per host for this time period
    $entries = [];
    foreach ($aggregated as $hostData) {
        $entries[] = array_merge([
            'timestamp' => $bucketStart,
            'period_start' => $bucketStart,
            'period_end' => $bucketStart + $interval
        ], $hostData);
    }

    return $entries;
}

/**
 * Process rollup for a specific tier
 */
function processMultiSyncRollupTier($tier, $tierConfig) {
    processRollupTierGeneric(
        $tier,
        $tierConfig,
        WATCHER_ROLLUP_TIERS,
        WATCHERMULTISYNCROLLUPSTATEFILE,
        WATCHERMULTISYNCPINGMETRICSFILE,
        'getMultiSyncRollupFilePath',
        'aggregateMultiSyncMetricsForRollup'
    );
}

/**
 * Process all multi-sync rollup tiers
 */
function processAllMultiSyncRollups() {
    foreach (WATCHER_ROLLUP_TIERS as $tier => $config) {
        try {
            processMultiSyncRollupTier($tier, $config);
        } catch (Exception $e) {
            logMessage("ERROR processing multi-sync rollup tier {$tier}: " . $e->getMessage());
        }
    }
}

/**
 * Read multi-sync rollup data from a specific tier
 */
function readMultiSyncRollupData($tier, $startTime = null, $endTime = null, $hostname = null) {
    $rollupFile = getMultiSyncRollupFilePath($tier);

    // Create hostname filter if specified
    $filterFn = null;
    if ($hostname !== null) {
        $filterFn = function($entry) use ($hostname) {
            return ($entry['hostname'] ?? '') === $hostname;
        };
    }

    $result = readRollupDataGeneric($rollupFile, $tier, WATCHER_ROLLUP_TIERS, $startTime, $endTime, $filterFn);

    // Sort by timestamp and hostname
    if ($result['success'] && !empty($result['data'])) {
        usort($result['data'], function($a, $b) {
            $timeDiff = $a['timestamp'] - $b['timestamp'];
            if ($timeDiff !== 0) return $timeDiff;
            return strcmp($a['hostname'] ?? '', $b['hostname'] ?? '');
        });
    }

    return $result;
}

/**
 * Get the best rollup tier for a given time range
 */
function getBestMultiSyncRollupTier($hoursBack) {
    return getBestTierForHours($hoursBack);
}

/**
 * Get multi-sync ping metrics with automatic tier selection
 */
function getMultiSyncPingMetrics($hoursBack = 24, $hostname = null) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $tier = getBestMultiSyncRollupTier($hoursBack);
    $tierConfig = WATCHER_ROLLUP_TIERS[$tier];

    $result = readMultiSyncRollupData($tier, $startTime, $endTime, $hostname);

    if ($result['success']) {
        $result['tier_info'] = [
            'tier' => $tier,
            'interval' => $tierConfig['interval'],
            'label' => $tierConfig['label']
        ];
    }

    return $result;
}

/**
 * Get raw multi-sync ping metrics with filtering
 *
 * @param int $hoursBack Number of hours to look back
 * @param string|null $hostname Optional hostname filter
 * @return array Result with success, count, data, and period info
 */
function getRawMultiSyncPingMetrics($hoursBack = 24, $hostname = null) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $metrics = readRawMultiSyncMetrics($startTime);

    // Filter by hostname if specified
    if ($hostname !== null) {
        $metrics = array_filter($metrics, function($m) use ($hostname) {
            return ($m['hostname'] ?? '') === $hostname;
        });
        $metrics = array_values($metrics);
    }

    // Filter to requested time range
    $metrics = array_filter($metrics, function($m) use ($startTime, $endTime) {
        return $m['timestamp'] >= $startTime && $m['timestamp'] <= $endTime;
    });

    $result = [
        'success' => true,
        'count' => count($metrics),
        'data' => array_values($metrics),
        'period' => [
            'start' => $startTime,
            'end' => $endTime,
            'hours' => $hoursBack
        ]
    ];

    if ($hostname !== null) {
        $result['hostname'] = $hostname;
    }

    return $result;
}

/**
 * Get information about available multi-sync rollup tiers
 *
 * @return array Tier info including interval, retention, and file status
 */
function getMultiSyncRollupTiersInfo() {
    return getTiersInfoGeneric(WATCHER_ROLLUP_TIERS, 'getMultiSyncRollupFilePath');
}

?>
