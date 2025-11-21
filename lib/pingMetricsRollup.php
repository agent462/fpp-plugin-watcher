<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/watcherCommon.php";

/**
 * Ping Metrics Rollup and Rotation Management
 *
 * This module handles RRD-style rollup of ping metrics into multiple time resolutions
 * for efficient long-term storage and querying. Similar to RRD databases, we maintain
 * multiple tiers of aggregated data with different granularities and retention periods.
 */

// Define rollup tiers (resolution => retention period)
define("WATCHERPINGROLLUPTIERS", [
    '1min' => [
        'interval' => 60,           // 1 minute in seconds
        'retention' => 21600,       // Keep 6 hours (360 data points)
        'label' => '1-minute averages'
    ],
    '5min' => [
        'interval' => 300,          // 5 minutes in seconds
        'retention' => 172800,      // Keep 48 hours (576 data points)
        'label' => '5-minute averages'
    ],
    '30min' => [
        'interval' => 1800,         // 30 minutes in seconds
        'retention' => 1209600,     // Keep 14 days (672 data points)
        'label' => '30-minute averages'
    ],
    '2hour' => [
        'interval' => 7200,         // 2 hours in seconds
        'retention' => 7776000,     // Keep 90 days (1080 data points)
        'label' => '2-hour averages'
    ]
]);

// Define rollup file paths
define("WATCHERPINGROLLUPDIR", dirname(WATCHERPINGMETRICSFILE));
define("WATCHERPINGROLLUPSTATEFILE", WATCHERPINGROLLUPDIR . "/fpp-plugin-watcher-ping-rollup-state.json");

/**
 * Get rollup file path for a specific tier
 */
function getPingRollupFilePath($tier) {
    return WATCHERPINGROLLUPDIR . "/fpp-plugin-watcher-ping-{$tier}.log";
}

/**
 * Get or initialize rollup state
 * Tracks the last processed timestamp for each tier
 */
function getRollupState() {
    $stateFile = WATCHERPINGROLLUPSTATEFILE;

    // Helper to create a fresh state structure without recursion
    $buildFreshState = function() {
        $state = [];
        foreach (array_keys(WATCHERPINGROLLUPTIERS) as $tier) {
            $state[$tier] = [
                'last_processed' => 0,
                'last_rollup' => time()
            ];
        }
        return $state;
    };

    if (!file_exists($stateFile)) {
        // Initialize state with current time for all tiers
        $state = $buildFreshState();
        saveRollupState($state);
        return $state;
    }

    $content = file_get_contents($stateFile);
    $state = json_decode($content, true);

    if (!$state || !is_array($state)) {
        // Corrupted state, reinitialize without recursion
        logMessage("Corrupted ping rollup state file detected. Rebuilding fresh state.");
        $state = $buildFreshState();
        saveRollupState($state);
    }

    return $state;
}

/**
 * Save rollup state to disk
 */
