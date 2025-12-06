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
include_once __DIR__ . "/../multisync/comparison.php";

// Network quality log file
define("WATCHERNETWORKQUALITYFILE", WATCHERLOGDIR . "/" . WATCHERPLUGINNAME . "-network-quality.log");
define("WATCHERNETWORKQUALITYROLLUPSTATEFILE", WATCHERLOGDIR . "/" . WATCHERPLUGINNAME . "-network-quality-rollup-state.json");

// Use shared tier configuration from rollupBase.php (WATCHER_ROLLUP_TIERS)
// Alias for backward compatibility with existing code
define("WATCHERNETWORKQUALITYTIERS", WATCHER_ROLLUP_TIERS);

// Quality thresholds - use shared constants from rollupBase.php
define('LATENCY_GOOD_MS', WATCHER_LATENCY_THRESHOLDS['good']);
define('LATENCY_FAIR_MS', WATCHER_LATENCY_THRESHOLDS['fair']);
define('LATENCY_POOR_MS', WATCHER_LATENCY_THRESHOLDS['poor']);

define('JITTER_GOOD_MS', WATCHER_JITTER_THRESHOLDS['good']);
define('JITTER_FAIR_MS', WATCHER_JITTER_THRESHOLDS['fair']);
define('JITTER_POOR_MS', WATCHER_JITTER_THRESHOLDS['poor']);

define('PACKET_LOSS_GOOD_PCT', WATCHER_PACKET_LOSS_THRESHOLDS['good']);
define('PACKET_LOSS_FAIR_PCT', WATCHER_PACKET_LOSS_THRESHOLDS['fair']);
define('PACKET_LOSS_POOR_PCT', WATCHER_PACKET_LOSS_THRESHOLDS['poor']);

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
 * Delegates to shared function in rollupBase.php
 *
 * @param string $hostname Host identifier
 * @param float $latency Current latency measurement in ms
 * @param array &$state Jitter state reference
 * @return float|null Calculated jitter or null if first sample
 */
function calculateJitterRFC3550($hostname, $latency, &$state) {
    return calculateJitterRFC3550Generic($hostname, $latency, $state);
}

/**
 * Get quality rating based on metric value and thresholds
 * Delegates to shared function in rollupBase.php
 */
function getQualityRating($value, $good, $fair, $poor) {
    return getQualityRatingGeneric($value, $good, $fair, $poor);
}

/**
 * Get overall quality rating from individual ratings
 * Delegates to shared function in rollupBase.php
 */
function getOverallQualityRating($latencyRating, $jitterRating, $packetLossRating) {
    return getOverallQualityRatingGeneric($latencyRating, $jitterRating, $packetLossRating);
}

