<?php
/**
 * eFuse Metrics Storage and Rollup Library
 *
 * Handles storage of eFuse current readings with time-based rollup aggregation.
 * Raw data is collected every 5 seconds and rolled up to 1-minute averages.
 *
 * @package fpp-plugin-watcher
 */

include_once __DIR__ . '/rollupBase.php';
include_once __DIR__ . '/../controllers/efuseHardware.php';
include_once __DIR__ . '/../controllers/efuseOutputConfig.php';

// eFuse rollup tier configuration (24-hour focused)
define('EFUSE_ROLLUP_TIERS', [
    '1min' => [
        'interval' => 60,        // 1 minute buckets
        'retention' => 86400,    // 24 hours
        'label' => '1-minute averages'
    ]
]);

// Raw data retention (shorter than rollup since we have aggregates)
define('EFUSE_RAW_RETENTION', 21600); // 6 hours

/**
 * Write a raw eFuse metric sample
 *
 * @param array $ports Port data [portName => mA value] (only non-zero values)
 * @return bool Success
 */
function writeEfuseRawMetric($ports) {
    if (empty($ports)) {
        return true; // Nothing to write
    }

    $entry = [
        'timestamp' => time(),
        'ports' => $ports
    ];

    $metricsFile = WATCHEREFUSERAWFILE;
    $timestamp = date('Y-m-d H:i:s');
    $jsonData = json_encode($entry);

    $fp = @fopen($metricsFile, 'a');
    if (!$fp) {
        // Ensure directory exists
        ensureDataDirectories();
        $fp = @fopen($metricsFile, 'a');
        if (!$fp) {
            logMessage("ERROR: Unable to open eFuse raw metrics file for writing");
            return false;
        }
    }

    $success = false;
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, "[{$timestamp}] {$jsonData}\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        $success = true;
    }

    fclose($fp);
    ensureFppOwnership($metricsFile);

    return $success;
}

/**
 * Read raw eFuse metrics from log file
 *
 * @param int $hoursBack Number of hours to look back
 * @param string|null $portFilter Optional port name to filter by
 * @return array Array of metric entries
 */
function readEfuseRawMetrics($hoursBack = 6, $portFilter = null) {
    $sinceTimestamp = time() - ($hoursBack * 3600);

    $filterFn = null;
    if ($portFilter !== null) {
        $filterFn = function($entry) use ($portFilter) {
            return isset($entry['ports'][$portFilter]);
        };
    }

    return readJsonLinesFile(WATCHEREFUSERAWFILE, $sinceTimestamp, $filterFn);
}

/**
 * Process eFuse rollup for 1-minute tier
 * Aggregates raw 5-second samples into 1-minute buckets
 */
function processEfuseRollup() {
    $tierConfig = EFUSE_ROLLUP_TIERS['1min'];

    processRollupTierGeneric(
        '1min',
        $tierConfig,
        EFUSE_ROLLUP_TIERS,
        WATCHEREFUSEROLLUPSTATEFILE,
        WATCHEREFUSERAWFILE,
        function($tier) {
            return WATCHEREFUSEROLLUPFILE;
        },
        'aggregateEfuseBucket'
    );

    // Also rotate the raw file to keep only recent data
    rotateRawMetricsFileGeneric(WATCHEREFUSERAWFILE, EFUSE_RAW_RETENTION);
}

/**
 * Aggregate eFuse metrics for a time bucket
 *
 * @param array $metrics Array of raw metrics in this bucket
 * @param int $bucketStart Start timestamp of bucket
 * @param int $interval Bucket interval in seconds
 * @return array|null Aggregated entry or null if no data
 */