function saveRollupState($state) {
    $stateFile = WATCHERPINGROLLUPSTATEFILE;

    // Use file locking for atomic writes
    $fp = fopen($stateFile, 'c');
    if (!$fp) {
        logMessage("ERROR: Unable to open rollup state file for writing");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    ensureFppOwnership($stateFile);
    return true;
}

/**
 * Read raw ping metrics from the metrics file
 * Returns metrics newer than the given timestamp
 */
function readRawPingMetrics($sinceTimestamp = 0) {
    $metricsFile = WATCHERPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return [];
    }

    $metrics = [];
    $fp = fopen($metricsFile, 'r');

    if (!$fp) {
        return [];
    }

    // Use shared lock for reading
    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            // Extract JSON from log entry format: [timestamp] JSON
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    // Only include entries newer than sinceTimestamp
                    if ($entry['timestamp'] > $sinceTimestamp) {
                        $metrics[] = $entry;
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    // Sort by timestamp (should already be sorted, but ensure it)
    usort($metrics, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return $metrics;
}

/**
 * Aggregate metrics into a time period
 * Returns aggregated statistics for the period
 */
function aggregateMetrics($metrics) {
    if (empty($metrics)) {
        return null;
    }

    $latencies = [];
    $hosts = [];
    $successCount = 0;
    $failureCount = 0;

    foreach ($metrics as $entry) {
        if (isset($entry['latency']) && $entry['latency'] !== null) {
            $latencies[] = floatval($entry['latency']);
        }

        if (isset($entry['host'])) {
            $host = $entry['host'];
            if (!isset($hosts[$host])) {
                $hosts[$host] = 0;
            }
            $hosts[$host]++;
        }

        if (isset($entry['status']) && $entry['status'] === 'success') {
            $successCount++;
        } elseif (isset($entry['status']) && $entry['status'] === 'failure') {
            $failureCount++;
        }
    }

    $sampleCount = count($metrics);
    if ($failureCount === 0) {
        // Backfill failure count if status was missing but we have samples
        $failureCount = $sampleCount - $successCount;
    }

    if (empty($latencies)) {
        // No latency data (e.g., all failures). Still return counts to surface failures.
        return [
            'min_latency' => null,
            'max_latency' => null,
            'avg_latency' => null,
            'sample_count' => $sampleCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'hosts' => $hosts
        ];
    }

    return [
        'min_latency' => round(min($latencies), 3),
        'max_latency' => round(max($latencies), 3),
        'avg_latency' => round(array_sum($latencies) / count($latencies), 3),
        'sample_count' => $sampleCount,
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'hosts' => $hosts
    ];
}

/**
 * Process rollup for a specific tier
 * Aggregates raw metrics into the tier's resolution and retention
 */
function processRollupTier($tier, $tierConfig) {
    $state = getRollupState();
    $lastProcessed = $state[$tier]['last_processed'] ?? 0;
    $lastRollup = $state[$tier]['last_rollup'] ?? 0;
    $interval = $tierConfig['interval'];
    $retention = $tierConfig['retention'];
    $now = time();

    // Prevent running a tier more frequently than its interval to avoid duplicate buckets
    if (($now - $lastRollup) < $interval) {
        return;
    }

    // Get raw metrics since last processed timestamp
    $rawMetrics = readRawPingMetrics($lastProcessed);

    if (empty($rawMetrics)) {
        // Pace rollups even if no new data to avoid constant wakeups
        $state[$tier]['last_rollup'] = $now;
        saveRollupState($state);
        return; // No new data to process
    }

    // Group metrics into time buckets
    $buckets = [];
    foreach ($rawMetrics as $metric) {
        $timestamp = $metric['timestamp'];
        // Calculate bucket start time (aligned to interval boundaries)
        $bucketStart = floor($timestamp / $interval) * $interval;

        if (!isset($buckets[$bucketStart])) {
            $buckets[$bucketStart] = [];
        }

        $buckets[$bucketStart][] = $metric;
    }

    // Process each bucket and create rollup entries
    $rollupFile = getPingRollupFilePath($tier);
    $newEntries = [];

    foreach ($buckets as $bucketStart => $bucketMetrics) {
        $aggregated = aggregateMetrics($bucketMetrics);

        if ($aggregated === null) {
            continue;
        }

        $rollupEntry = array_merge([
            'timestamp' => $bucketStart,
            'period_start' => $bucketStart,
            'period_end' => $bucketStart + $interval
        ], $aggregated);

        $newEntries[] = $rollupEntry;
    }

    // Append new entries to rollup file (with file locking)
    if (!empty($newEntries)) {
        appendRollupEntries($rollupFile, $newEntries);

        // Update last processed timestamp
        $lastMetric = end($rawMetrics);
        $state[$tier]['last_processed'] = $lastMetric['timestamp'];
        $state[$tier]['last_rollup'] = $now;
        saveRollupState($state);
    }

    // Rotate old entries based on retention period
    rotateRollupFile($rollupFile, $retention);
}

/**
 * Append rollup entries to a rollup file
 * Uses file locking for thread safety
 */
function appendRollupEntries($rollupFile, $entries) {
    $fp = fopen($rollupFile, 'a');

    if (!$fp) {
        logMessage("ERROR: Unable to open rollup file for writing: {$rollupFile}");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        foreach ($entries as $entry) {
            $timestamp = date('Y-m-d H:i:s', $entry['timestamp']);
            $jsonData = json_encode($entry);
            $logEntry = "[{$timestamp}] {$jsonData}\n";
            fwrite($fp, $logEntry);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    ensureFppOwnership($rollupFile);
    return true;
}

/**
 * Rotate rollup file to keep only entries within retention period
 * Removes entries older than the retention period
 */
function rotateRollupFile($rollupFile, $retentionSeconds) {
    if (!file_exists($rollupFile)) {
        return;
    }

    $fileSize = filesize($rollupFile);

    // Only rotate if file is larger than 1MB to avoid excessive I/O
    if ($fileSize < 1024 * 1024) {
        return;
    }

    $cutoffTime = time() - $retentionSeconds;
    $recentEntries = [];

    $fp = fopen($rollupFile, 'r');
    if (!$fp) {
        return;
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    // Keep only entries within retention period
                    if ($entry['timestamp'] >= $cutoffTime) {
                        $recentEntries[] = $line;
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    // Rewrite file with recent entries only
    if (!empty($recentEntries)) {
        $fp = fopen($rollupFile, 'w');
        if ($fp && flock($fp, LOCK_EX)) {
            foreach ($recentEntries as $line) {
                fwrite($fp, $line);
            }
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    } else {
        // No recent entries, truncate file
        file_put_contents($rollupFile, '');
    }
    ensureFppOwnership($rollupFile);
}

/**
 * Process all rollup tiers
 * This should be called periodically from the main connectivity check loop
 */
function processAllRollups() {
    $tiers = WATCHERPINGROLLUPTIERS;

    foreach ($tiers as $tier => $config) {
        try {
            processRollupTier($tier, $config);
        } catch (Exception $e) {
            logMessage("ERROR processing rollup tier {$tier}: " . $e->getMessage());
        }
    }
}

/**
 * Read rollup data from a specific tier
 * Returns data within the specified time range
 */
function readRollupData($tier, $startTime = null, $endTime = null) {
    $rollupFile = getPingRollupFilePath($tier);

    if (!file_exists($rollupFile)) {
        return [
            'success' => false,
            'error' => 'Rollup file not found',
            'data' => []
        ];
    }

    if ($endTime === null) {
        $endTime = time();
    }

    // Default start time based on tier retention
    if ($startTime === null) {
        $tierConfig = WATCHERPINGROLLUPTIERS[$tier] ?? null;
        if ($tierConfig) {
            $startTime = $endTime - $tierConfig['retention'];
        } else {
            $startTime = $endTime - (24 * 3600); // Default to 24 hours
        }
    }

    $data = [];
    $fp = fopen($rollupFile, 'r');

    if (!$fp) {
        return [
            'success' => false,
            'error' => 'Unable to read rollup file',
            'data' => []
        ];
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    $timestamp = $entry['timestamp'];

                    // Filter by time range
                    if ($timestamp >= $startTime && $timestamp <= $endTime) {
                        $data[] = $entry;
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    // Sort by timestamp
    usort($data, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return [
        'success' => true,
        'count' => count($data),
        'data' => $data,
        'tier' => $tier,
        'period' => [
            'start' => $startTime,
            'end' => $endTime
        ]
    ];
}

/**
 * Get the best rollup tier for a given time range
 * Returns the tier that provides optimal resolution without excessive data points
 */
function getBestRollupTier($hoursBack) {
    $seconds = $hoursBack * 3600;

    // Select tier based on time range for optimal data points
    if ($hoursBack <= 6) {
        return '1min';      // Up to 6 hours: use 1-min data
    } elseif ($hoursBack <= 48) {
        return '5min';      // Up to 2 days: use 5-min data
    } elseif ($hoursBack <= 336) {
        return '30min';     // Up to 14 days: use 30-min data
    } else {
        return '2hour';     // More than 14 days: use 2-hour data
    }
}

/**
 * Get ping metrics with automatic tier selection
 * Selects the best rollup tier based on the requested time range
 */
function getPingMetricsRollup($hoursBack = 24) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Determine best tier for requested time range
    $tier = getBestRollupTier($hoursBack);
    $tierConfig = WATCHERPINGROLLUPTIERS[$tier];

    $result = readRollupData($tier, $startTime, $endTime);

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
 * Get available rollup tiers information
 */
function getRollupTiersInfo() {
    $tiers = [];

    foreach (WATCHERPINGROLLUPTIERS as $tier => $config) {
        $tiers[$tier] = [
            'interval' => $config['interval'],
            'interval_label' => formatInterval($config['interval']),
            'retention' => $config['retention'],
            'retention_label' => formatDuration($config['retention']),
            'label' => $config['label'],
            'file_exists' => file_exists(getPingRollupFilePath($tier)),
            'file_size' => file_exists(getPingRollupFilePath($tier))
                ? filesize(getPingRollupFilePath($tier))
                : 0
        ];
    }

    return $tiers;
}

/**
 * Format interval in human-readable form
 */
function formatInterval($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    } elseif ($seconds < 3600) {
        return ($seconds / 60) . ' minutes';
    } else {
        return ($seconds / 3600) . ' hours';
    }
}

/**
 * Format duration in human-readable form
 */
function formatDuration($seconds) {
    if ($seconds < 3600) {
        return ($seconds / 60) . ' minutes';
    } elseif ($seconds < 86400) {
        return ($seconds / 3600) . ' hours';
    } else {
        return ($seconds / 86400) . ' days';
    }
}

?>
