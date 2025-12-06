<?php
/**
 * Network Quality Metrics Collection and Rollup
 *
 * Tracks network quality metrics for multi-sync remotes:
 * - Packet loss percentage (sync packets sent vs received)
 * - Round-trip latency (HTTP API response time)
 * - Jitter (latency variance using RFC 3550 algorithm)
 */

include_once __DIR__ . "/rollupBase.php";
include_once __DIR__ . "/multiSyncComparison.php";

// Network quality log file
define("WATCHERNETWORKQUALITYFILE", WATCHERLOGDIR . "/" . WATCHERPLUGINNAME . "-network-quality.log");
define("WATCHERNETWORKQUALITYROLLUPSTATEFILE", WATCHERLOGDIR . "/" . WATCHERPLUGINNAME . "-network-quality-rollup-state.json");

// Rollup tiers for network quality metrics
define("WATCHERNETWORKQUALITYTIERS", [
    '1min' => [
        'interval' => 60,
        'retention' => 21600,       // 6 hours
        'label' => '1-minute averages'
    ],
    '5min' => [
        'interval' => 300,
        'retention' => 172800,      // 48 hours
        'label' => '5-minute averages'
    ],
    '30min' => [
        'interval' => 1800,
        'retention' => 1209600,     // 14 days
        'label' => '30-minute averages'
    ],
    '2hour' => [
        'interval' => 7200,
        'retention' => 7776000,     // 90 days
        'label' => '2-hour averages'
    ]
]);

// Quality thresholds
define('LATENCY_GOOD_MS', 50);
define('LATENCY_FAIR_MS', 100);
define('LATENCY_POOR_MS', 250);

define('JITTER_GOOD_MS', 10);
define('JITTER_FAIR_MS', 20);
define('JITTER_POOR_MS', 50);

define('PACKET_LOSS_GOOD_PCT', 1);
define('PACKET_LOSS_FAIR_PCT', 2);
define('PACKET_LOSS_POOR_PCT', 5);

// Retention period for raw metrics (25 hours)
define("WATCHERNETWORKQUALITYRETENTIONSECONDS", 25 * 60 * 60);

// Jitter state storage (per-host previous latency for RFC 3550 calculation)
$_networkQualityJitterState = [];

/**
 * Get rollup file path for a specific tier
 */
function getNetworkQualityRollupFilePath($tier) {
    return WATCHERLOGDIR . "/" . WATCHERPLUGINNAME . "-network-quality-{$tier}.log";
}

/**
 * Calculate jitter using RFC 3550 algorithm
 * J(i) = J(i-1) + (|D(i-1,i)| - J(i-1)) / 16
 * where D = difference in consecutive packet transit times
 *
 * @param string $hostname Host identifier
 * @param float $latency Current latency measurement in ms
 * @param array &$state Jitter state reference
 * @return float|null Calculated jitter or null if first sample
 */
function calculateJitterRFC3550($hostname, $latency, &$state) {
    if (!isset($state[$hostname])) {
        $state[$hostname] = [
            'prevLatency' => $latency,
            'jitter' => 0.0
        ];
        return null;
    }

    $prevLatency = $state[$hostname]['prevLatency'];
    $prevJitter = $state[$hostname]['jitter'];

    // D = difference between consecutive latencies
    $d = abs($latency - $prevLatency);

    // RFC 3550 jitter calculation
    $jitter = $prevJitter + ($d - $prevJitter) / 16.0;

    // Update state
    $state[$hostname]['prevLatency'] = $latency;
    $state[$hostname]['jitter'] = $jitter;

    return round($jitter, 2);
}

/**
 * Get quality rating based on metric value and thresholds
 *
 * @param float $value Metric value
 * @param float $good Good threshold
 * @param float $fair Fair threshold
 * @param float $poor Poor threshold
 * @return string Quality rating: good, fair, poor, or critical
 */
function getQualityRating($value, $good, $fair, $poor) {
    if ($value <= $good) return 'good';
    if ($value <= $fair) return 'fair';
    if ($value <= $poor) return 'poor';
    return 'critical';
}

/**
 * Get overall quality rating from individual ratings
 *
 * @param string $latencyRating
 * @param string $jitterRating
 * @param string $packetLossRating
 * @return string Overall quality rating
 */