function aggregateEfuseBucket($metrics, $bucketStart, $interval) {
    if (empty($metrics)) {
        return null;
    }

    // Collect all port readings
    $portData = [];

    foreach ($metrics as $metric) {
        $ports = $metric['ports'] ?? [];
        foreach ($ports as $portName => $mA) {
            if (!isset($portData[$portName])) {
                $portData[$portName] = [];
            }
            $portData[$portName][] = $mA;
        }
    }

    if (empty($portData)) {
        return null;
    }

    // Calculate aggregates for each port
    $aggregatedPorts = [];
    foreach ($portData as $portName => $readings) {
        if (empty($readings)) {
            continue;
        }

        sort($readings);
        $count = count($readings);

        $aggregatedPorts[$portName] = [
            'avg' => intval(round(array_sum($readings) / $count)),
            'min' => min($readings),
            'max' => max($readings),
            'peak' => max($readings), // For backward compatibility
            'samples' => $count
        ];
    }

    return [
        'timestamp' => $bucketStart,
        'interval' => $interval,
        'ports' => $aggregatedPorts
    ];
}

/**
 * Read eFuse rollup data
 *
 * @param int $hoursBack Number of hours to look back
 * @param string|null $portFilter Optional port name to filter by
 * @return array Rollup data with metadata
 */
function readEfuseRollup($hoursBack = 24, $portFilter = null) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $filterFn = null;
    if ($portFilter !== null) {
        $filterFn = function($entry) use ($portFilter) {
            return isset($entry['ports'][$portFilter]);
        };
    }

    return readRollupDataGeneric(
        WATCHEREFUSEROLLUPFILE,
        '1min',
        EFUSE_ROLLUP_TIERS,
        $startTime,
        $endTime,
        $filterFn
    );
}

/**
 * Get current eFuse status with port details
 *
 * @return array Current status
 */
function getEfuseCurrentStatus() {
    $hardware = detectEfuseHardware();

    if (!$hardware['supported']) {
        return [
            'success' => false,
            'supported' => false,
            'error' => 'No compatible eFuse hardware detected'
        ];
    }

    // Read current values
    $reading = readEfuseData();

    if (!$reading['success']) {
        return [
            'success' => false,
            'supported' => true,
            'error' => $reading['error'] ?? 'Failed to read eFuse data'
        ];
    }

    // Get output config for port details
    $outputConfig = getEfuseOutputConfig();

    // Get port summary with status
    $portSummary = getPortCurrentSummary($reading['ports']);

    // Calculate totals
    $totals = calculateTotalCurrent($reading['ports']);

    return [
        'success' => true,
        'supported' => true,
        'timestamp' => $reading['timestamp'],
        'hardware' => getEfuseHardwareSummary(),
        'ports' => $portSummary,
        'totals' => $totals,
        'outputConfig' => $outputConfig
    ];
}

/**
 * Get historical eFuse data for a specific port
 *
 * @param string $portName Port name (e.g., "Port1")
 * @param int $hoursBack Number of hours to look back
 * @return array Historical data
 */
function getEfusePortHistory($portName, $hoursBack = 24) {
    // Use rollup data for longer periods, raw for shorter
    if ($hoursBack <= 6) {
        $data = readEfuseRawMetrics($hoursBack, $portName);
        $source = 'raw';
        $interval = 5; // Raw data is 5-second intervals
    } else {
        $result = readEfuseRollup($hoursBack, $portName);
        $data = $result['data'] ?? [];
        $source = 'rollup';
        $interval = 60; // Rollup data is 1-minute intervals
    }

    // Build indexed lookup by timestamp
    $dataByTimestamp = [];
    foreach ($data as $entry) {
        $ts = $entry['timestamp'];
        $dataByTimestamp[$ts] = $entry['ports'][$portName] ?? null;
    }

    // Determine time range
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Align to interval boundaries
    $startTime = intval(floor($startTime / $interval) * $interval);
    $endTime = intval(floor($endTime / $interval) * $interval);

    // Generate complete time series with gaps filled as zero
    $history = [];
    for ($ts = $startTime; $ts <= $endTime; $ts += $interval) {
        $portData = $dataByTimestamp[$ts] ?? null;

        if ($portData !== null) {
            if (is_array($portData)) {
                // Rollup data
                $history[] = [
                    'timestamp' => $ts,
                    'avg' => $portData['avg'] ?? 0,
                    'min' => $portData['min'] ?? 0,
                    'max' => $portData['max'] ?? 0
                ];
            } else {
                // Raw data
                $history[] = [
                    'timestamp' => $ts,
                    'value' => $portData
                ];
            }
        } else {
            // No data - fill with zero
            if ($source === 'rollup') {
                $history[] = [
                    'timestamp' => $ts,
                    'avg' => 0,
                    'min' => 0,
                    'max' => 0
                ];
            } else {
                $history[] = [
                    'timestamp' => $ts,
                    'value' => 0
                ];
            }
        }
    }

    // Get output config for this port
    $portConfig = getPortOutputConfig($portName);

    return [
        'success' => true,
        'portName' => $portName,
        'hours' => $hoursBack,
        'source' => $source,
        'count' => count($history),
        'history' => $history,
        'config' => $portConfig
    ];
}

