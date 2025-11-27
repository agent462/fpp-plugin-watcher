<?php
/**
 * Multi-Sync Ping Metrics Collection and Rollup
 *
 * This module handles pinging remote multi-sync systems and storing
 * metrics in a similar format to the connectivity check ping metrics.
 * Metrics are stored per-host for historical analysis.
 */

include_once __DIR__ . "/rollupBase.php";

// Define rollup tiers for multi-sync ping metrics (same structure as connectivity)
define("WATCHERMULTISYNCROLLUPTIERS", [
    '1min' => [
        'interval' => 60,           // 1 minute in seconds
        'retention' => 21600,       // Keep 6 hours (360 data points per host)
        'label' => '1-minute averages'
    ],
    '5min' => [
        'interval' => 300,          // 5 minutes in seconds
        'retention' => 172800,      // Keep 48 hours (576 data points per host)
        'label' => '5-minute averages'
    ],
    '30min' => [
        'interval' => 1800,         // 30 minutes in seconds
        'retention' => 1209600,     // Keep 14 days (672 data points per host)
        'label' => '30-minute averages'
    ],
    '2hour' => [
        'interval' => 7200,         // 2 hours in seconds
        'retention' => 7776000,     // Keep 90 days (1080 data points per host)
        'label' => '2-hour averages'
    ]
]);

// Define rollup file paths
define("WATCHERMULTISYNCROLLUPDIR", dirname(WATCHERMULTISYNCPINGMETRICSFILE));
define("WATCHERMULTISYNCROLLUPSTATEFILE", WATCHERMULTISYNCROLLUPDIR . "/fpp-plugin-watcher-multisync-rollup-state.json");

// Retention period for raw multi-sync metrics (25 hours)
define("WATCHERMULTISYNCMETRICSRETENTIONSECONDS", 25 * 60 * 60);

/**
 * Get rollup file path for a specific tier
 */
function getMultiSyncRollupFilePath($tier) {
    return WATCHERMULTISYNCROLLUPDIR . "/fpp-plugin-watcher-multisync-ping-{$tier}.log";
}

/**
 * Ping a single remote host and return results
 *
 * @param string $address IP address or hostname to ping
 * @param string $networkAdapter Network interface to use for ping
 * @return array Ping result with latency and status
 */
function pingRemoteHost($address, $networkAdapter) {
    $output = [];
    $returnVar = 0;

    exec("ping -I " . escapeshellarg($networkAdapter) . " -c 1 -W 2 " . escapeshellarg($address) . " 2>&1", $output, $returnVar);

    $result = [
        'success' => ($returnVar === 0),
        'latency' => null
    ];

    if ($returnVar === 0) {
        foreach ($output as $line) {
            if (preg_match('/time=([0-9.]+)\s*ms/', $line, $matches)) {
                $result['latency'] = floatval($matches[1]);
                break;
            }
        }
    }

    return $result;
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

        $results[$hostname] = [
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $pingResult['latency'],
            'success' => $pingResult['success']
        ];

        $metricsBuffer[] = [
            'timestamp' => $checkTimestamp,
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $pingResult['latency'],
            'status' => $pingResult['success'] ? 'success' : 'failure'
        ];
    }

    writeMultiSyncMetricsBatch($metricsBuffer);

    return $results;
}

/**
 * Write multiple metrics entries in a single file operation
 */