function getOverallQualityRating($latencyRating, $jitterRating, $packetLossRating) {
    $ratings = [$latencyRating, $jitterRating, $packetLossRating];

    if (in_array('critical', $ratings)) return 'critical';
    if (in_array('poor', $ratings)) return 'poor';
    if (in_array('fair', $ratings)) return 'fair';
    return 'good';
}

/**
 * Collect network quality metrics from all remote systems
 * Uses data from comparison API (response time) and sync metrics (packet counts)
 *
 * @return array Collected metrics
 */
function collectNetworkQualityMetrics() {
    global $_networkQualityJitterState;

    $timestamp = time();
    $metrics = [];

    // Get comparison data (includes response times)
    $comparison = getSyncComparison();

    if (!$comparison['success']) {
        return ['success' => false, 'error' => 'Failed to get comparison data'];
    }

    $playerMetrics = $comparison['player']['metrics'] ?? [];
    $playerPacketsSent = $playerMetrics['totalPacketsSent'] ?? 0;

    foreach ($comparison['remotes'] as $remote) {
        $hostname = $remote['hostname'];
        $address = $remote['address'];

        // Skip offline remotes
        if (!$remote['online']) {
            continue;
        }

        $remoteMetrics = $remote['metrics'] ?? [];

        // Response time from comparison API (HTTP latency)
        $latency = $remote['responseTime'] ?? null;

        // Calculate jitter
        $jitter = null;
        if ($latency !== null) {
            $jitter = calculateJitterRFC3550($hostname, $latency, $_networkQualityJitterState);
        }

        // Packet loss calculation
        $packetLoss = null;
        $remotePacketsReceived = $remoteMetrics['totalPacketsReceived'] ?? null;

        // Only calculate packet loss if we have valid data from remote's watcher plugin
        if ($playerPacketsSent > 0 && $remotePacketsReceived !== null && $remote['pluginInstalled']) {
            // This is a rough estimate - actual packet loss requires sequence tracking
            // For now, we track the ratio and any significant deviation indicates loss
            // Store raw counts for aggregation
            $packetLoss = 0; // Will be calculated during aggregation with window-based counting
        }

        $metricEntry = [
            'timestamp' => $timestamp,
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $latency,
            'jitter' => $jitter,
            'playerPacketsSent' => $playerPacketsSent,
            'remotePacketsReceived' => $remotePacketsReceived,
            'pluginInstalled' => $remote['pluginInstalled']
        ];

        // Add quality ratings
        if ($latency !== null) {
            $metricEntry['latencyQuality'] = getQualityRating($latency, LATENCY_GOOD_MS, LATENCY_FAIR_MS, LATENCY_POOR_MS);
        }
        if ($jitter !== null) {
            $metricEntry['jitterQuality'] = getQualityRating($jitter, JITTER_GOOD_MS, JITTER_FAIR_MS, JITTER_POOR_MS);
        }

        $metrics[] = $metricEntry;
    }

    // Write metrics to log file
    if (!empty($metrics)) {
        writeNetworkQualityMetrics($metrics);
    }

    return [
        'success' => true,
        'timestamp' => $timestamp,
        'count' => count($metrics),
        'metrics' => $metrics
    ];
}

/**
 * Write network quality metrics to log file
 *
 * @param array $entries Array of metric entries
 */
function writeNetworkQualityMetrics($entries) {
    if (empty($entries)) {
        return;
    }

    $fp = @fopen(WATCHERNETWORKQUALITYFILE, 'a');
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
    if (!isset($_watcherOwnershipVerified[WATCHERNETWORKQUALITYFILE])) {
        ensureFppOwnership(WATCHERNETWORKQUALITYFILE);
        $_watcherOwnershipVerified[WATCHERNETWORKQUALITYFILE] = true;
    }
}

/**
 * Read raw network quality metrics
 *
 * @param int $sinceTimestamp Only return entries newer than this
 * @return array Array of metric entries
 */
function readRawNetworkQualityMetrics($sinceTimestamp = 0) {
    return readMetricsFileGeneric(WATCHERNETWORKQUALITYFILE, $sinceTimestamp);
}

/**
 * Aggregate network quality metrics for a time period, grouped by hostname
 *
 * @param array $metrics Raw metrics for the period
 * @return array|null Aggregated results per host
 */