/**
 * Get heatmap data for all ports over time
 *
 * @param int $hoursBack Number of hours to look back
 * @return array Heatmap data structure
 */
function getEfuseHeatmapData($hoursBack = 24) {
    $hardware = detectEfuseHardware();

    if (!$hardware['supported']) {
        return [
            'success' => false,
            'error' => 'No compatible eFuse hardware detected'
        ];
    }

    // Get rollup data
    $result = readEfuseRollup($hoursBack);
    $rollupData = $result['data'] ?? [];

    // Get output config
    $outputConfig = getEfuseOutputConfig();
    $portNames = array_keys($outputConfig['ports']);

    // Build indexed lookup of data by timestamp
    $dataByTimestamp = [];
    foreach ($rollupData as $entry) {
        $ts = $entry['timestamp'];
        $dataByTimestamp[$ts] = $entry['ports'] ?? [];
    }

    // Determine time range and interval (1 minute = 60 seconds)
    $interval = 60;
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Align to interval boundaries
    $startTime = intval(floor($startTime / $interval) * $interval);
    $endTime = intval(floor($endTime / $interval) * $interval);

    // Generate all expected timestamps
    $allTimestamps = [];
    for ($ts = $startTime; $ts <= $endTime; $ts += $interval) {
        $allTimestamps[] = $ts;
    }

    // Build time series for each port, filling gaps with zeros
    $timeSeries = [];
    $peaks = [];

    foreach ($portNames as $portName) {
        $timeSeries[$portName] = [];
        $peaks[$portName] = 0;

        foreach ($allTimestamps as $ts) {
            $portData = $dataByTimestamp[$ts][$portName] ?? null;

            if ($portData !== null) {
                $value = $portData['avg'] ?? 0;
                $min = $portData['min'] ?? 0;
                $max = $portData['max'] ?? 0;

                if ($max > $peaks[$portName]) {
                    $peaks[$portName] = $max;
                }
            } else {
                // No data for this timestamp - fill with zero
                $value = 0;
                $min = 0;
                $max = 0;
            }

            $timeSeries[$portName][] = [
                'timestamp' => $ts,
                'value' => $value,
                'min' => $min,
                'max' => $max
            ];
        }
    }

    return [
        'success' => true,
        'hours' => $hoursBack,
        'hardware' => getEfuseHardwareSummary(),
        'portCount' => count($portNames),
        'ports' => $portNames,
        'outputConfig' => $outputConfig['ports'],
        'timeSeries' => $timeSeries,
        'peaks' => $peaks,
        'timestamps' => $allTimestamps,
        'period' => $result['period'] ?? null
    ];
}

/**
 * Get rollup tier information for eFuse metrics
 *
 * @return array Tier info
 */
function getEfuseRollupTiersInfo() {
    return getTiersInfoGeneric(EFUSE_ROLLUP_TIERS, function($tier) {
        return WATCHEREFUSEROLLUPFILE;
    });
}

/**
 * Get the rollup state
 *
 * @return array State information
 */
function getEfuseRollupState() {
    return getRollupStateGeneric(WATCHEREFUSEROLLUPSTATEFILE, EFUSE_ROLLUP_TIERS);
}
?>
