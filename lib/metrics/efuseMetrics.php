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

// Raw data retention (6 hours fixed - rollups handle longer retention)
define('EFUSE_RAW_RETENTION', 21600); // 6 hours

/**
 * Get eFuse rollup tiers based on configured retention
 * Tiers are capped by the retention period - no point storing more than we'll keep
 *
 * @param int|null $retentionDays Number of days to retain data (null = read from config)
 * @return array Tier configuration
 */
function getEfuseRollupTiers($retentionDays = null) {
    if ($retentionDays === null) {
        $config = readPluginConfig();
        $retentionDays = $config['efuseRetentionDays'] ?? 7;
    }

    $retentionSeconds = $retentionDays * 86400;

    return [
        '1min' => [
            'interval' => 60,           // 1 minute buckets
            'retention' => min(21600, $retentionSeconds),    // max 6 hours
            'label' => '1-minute averages'
        ],
        '5min' => [
            'interval' => 300,          // 5 minute buckets
            'retention' => min(172800, $retentionSeconds),   // max 48 hours
            'label' => '5-minute averages'
        ],
        '30min' => [
            'interval' => 1800,         // 30 minute buckets
            'retention' => min(1209600, $retentionSeconds),  // max 14 days
            'label' => '30-minute averages'
        ],
        '2hour' => [
            'interval' => 7200,         // 2 hour buckets
            'retention' => $retentionSeconds,                // full retention
            'label' => '2-hour averages'
        ]
    ];
}

/**
 * Get rollup file path for a specific tier
 *
 * @param string $tier Tier name
 * @return string File path
 */
function getEfuseRollupFilePath($tier) {
    return WATCHEREFUSEDIR . "/{$tier}.log";
}

/**
 * Select best tier for requested time range
 *
 * @param int $hours Hours of data requested
 * @return string Tier name
 */
function getBestEfuseTierForHours($hours) {
    if ($hours <= 6) return '1min';
    if ($hours <= 48) return '5min';
    if ($hours <= 336) return '30min';  // 14 days
    return '2hour';
}

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

    // Calculate total from all ports (we already have all values here)
    $total = array_sum($ports);

    // Add total as pseudo-port with underscore prefix to distinguish from real ports
    $ports['_total'] = $total;

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
 * Process all eFuse rollup tiers
 * Aggregates raw samples into all configured tier buckets
 */
function processEfuseRollup() {
    $tiers = getEfuseRollupTiers();

    foreach ($tiers as $tierName => $tierConfig) {
        // Determine source: raw data for 1min, previous tier for higher tiers
        if ($tierName === '1min') {
            $sourceFile = WATCHEREFUSERAWFILE;
        } else {
            // Higher tiers aggregate from the previous tier
            $tierOrder = array_keys($tiers);
            $tierIndex = array_search($tierName, $tierOrder);
            $previousTier = $tierOrder[$tierIndex - 1];
            $sourceFile = getEfuseRollupFilePath($previousTier);
        }

        processRollupTierGeneric(
            $tierName,
            $tierConfig,
            $tiers,
            WATCHEREFUSEROLLUPSTATEFILE,
            $sourceFile,
            'getEfuseRollupFilePath',
            'aggregateEfuseBucket'
        );
    }

    // Rotate the raw file to keep only recent data
    rotateRawMetricsFileGeneric(WATCHEREFUSERAWFILE, EFUSE_RAW_RETENTION);

    // Also rotate rollup files according to their retention
    foreach ($tiers as $tierName => $tierConfig) {
        rotateRawMetricsFileGeneric(getEfuseRollupFilePath($tierName), $tierConfig['retention']);
    }
}

/**
 * Aggregate eFuse metrics for a time bucket
 * Handles both raw metrics (port value is integer) and rollup metrics (port value is object with avg/min/max)
 *
 * @param array $metrics Array of raw or rollup metrics in this bucket
 * @param int $bucketStart Start timestamp of bucket
 * @param int $interval Bucket interval in seconds
 * @return array|null Aggregated entry or null if no data
 */