function aggregateNetworkQualityMetrics($metrics) {
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
                'address' => $entry['address'] ?? '',
                'firstPlayerPackets' => null,
                'lastPlayerPackets' => null,
                'firstRemotePackets' => null,
                'lastRemotePackets' => null,
                'sample_count' => 0
            ];
        }

        $byHost[$hostname]['sample_count']++;

        if (isset($entry['latency']) && $entry['latency'] !== null) {
            $byHost[$hostname]['latencies'][] = floatval($entry['latency']);
        }

        if (isset($entry['jitter']) && $entry['jitter'] !== null) {
            $byHost[$hostname]['jitters'][] = floatval($entry['jitter']);
        }

        // Track packet counts for window-based loss calculation
        $playerPkts = $entry['playerPacketsSent'] ?? null;
        $remotePkts = $entry['remotePacketsReceived'] ?? null;

        if ($playerPkts !== null) {
            if ($byHost[$hostname]['firstPlayerPackets'] === null) {
                $byHost[$hostname]['firstPlayerPackets'] = $playerPkts;
            }
            $byHost[$hostname]['lastPlayerPackets'] = $playerPkts;
        }

        if ($remotePkts !== null) {
            if ($byHost[$hostname]['firstRemotePackets'] === null) {
                $byHost[$hostname]['firstRemotePackets'] = $remotePkts;
            }
            $byHost[$hostname]['lastRemotePackets'] = $remotePkts;
        }
    }

    $aggregated = [];
    foreach ($byHost as $hostname => $data) {
        $hostResult = [
            'hostname' => $hostname,
            'address' => $data['address'],
            'sample_count' => $data['sample_count']
        ];

        // Latency aggregation
        if (!empty($data['latencies'])) {
            sort($data['latencies']);
            $count = count($data['latencies']);
            $hostResult['latency_min'] = round(min($data['latencies']), 1);
            $hostResult['latency_max'] = round(max($data['latencies']), 1);
            $hostResult['latency_avg'] = round(array_sum($data['latencies']) / $count, 1);

            // P95 latency
            $p95Index = (int)ceil($count * 0.95) - 1;
            $hostResult['latency_p95'] = round($data['latencies'][max(0, $p95Index)], 1);

            $hostResult['latency_quality'] = getQualityRating(
                $hostResult['latency_avg'],
                LATENCY_GOOD_MS,
                LATENCY_FAIR_MS,
                LATENCY_POOR_MS
            );
        } else {
            $hostResult['latency_min'] = null;
            $hostResult['latency_max'] = null;
            $hostResult['latency_avg'] = null;
            $hostResult['latency_p95'] = null;
            $hostResult['latency_quality'] = null;
        }

        // Jitter aggregation
        if (!empty($data['jitters'])) {
            $hostResult['jitter_avg'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
            $hostResult['jitter_max'] = round(max($data['jitters']), 2);
            $hostResult['jitter_quality'] = getQualityRating(
                $hostResult['jitter_avg'],
                JITTER_GOOD_MS,
                JITTER_FAIR_MS,
                JITTER_POOR_MS
            );
        } else {
            $hostResult['jitter_avg'] = null;
            $hostResult['jitter_max'] = null;
            $hostResult['jitter_quality'] = null;
        }

        // Packet loss calculation (window-based)
        $hostResult['packet_loss_pct'] = null;
        $hostResult['packet_loss_quality'] = null;

        if ($data['firstPlayerPackets'] !== null && $data['lastPlayerPackets'] !== null &&
            $data['firstRemotePackets'] !== null && $data['lastRemotePackets'] !== null) {

            $playerSentWindow = $data['lastPlayerPackets'] - $data['firstPlayerPackets'];
            $remoteReceivedWindow = $data['lastRemotePackets'] - $data['firstRemotePackets'];

            if ($playerSentWindow > 0) {
                // Calculate loss as percentage of packets not received
                $lossRatio = max(0, ($playerSentWindow - $remoteReceivedWindow) / $playerSentWindow);
                $hostResult['packet_loss_pct'] = round($lossRatio * 100, 2);
                $hostResult['packet_loss_quality'] = getQualityRating(
                    $hostResult['packet_loss_pct'],
                    PACKET_LOSS_GOOD_PCT,
                    PACKET_LOSS_FAIR_PCT,
                    PACKET_LOSS_POOR_PCT
                );
            }
        }

        // Overall quality
        $hostResult['overall_quality'] = getOverallQualityRating(
            $hostResult['latency_quality'] ?? 'good',
            $hostResult['jitter_quality'] ?? 'good',
            $hostResult['packet_loss_quality'] ?? 'good'
        );

        $aggregated[] = $hostResult;
    }

    return $aggregated;
}