// Expected sync packet rate during playback (packets per second)
// FPP sends sync packets at ~40Hz (25ms interval) during sequence playback
define('EXPECTED_SYNC_RATE_PER_SECOND', 40);

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
    $playerFppStatus = $comparison['player']['fppStatus'] ?? [];
    $isPlaying = ($playerFppStatus['status'] ?? '') === 'playing';

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

        // Packet counts for loss calculation
        $remotePacketsReceived = $remoteMetrics['totalPacketsReceived'] ?? null;

        $metricEntry = [
            'timestamp' => $timestamp,
            'hostname' => $hostname,
            'address' => $address,
            'latency' => $latency,
            'jitter' => $jitter,
            'playerPacketsSent' => $playerPacketsSent,
            'remotePacketsReceived' => $remotePacketsReceived,
            'isPlaying' => $isPlaying,
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
 * Delegates to shared function in rollupBase.php
 */
function writeNetworkQualityMetrics($entries) {
    return writeMetricsBatchGeneric(WATCHERNETWORKQUALITYFILE, $entries);
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
 * Calculate jitter from consecutive latency samples using RFC 3550 algorithm
 * Delegates to shared function in rollupBase.php
 */
function calculateJitterFromLatencies($latencies) {
    return calculateJitterFromLatencyArray($latencies);
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

    // Sort by timestamp to ensure correct order for jitter calculation
    usort($metrics, function($a, $b) {
        return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
    });

    // Group by hostname (maintaining order for jitter calculation)
    $byHost = [];
    foreach ($metrics as $entry) {
        $hostname = $entry['hostname'] ?? 'unknown';
        $ts = $entry['timestamp'] ?? 0;
        $isPlaying = !empty($entry['isPlaying']);

        if (!isset($byHost[$hostname])) {
            $byHost[$hostname] = [
                'latencies' => [],
                'address' => $entry['address'] ?? '',
                'firstTimestamp' => $ts,
                'lastTimestamp' => $ts,
                // Track packet counts ONLY during playback to avoid idle->playing transition spikes
                'firstPlayingTimestamp' => null,
                'lastPlayingTimestamp' => null,
                'firstPlayingPackets' => null,
                'lastPlayingPackets' => null,
                'playingSampleCount' => 0,
                'sample_count' => 0
            ];
        }

        $byHost[$hostname]['sample_count']++;
        $byHost[$hostname]['lastTimestamp'] = $ts;

        if (isset($entry['latency']) && $entry['latency'] !== null) {
            $byHost[$hostname]['latencies'][] = floatval($entry['latency']);
        }

        // Track packet counts ONLY from samples where player was actively playing
        // This prevents false packet loss spikes during idle->playing transitions
        if ($isPlaying) {
            $byHost[$hostname]['playingSampleCount']++;
            $remotePkts = $entry['remotePacketsReceived'] ?? null;

            if ($remotePkts !== null) {
                if ($byHost[$hostname]['firstPlayingPackets'] === null) {
                    $byHost[$hostname]['firstPlayingTimestamp'] = $ts;
                    $byHost[$hostname]['firstPlayingPackets'] = $remotePkts;
                }
                $byHost[$hostname]['lastPlayingTimestamp'] = $ts;
                $byHost[$hostname]['lastPlayingPackets'] = $remotePkts;
            }
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
            $sortedLatencies = $data['latencies'];
            sort($sortedLatencies);
            $count = count($sortedLatencies);
            $hostResult['latency_min'] = round(min($sortedLatencies), 1);
            $hostResult['latency_max'] = round(max($sortedLatencies), 1);
            $hostResult['latency_avg'] = round(array_sum($sortedLatencies) / $count, 1);

            // P95 latency
            $p95Index = (int)ceil($count * 0.95) - 1;
            $hostResult['latency_p95'] = round($sortedLatencies[max(0, $p95Index)], 1);

            $hostResult['latency_quality'] = getQualityRating(
                $hostResult['latency_avg'],
                LATENCY_GOOD_MS,
                LATENCY_FAIR_MS,
                LATENCY_POOR_MS
            );

            // Calculate jitter from time-ordered latencies (not sorted)
            $jitterResult = calculateJitterFromLatencies($data['latencies']);
            if ($jitterResult !== null) {
                $hostResult['jitter_avg'] = $jitterResult['avg'];
                $hostResult['jitter_max'] = $jitterResult['max'];
                $hostResult['jitter_quality'] = getQualityRating(
                    $jitterResult['avg'],
                    JITTER_GOOD_MS,
                    JITTER_FAIR_MS,
                    JITTER_POOR_MS
                );
            } else {
                $hostResult['jitter_avg'] = null;
                $hostResult['jitter_max'] = null;
                $hostResult['jitter_quality'] = null;
            }
        } else {
            $hostResult['latency_min'] = null;
            $hostResult['latency_max'] = null;
            $hostResult['latency_avg'] = null;
            $hostResult['latency_p95'] = null;
            $hostResult['latency_quality'] = null;
            $hostResult['jitter_avg'] = null;
            $hostResult['jitter_max'] = null;
            $hostResult['jitter_quality'] = null;
        }

        // Packet loss calculation - rate stability analysis
        // Only calculate from samples where player was actively playing
        // This prevents false spikes during idle->playing transitions
        $hostResult['packet_loss_pct'] = null;
        $hostResult['packet_loss_quality'] = null;
        $hostResult['receive_rate'] = null;

        // Use playing-only timestamps and packet counts
        $playingTimeWindow = 0;
        if ($data['firstPlayingTimestamp'] !== null && $data['lastPlayingTimestamp'] !== null) {
            $playingTimeWindow = $data['lastPlayingTimestamp'] - $data['firstPlayingTimestamp'];
        }

        // Need at least 2 playing samples (for first and last) to calculate rate
        if ($data['firstPlayingPackets'] !== null && $data['lastPlayingPackets'] !== null &&
            $data['playingSampleCount'] >= 2 && $playingTimeWindow > 0) {

            $receivedInWindow = $data['lastPlayingPackets'] - $data['firstPlayingPackets'];
            $receiveRate = $receivedInWindow / $playingTimeWindow; // packets per second
            $hostResult['receive_rate'] = round($receiveRate, 1);

            // FPP typically sends 8-12 sync packets/second during active playback
            $minExpectedRate = 5; // Minimum acceptable packets/second

            if ($receiveRate < 0.1) {
                // Essentially no packets received during playback
                $hostResult['packet_loss_pct'] = 100.0;
            } else if ($receiveRate < $minExpectedRate) {
                // Below expected rate - calculate approximate loss
                $hostResult['packet_loss_pct'] = round((1 - $receiveRate / $minExpectedRate) * 100, 1);
            } else {
                // Good receive rate
                $hostResult['packet_loss_pct'] = 0.0;
            }

            $hostResult['packet_loss_quality'] = getQualityRating(
                $hostResult['packet_loss_pct'],
                PACKET_LOSS_GOOD_PCT,
                PACKET_LOSS_FAIR_PCT,
                PACKET_LOSS_POOR_PCT
            );
        }
        // If not enough playing samples, leave packet_loss as null (not enough data)

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
    // Use raw data for current status (last hour) since it has more samples
    // and allows proper jitter calculation from consecutive measurements
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
 * Delegates to shared function in rollupBase.php
 */
function rotateNetworkQualityMetricsFile() {
    rotateRawMetricsFileGeneric(WATCHERNETWORKQUALITYFILE, WATCHERNETWORKQUALITYRETENTIONSECONDS);
}

?>