function aggregateEfuseBucket($metrics, $bucketStart, $interval) {
    if (empty($metrics)) {
        return null;
    }

    // Collect all port readings
    // For raw data: $mA is an integer
    // For rollup data: $mA is an array with 'avg', 'min', 'max', 'samples'
    $portData = [];

    foreach ($metrics as $metric) {
        $ports = $metric['ports'] ?? [];
        foreach ($ports as $portName => $mA) {
            if (!isset($portData[$portName])) {
                $portData[$portName] = ['values' => [], 'mins' => [], 'maxs' => [], 'samples' => 0];
            }

            if (is_array($mA)) {
                // Rollup entry - extract aggregated values
                $portData[$portName]['values'][] = $mA['avg'] ?? 0;
                $portData[$portName]['mins'][] = $mA['min'] ?? 0;
                $portData[$portName]['maxs'][] = $mA['max'] ?? 0;
                $portData[$portName]['samples'] += $mA['samples'] ?? 1;
            } else {
                // Raw entry - simple integer value
                $portData[$portName]['values'][] = $mA;
                $portData[$portName]['mins'][] = $mA;
                $portData[$portName]['maxs'][] = $mA;
                $portData[$portName]['samples'] += 1;
            }
        }
    }

    if (empty($portData)) {
        return null;
    }

    // Calculate aggregates for each port
    $aggregatedPorts = [];
    foreach ($portData as $portName => $data) {
        if (empty($data['values'])) {
            continue;
        }

        $values = $data['values'];
        $mins = $data['mins'];
        $maxs = $data['maxs'];
        $sampleCount = $data['samples'];
        $count = count($values);

        $aggregatedPorts[$portName] = [
            'avg' => intval(round(array_sum($values) / $count)),
            'min' => min($mins),
            'max' => max($maxs),
            'peak' => max($maxs), // For backward compatibility
            'samples' => $sampleCount
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

    // Select best tier for the requested time range
    $bestTier = getBestEfuseTierForHours($hoursBack);
    $tiers = getEfuseRollupTiers();
    $tierOrder = array_keys($tiers);

    $filterFn = null;
    if ($portFilter !== null) {
        $filterFn = function($entry) use ($portFilter) {
            return isset($entry['ports'][$portFilter]);
        };
    }

    // Try preferred tier first, fall back to lower tiers if file doesn't exist
    $selectedTier = $bestTier;
    $selectedFile = getEfuseRollupFilePath($bestTier);

    if (!file_exists($selectedFile)) {
        // Find the best available tier that exists
        $tierIndex = array_search($bestTier, $tierOrder);
        for ($i = $tierIndex - 1; $i >= 0; $i--) {
            $fallbackTier = $tierOrder[$i];
            $fallbackFile = getEfuseRollupFilePath($fallbackTier);
            if (file_exists($fallbackFile)) {
                $selectedTier = $fallbackTier;
                $selectedFile = $fallbackFile;
                break;
            }
        }
    }

    $result = readRollupDataGeneric(
        $selectedFile,
        $selectedTier,
        $tiers,
        $startTime,
        $endTime,
        $filterFn
    );

    // Add tier_info like ping metrics does for UI display
    if ($result['success']) {
        $tierConfig = $tiers[$selectedTier] ?? [];
        $result['tier_info'] = [
            'tier' => $selectedTier,
            'interval' => $tierConfig['interval'] ?? 60,
            'label' => $tierConfig['label'] ?? 'Unknown'
        ];
    }

    return $result;
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
    $tierInfo = null;

    // Use raw data for very short periods, rollups for longer
    if ($hoursBack <= 1) {
        $data = readEfuseRawMetrics($hoursBack, $portName);
        $source = 'raw';
        // Get collection interval from config
        $config = readPluginConfig();
        $interval = $config['efuseCollectionInterval'] ?? 5;
    } else {
        $result = readEfuseRollup($hoursBack, $portName);
        $data = $result['data'] ?? [];
        $source = 'rollup';
        $tierInfo = $result['tier_info'] ?? null;
        // Get interval from the tier that was actually selected (may be fallback)
        $interval = $tierInfo['interval'] ?? 60;
    }

    // Build indexed lookup by timestamp (align to interval boundaries for matching)
    $dataByTimestamp = [];
    foreach ($data as $entry) {
        $ts = $entry['timestamp'];
        // Align timestamp to interval boundary for lookup matching
        $alignedTs = intval(floor($ts / $interval) * $interval);
        // If multiple entries fall in same bucket, keep the latest
        $dataByTimestamp[$alignedTs] = $entry['ports'][$portName] ?? null;
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
        'config' => $portConfig,
        'tier_info' => $tierInfo
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

    // Get rollup data using appropriate tier (with automatic fallback)
    $result = readEfuseRollup($hoursBack);
    $rollupData = $result['data'] ?? [];
    $tierInfo = $result['tier_info'] ?? null;

    // Get output config
    $outputConfig = getEfuseOutputConfig();
    $portNames = array_keys($outputConfig['ports']);

    // Build indexed lookup of data by timestamp
    $dataByTimestamp = [];
    foreach ($rollupData as $entry) {
        $ts = $entry['timestamp'];
        $dataByTimestamp[$ts] = $entry['ports'] ?? [];
    }

    // Use the actual tier that was selected (may be fallback)
    $interval = $tierInfo['interval'] ?? 60;
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Align to interval boundaries
    $startTime = intval(floor($startTime / $interval) * $interval);
    // Exclude current and previous bucket (rollup may not have processed them yet)
    // Rollup runs every 60s, so we need 2-minute buffer to ensure data exists
    $endTime = intval(floor($endTime / $interval) * $interval) - (2 * $interval);

    // Generate all expected timestamps
    $allTimestamps = [];
    for ($ts = $startTime; $ts <= $endTime; $ts += $interval) {
        $allTimestamps[] = $ts;
    }

    // Build time series for each port, filling gaps with zeros
    $timeSeries = [];
    $peaks = [];

    // Also collect _total if present in data
    $allPortsIncludingTotal = $portNames;
    // Check if _total exists in any data entry
    foreach ($dataByTimestamp as $ts => $ports) {
        if (isset($ports['_total']) && !in_array('_total', $allPortsIncludingTotal)) {
            $allPortsIncludingTotal[] = '_total';
            break;
        }
    }

    foreach ($allPortsIncludingTotal as $portName) {
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

    // Extract total history (separate from port data)
    $totalHistory = [];
    if (isset($timeSeries['_total'])) {
        $totalHistory = $timeSeries['_total'];
        unset($timeSeries['_total']);
    }

    // Remove _total from peaks and ports list
    unset($peaks['_total']);

    return [
        'success' => true,
        'hours' => $hoursBack,
        'hardware' => getEfuseHardwareSummary(),
        'portCount' => count($portNames),
        'ports' => $portNames,
        'outputConfig' => $outputConfig['ports'],
        'timeSeries' => $timeSeries,
        'totalHistory' => $totalHistory,
        'peaks' => $peaks,
        'timestamps' => $allTimestamps,
        'period' => $result['period'] ?? null,
        'tier_info' => $tierInfo
    ];
}

/**
 * Get rollup tier information for eFuse metrics
 *
 * @return array Tier info
 */
function getEfuseRollupTiersInfo() {
    return getTiersInfoGeneric(getEfuseRollupTiers(), 'getEfuseRollupFilePath');
}

/**
 * Get the rollup state
 *
 * @return array State information
 */
function getEfuseRollupState() {
    return getRollupStateGeneric(WATCHEREFUSEROLLUPSTATEFILE, getEfuseRollupTiers());
}

/**
 * Get configured eFuse settings for API response
 *
 * @return array Config settings relevant to eFuse
 */
function getEfuseConfig() {
    $config = readPluginConfig();
    return [
        'collectionInterval' => $config['efuseCollectionInterval'] ?? 5,
        'retentionDays' => $config['efuseRetentionDays'] ?? 7
    ];
}
?>
