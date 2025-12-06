<?php
/**
 * Ping Metrics Rollup and Rotation Management
 *
 * This module handles RRD-style rollup of ping metrics into multiple time resolutions
 * for efficient long-term storage and querying.
 */

include_once __DIR__ . "/rollupBase.php";

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
 */
function getRollupState() {
    return getRollupStateGeneric(WATCHERPINGROLLUPSTATEFILE, WATCHERPINGROLLUPTIERS);
}

/**
 * Save rollup state to disk
 */
function saveRollupState($state) {
    return saveRollupStateGeneric(WATCHERPINGROLLUPSTATEFILE, $state);
}

/**
 * Read raw ping metrics from the metrics file
 */
function readRawPingMetrics($sinceTimestamp = 0) {
    return readMetricsFileGeneric(WATCHERPINGMETRICSFILE, $sinceTimestamp);
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
        $failureCount = $sampleCount - $successCount;
    }

    if (empty($latencies)) {
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
 * Aggregation function wrapper for generic rollup processing
 */
function aggregatePingMetricsForRollup($bucketMetrics, $bucketStart, $interval) {
    $aggregated = aggregateMetrics($bucketMetrics);

    if ($aggregated === null) {
        return null;
    }

    return array_merge([
        'timestamp' => $bucketStart,
        'period_start' => $bucketStart,
        'period_end' => $bucketStart + $interval
    ], $aggregated);
}

/**
 * Process rollup for a specific tier
 */
function processRollupTier($tier, $tierConfig) {
    processRollupTierGeneric(
        $tier,
        $tierConfig,
        WATCHERPINGROLLUPTIERS,
        WATCHERPINGROLLUPSTATEFILE,
        WATCHERPINGMETRICSFILE,
        'getPingRollupFilePath',
        'aggregatePingMetricsForRollup'
    );
}

/**
 * Append rollup entries to a rollup file (wrapper for backward compatibility)
 */
function appendRollupEntries($rollupFile, $entries) {
    return appendRollupEntriesGeneric($rollupFile, $entries);
}

/**
 * Rotate rollup file (wrapper for backward compatibility)
 */
function rotateRollupFile($rollupFile, $retentionSeconds) {
    return rotateRollupFileGeneric($rollupFile, $retentionSeconds);
}

/**
 * Process all rollup tiers
 */
function processAllRollups() {
    foreach (WATCHERPINGROLLUPTIERS as $tier => $config) {
        try {
            processRollupTier($tier, $config);
        } catch (Exception $e) {
            logMessage("ERROR processing rollup tier {$tier}: " . $e->getMessage());
        }
    }
}

/**
 * Read rollup data from a specific tier
 */
function readRollupData($tier, $startTime = null, $endTime = null) {
    $rollupFile = getPingRollupFilePath($tier);
    return readRollupDataGeneric($rollupFile, $tier, WATCHERPINGROLLUPTIERS, $startTime, $endTime);
}

/**
 * Get the best rollup tier for a given time range
 */
function getBestRollupTier($hoursBack) {
    return getBestTierForHours($hoursBack);
}

/**
 * Get ping metrics with automatic tier selection
 */
function getPingMetricsRollup($hoursBack = 24) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

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
    return getTiersInfoGeneric(WATCHERPINGROLLUPTIERS, 'getPingRollupFilePath');
}

?>