function writeMultiSyncMetricsBatch($entries) {
    if (empty($entries)) {
        return;
    }

    $metricsFile = WATCHERMULTISYNCPINGMETRICSFILE;
    $fp = @fopen($metricsFile, 'a');
    if (!$fp) {
        return;
    }

    if (flock($fp, LOCK_EX)) {
        foreach ($entries as $entry) {
            $timestamp = date('Y-m-d H:i:s', $entry['timestamp']);
            $jsonData = json_encode($entry);
            fwrite($fp, "[{$timestamp}] {$jsonData}\n");
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    global $_watcherOwnershipVerified;
    if (!isset($_watcherOwnershipVerified[$metricsFile])) {
        ensureFppOwnership($metricsFile);
        $_watcherOwnershipVerified[$metricsFile] = true;
    }
}

/**
 * Rotate multi-sync metrics file to remove old entries
 */
function rotateMultiSyncMetricsFile() {
    $metricsFile = WATCHERMULTISYNCPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return;
    }

    $fp = fopen($metricsFile, 'c+');
    if (!$fp) {
        return;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    $cutoffTime = time() - WATCHERMULTISYNCMETRICSRETENTIONSECONDS;
    $recentMetrics = [];
    $purgedCount = 0;

    rewind($fp);
    while (($line = fgets($fp)) !== false) {
        if (preg_match('/"timestamp"\s*:\s*(\d+)/', $line, $matches)) {
            $entryTimestamp = (int)$matches[1];
            if ($entryTimestamp >= $cutoffTime) {
                $recentMetrics[] = $line;
            } else {
                $purgedCount++;
            }
        }
    }

    if ($purgedCount === 0) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    $backupFile = $metricsFile . '.old';
    $tempFile = $metricsFile . '.tmp';

    $tempFp = fopen($tempFile, 'w');
    if ($tempFp) {
        if (!empty($recentMetrics)) {
            fwrite($tempFp, implode('', $recentMetrics));
        }
        fclose($tempFp);

        @unlink($backupFile);
        rename($metricsFile, $backupFile);
        rename($tempFile, $metricsFile);

        logMessage("Multi-sync metrics purge: removed {$purgedCount} old entries, kept " . count($recentMetrics) . " recent entries.");

        ensureFppOwnership($metricsFile);
        ensureFppOwnership($backupFile);
    } else {
        logMessage("ERROR: Unable to create temp file for multi-sync metrics purge");
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Get or initialize multi-sync rollup state
 */
function getMultiSyncRollupState() {
    return getRollupStateGeneric(WATCHERMULTISYNCROLLUPSTATEFILE, WATCHERMULTISYNCROLLUPTIERS);
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
                'success_count' => 0,
                'failure_count' => 0,
                'address' => $entry['address'] ?? ''
            ];
        }

        if (isset($entry['latency']) && $entry['latency'] !== null) {
            $byHost[$hostname]['latencies'][] = floatval($entry['latency']);
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
        WATCHERMULTISYNCROLLUPTIERS,
        WATCHERMULTISYNCROLLUPSTATEFILE,
        WATCHERMULTISYNCPINGMETRICSFILE,
        'getMultiSyncRollupFilePath',
        'aggregateMultiSyncMetricsForRollup'
    );
}

/**
 * Append rollup entries to a rollup file (wrapper for backward compatibility)
 */
function appendMultiSyncRollupEntries($rollupFile, $entries) {
    return appendRollupEntriesGeneric($rollupFile, $entries);
}

/**
 * Rotate rollup file (wrapper for backward compatibility)
 */
function rotateMultiSyncRollupFile($rollupFile, $retentionSeconds) {
    return rotateRollupFileGeneric($rollupFile, $retentionSeconds);
}

/**
 * Process all multi-sync rollup tiers
 */
function processAllMultiSyncRollups() {
    foreach (WATCHERMULTISYNCROLLUPTIERS as $tier => $config) {
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

    $result = readRollupDataGeneric($rollupFile, $tier, WATCHERMULTISYNCROLLUPTIERS, $startTime, $endTime, $filterFn);

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
    $tierConfig = WATCHERMULTISYNCROLLUPTIERS[$tier];

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
    return getTiersInfoGeneric(WATCHERMULTISYNCROLLUPTIERS, 'getMultiSyncRollupFilePath');
}

/**
 * Get list of unique hostnames from multi-sync metrics
 */
function getMultiSyncHostsList() {
    $metricsFile = WATCHERMULTISYNCPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return [];
    }

    $hosts = [];
    $fp = fopen($metricsFile, 'r');

    if (!$fp) {
        return [];
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['hostname'])) {
                    $hostname = $entry['hostname'];
                    if (!isset($hosts[$hostname])) {
                        $hosts[$hostname] = [
                            'hostname' => $hostname,
                            'address' => $entry['address'] ?? ''
                        ];
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    return array_values($hosts);
}

?>