/**
 * Aggregation function for rollup processing
 */
function aggregateNetworkQualityForRollup($bucketMetrics, $bucketStart, $interval) {
    $aggregated = aggregateNetworkQualityMetrics($bucketMetrics);

    if ($aggregated === null || empty($aggregated)) {
        return null;
    }

    // Add timestamps to each entry
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
 * Get or initialize network quality rollup state
 */
function getNetworkQualityRollupState() {
    return getRollupStateGeneric(WATCHERNETWORKQUALITYROLLUPSTATEFILE, WATCHERNETWORKQUALITYTIERS);
}

/**
 * Save network quality rollup state
 */
function saveNetworkQualityRollupState($state) {
    return saveRollupStateGeneric(WATCHERNETWORKQUALITYROLLUPSTATEFILE, $state);
}

/**
 * Process rollup for a specific tier
 */
function processNetworkQualityRollupTier($tier, $tierConfig) {
    processRollupTierGeneric(
        $tier,
        $tierConfig,
        WATCHERNETWORKQUALITYTIERS,
        WATCHERNETWORKQUALITYROLLUPSTATEFILE,
        WATCHERNETWORKQUALITYFILE,
        'getNetworkQualityRollupFilePath',
        'aggregateNetworkQualityForRollup'
    );
}

/**
 * Process all network quality rollup tiers
 */
function processAllNetworkQualityRollups() {
    foreach (WATCHERNETWORKQUALITYTIERS as $tier => $config) {
        try {
            processNetworkQualityRollupTier($tier, $config);
        } catch (Exception $e) {
            logMessage("ERROR processing network quality rollup tier {$tier}: " . $e->getMessage());
        }
    }
}

/**
 * Read network quality rollup data from a specific tier
 */
function readNetworkQualityRollupData($tier, $startTime = null, $endTime = null, $hostname = null) {
    $rollupFile = getNetworkQualityRollupFilePath($tier);

    $filterFn = null;
    if ($hostname !== null) {
        $filterFn = function($entry) use ($hostname) {
            return ($entry['hostname'] ?? '') === $hostname;
        };
    }

    $result = readRollupDataGeneric($rollupFile, $tier, WATCHERNETWORKQUALITYTIERS, $startTime, $endTime, $filterFn);

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
function getBestNetworkQualityRollupTier($hoursBack) {
    return getBestTierForHours($hoursBack);
}

/**
 * Get network quality metrics with automatic tier selection
 */
function getNetworkQualityMetrics($hoursBack = 24, $hostname = null) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $tier = getBestNetworkQualityRollupTier($hoursBack);
    $tierConfig = WATCHERNETWORKQUALITYTIERS[$tier];

    $result = readNetworkQualityRollupData($tier, $startTime, $endTime, $hostname);

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
 * Get current network quality status for all hosts
 * Returns the most recent metrics along with quality ratings
 */
function getNetworkQualityStatus() {
    // Get last hour of data for current status
    $result = getNetworkQualityMetrics(1);

    if (!$result['success'] || empty($result['data'])) {
        // Try to get from raw data if no rollup exists
        $rawMetrics = readRawNetworkQualityMetrics(time() - 3600);
        if (empty($rawMetrics)) {
            return [
                'success' => true,
                'timestamp' => time(),
                'hosts' => [],
                'summary' => [
                    'avgLatency' => null,
                    'avgJitter' => null,
                    'avgPacketLoss' => null,
                    'overallQuality' => 'unknown'
                ]
            ];
        }
        $aggregated = aggregateNetworkQualityMetrics($rawMetrics);
    } else {
        // Get the most recent entry per host
        $latestByHost = [];
        foreach ($result['data'] as $entry) {
            $hostname = $entry['hostname'];
            if (!isset($latestByHost[$hostname]) || $entry['timestamp'] > $latestByHost[$hostname]['timestamp']) {
                $latestByHost[$hostname] = $entry;
            }
        }
        $aggregated = array_values($latestByHost);
    }

    // Calculate summary statistics
    $totalLatency = 0;
    $totalJitter = 0;
    $totalPacketLoss = 0;
    $latencyCount = 0;
    $jitterCount = 0;
    $packetLossCount = 0;
    $worstQuality = 'good';

    $qualityOrder = ['good' => 0, 'fair' => 1, 'poor' => 2, 'critical' => 3];

    foreach ($aggregated as $host) {
        if (isset($host['latency_avg']) && $host['latency_avg'] !== null) {
            $totalLatency += $host['latency_avg'];
            $latencyCount++;
        }
        if (isset($host['jitter_avg']) && $host['jitter_avg'] !== null) {
            $totalJitter += $host['jitter_avg'];
            $jitterCount++;
        }
        if (isset($host['packet_loss_pct']) && $host['packet_loss_pct'] !== null) {
            $totalPacketLoss += $host['packet_loss_pct'];
            $packetLossCount++;
        }

        $hostQuality = $host['overall_quality'] ?? 'good';
        if (($qualityOrder[$hostQuality] ?? 0) > ($qualityOrder[$worstQuality] ?? 0)) {
            $worstQuality = $hostQuality;
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'hosts' => $aggregated,
        'summary' => [
            'avgLatency' => $latencyCount > 0 ? round($totalLatency / $latencyCount, 1) : null,
            'avgJitter' => $jitterCount > 0 ? round($totalJitter / $jitterCount, 2) : null,
            'avgPacketLoss' => $packetLossCount > 0 ? round($totalPacketLoss / $packetLossCount, 2) : null,
            'overallQuality' => $worstQuality
        ]
    ];
}

/**
 * Get network quality history for charting
 */
function getNetworkQualityHistory($hoursBack = 6, $hostname = null) {
    $result = getNetworkQualityMetrics($hoursBack, $hostname);

    if (!$result['success']) {
        return $result;
    }

    // Organize data for charting - separate series per metric type
    $chartData = [
        'labels' => [],
        'latency' => [],
        'jitter' => [],
        'packetLoss' => []
    ];

    // Group by timestamp for time series
    $byTimestamp = [];
    foreach ($result['data'] as $entry) {
        $ts = $entry['timestamp'];
        if (!isset($byTimestamp[$ts])) {
            $byTimestamp[$ts] = [];
        }
        $byTimestamp[$ts][] = $entry;
    }

    ksort($byTimestamp);

    // If hostname filter is set, return per-point data
    // Otherwise aggregate across hosts for each timestamp
    foreach ($byTimestamp as $ts => $entries) {
        $chartData['labels'][] = $ts * 1000; // JS timestamp

        if ($hostname !== null) {
            // Single host - use direct values
            $entry = $entries[0];
            $chartData['latency'][] = $entry['latency_avg'] ?? null;
            $chartData['jitter'][] = $entry['jitter_avg'] ?? null;
            $chartData['packetLoss'][] = $entry['packet_loss_pct'] ?? null;
        } else {
            // Multiple hosts - aggregate
            $latencies = [];
            $jitters = [];
            $losses = [];

            foreach ($entries as $entry) {
                if (isset($entry['latency_avg']) && $entry['latency_avg'] !== null) {
                    $latencies[] = $entry['latency_avg'];
                }
                if (isset($entry['jitter_avg']) && $entry['jitter_avg'] !== null) {
                    $jitters[] = $entry['jitter_avg'];
                }
                if (isset($entry['packet_loss_pct']) && $entry['packet_loss_pct'] !== null) {
                    $losses[] = $entry['packet_loss_pct'];
                }
            }

            $chartData['latency'][] = !empty($latencies) ? round(array_sum($latencies) / count($latencies), 1) : null;
            $chartData['jitter'][] = !empty($jitters) ? round(array_sum($jitters) / count($jitters), 2) : null;
            $chartData['packetLoss'][] = !empty($losses) ? round(array_sum($losses) / count($losses), 2) : null;
        }
    }

    return [
        'success' => true,
        'chartData' => $chartData,
        'tier_info' => $result['tier_info'] ?? null
    ];
}

/**
 * Get rollup tier information
 */
function getNetworkQualityRollupTiersInfo() {
    return getTiersInfoGeneric(WATCHERNETWORKQUALITYTIERS, 'getNetworkQualityRollupFilePath');
}

/**
 * Rotate raw metrics file
 */
function rotateNetworkQualityMetricsFile() {
    $metricsFile = WATCHERNETWORKQUALITYFILE;

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

    $cutoffTime = time() - WATCHERNETWORKQUALITYRETENTIONSECONDS;
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

        logMessage("Network quality metrics purge: removed {$purgedCount} old entries, kept " . count($recentMetrics) . " recent entries.");

        ensureFppOwnership($metricsFile);
        ensureFppOwnership($backupFile);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

?>
