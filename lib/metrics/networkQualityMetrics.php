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

// Network quality log file (using centralized data directory)
define("WATCHERNETWORKQUALITYFILE", WATCHERNETWORKQUALITYDIR . "/raw.log");
define("WATCHERNETWORKQUALITYROLLUPSTATEFILE", WATCHERNETWORKQUALITYDIR . "/rollup-state.json");

// Use shared tier configuration from rollupBase.php (WATCHER_ROLLUP_TIERS)
// Alias for backward compatibility with existing code
define("WATCHERNETWORKQUALITYTIERS", WATCHER_ROLLUP_TIERS);

// Quality thresholds are defined in rollupBase.php:
// WATCHER_LATENCY_THRESHOLDS, WATCHER_JITTER_THRESHOLDS, WATCHER_PACKET_LOSS_THRESHOLDS

// Retention period for raw metrics (25 hours)
define("WATCHERNETWORKQUALITYRETENTIONSECONDS", 25 * 60 * 60);

// Jitter state storage (per-host previous latency for RFC 3550 calculation)
$_networkQualityJitterState = [];

// Cache for sequence step times (avoids repeated API calls)
$_sequenceStepTimeCache = [];

/**
 * Get step time for a sequence (cached)
 * Returns step time in ms, or null if unavailable
 */
function getSequenceStepTime($sequenceName) {
    global $_sequenceStepTimeCache;

    if (empty($sequenceName)) {
        return null;
    }

    // Check cache first
    if (isset($_sequenceStepTimeCache[$sequenceName])) {
        return $_sequenceStepTimeCache[$sequenceName];
    }

    // Fetch from API (only on cache miss)
    $encoded = urlencode($sequenceName);
    $meta = apiCall('GET', "http://127.0.0.1/api/sequence/{$encoded}/meta", [], true, 2);

    if ($meta && isset($meta['StepTime'])) {
        $_sequenceStepTimeCache[$sequenceName] = intval($meta['StepTime']);
        return $_sequenceStepTimeCache[$sequenceName];
    }

    // Cache null to avoid repeated failed lookups
    $_sequenceStepTimeCache[$sequenceName] = null;
    return null;
}

/**
 * Calculate expected sync packet rate based on step time
 * FPP sends sync every 10 frames after frame 32
 */
function getExpectedSyncRate($stepTimeMs) {
    if ($stepTimeMs === null || $stepTimeMs <= 0) {
        return 2; // Default: assume 50ms (20fps) = 2 pkt/s
    }
    $fps = 1000 / $stepTimeMs;
    return $fps / 10; // Sync sent every 10 frames
}

/**
 * Get rollup file path for a specific tier
 */
function getNetworkQualityRollupFilePath($tier) {
    return WATCHERNETWORKQUALITYDIR . "/{$tier}.log";
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

    // Get sequence step time when playing (cached lookup)
    $stepTime = null;
    if ($isPlaying) {
        // Try 'sequence' first (from comparison data), then 'current_sequence' (from raw fppd/status)
        $currentSeq = $playerFppStatus['sequence'] ?? $playerFppStatus['current_sequence'] ?? '';
        if (!empty($currentSeq)) {
            $stepTime = getSequenceStepTime($currentSeq);
        }
    }

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
            'stepTime' => $stepTime,
            'pluginInstalled' => $remote['pluginInstalled']
        ];

        // Add quality ratings
        if ($latency !== null) {
            $metricEntry['latencyQuality'] = getQualityRatingGeneric($latency, WATCHER_LATENCY_THRESHOLDS['good'], WATCHER_LATENCY_THRESHOLDS['fair'], WATCHER_LATENCY_THRESHOLDS['poor']);
        }
        if ($jitter !== null) {
            $metricEntry['jitterQuality'] = getQualityRatingGeneric($jitter, WATCHER_JITTER_THRESHOLDS['good'], WATCHER_JITTER_THRESHOLDS['fair'], WATCHER_JITTER_THRESHOLDS['poor']);
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
                'jitters' => [],  // Pre-calculated jitter values from raw data
                'stepTimes' => [],
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

        // Collect pre-calculated jitter from raw data (used when not enough samples for recalculation)
        if (isset($entry['jitter']) && $entry['jitter'] !== null) {
            $byHost[$hostname]['jitters'][] = floatval($entry['jitter']);
        }

        // Track packet counts ONLY from samples where player was actively playing
        // This prevents false packet loss spikes during idle->playing transitions
        if ($isPlaying) {
            $byHost[$hostname]['playingSampleCount']++;
            $remotePkts = $entry['remotePacketsReceived'] ?? null;
            $stepTime = $entry['stepTime'] ?? null;

            // Track step times for expected rate calculation
            if ($stepTime !== null) {
                $byHost[$hostname]['stepTimes'][] = $stepTime;
            }

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

            $hostResult['latency_quality'] = getQualityRatingGeneric(
                $hostResult['latency_avg'],
                WATCHER_LATENCY_THRESHOLDS['good'],
                WATCHER_LATENCY_THRESHOLDS['fair'],
                WATCHER_LATENCY_THRESHOLDS['poor']
            );

            // Calculate jitter from time-ordered latencies (not sorted)
            // Falls back to pre-calculated jitter values from raw data if not enough latency samples
            $jitterResult = calculateJitterFromLatencies($data['latencies']);
            if ($jitterResult !== null) {
                $hostResult['jitter_avg'] = $jitterResult['avg'];
                $hostResult['jitter_max'] = $jitterResult['max'];
            } else if (!empty($data['jitters'])) {
                // Use pre-calculated jitter values from raw data
                $hostResult['jitter_avg'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
                $hostResult['jitter_max'] = round(max($data['jitters']), 2);
            } else {
                $hostResult['jitter_avg'] = null;
                $hostResult['jitter_max'] = null;
            }

            if ($hostResult['jitter_avg'] !== null) {
                $hostResult['jitter_quality'] = getQualityRatingGeneric(
                    $hostResult['jitter_avg'],
                    WATCHER_JITTER_THRESHOLDS['good'],
                    WATCHER_JITTER_THRESHOLDS['fair'],
                    WATCHER_JITTER_THRESHOLDS['poor']
                );
            } else {
                $hostResult['jitter_quality'] = null;
            }
        } else {
            $hostResult['latency_min'] = null;
            $hostResult['latency_max'] = null;
            $hostResult['latency_avg'] = null;
            $hostResult['latency_p95'] = null;
            $hostResult['latency_quality'] = null;
            // Still check for pre-calculated jitter even without latency data
            if (!empty($data['jitters'])) {
                $hostResult['jitter_avg'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
                $hostResult['jitter_max'] = round(max($data['jitters']), 2);
                $hostResult['jitter_quality'] = getQualityRatingGeneric(
                    $hostResult['jitter_avg'],
                    WATCHER_JITTER_THRESHOLDS['good'],
                    WATCHER_JITTER_THRESHOLDS['fair'],
                    WATCHER_JITTER_THRESHOLDS['poor']
                );
            } else {
                $hostResult['jitter_avg'] = null;
                $hostResult['jitter_max'] = null;
                $hostResult['jitter_quality'] = null;
            }
        }

        // Packet loss estimation based on receive rate during playback
        // Note: playerPacketsSent counts ALL packet types (channel data, sync, media, commands)
        // while remotePacketsReceived only counts sync packets - direct comparison is invalid.
        // Instead, we use receive rate stability during playback as a proxy for network quality.
        $hostResult['packet_loss_pct'] = null;
        $hostResult['packet_loss_quality'] = null;
        $hostResult['receive_rate'] = null;

        // Use playing-only timestamps and packet counts
        $playingTimeWindow = 0;
        if ($data['firstPlayingTimestamp'] !== null && $data['lastPlayingTimestamp'] !== null) {
            $playingTimeWindow = $data['lastPlayingTimestamp'] - $data['firstPlayingTimestamp'];
        }

        // Need at least 2 playing samples and meaningful time window
        if ($data['firstPlayingPackets'] !== null && $data['lastPlayingPackets'] !== null &&
            $data['playingSampleCount'] >= 2 && $playingTimeWindow > 0) {

            $remoteReceivedDelta = $data['lastPlayingPackets'] - $data['firstPlayingPackets'];

            // Handle counter reset (plugin restart) - delta goes negative
            if ($remoteReceivedDelta < 0) {
                // Counter was reset, can't calculate meaningful rate
                $hostResult['receive_rate'] = null;
                $hostResult['packet_loss_pct'] = null;
                $hostResult['packet_loss_quality'] = null;
            } else {
                // Calculate receive rate (sync packets per second during playback)
                $receiveRate = $remoteReceivedDelta / $playingTimeWindow;
                $hostResult['receive_rate'] = round($receiveRate, 1);

                // FPP rate-limits sync packets: every 4 frames initially, then every 10 frames
                // after frame 32. See MultiSync.cpp SendSeqSyncPacket() for details.
                // Expected rate = fps / 10 (e.g., 20fps = 2 pkt/s, 40fps = 4 pkt/s)
                //
                // Use median step time from samples to calculate expected rate
                $stepTimes = $data['stepTimes'] ?? [];
                if (!empty($stepTimes)) {
                    sort($stepTimes);
                    $medianStepTime = $stepTimes[intval(count($stepTimes) / 2)];
                    $expectedRate = getExpectedSyncRate($medianStepTime);
                } else {
                    $expectedRate = 2; // Default: assume 50ms (20fps) = 2 pkt/s
                }

                if ($receiveRate >= $expectedRate) {
                    // At or above expected steady-state - no loss
                    $hostResult['packet_loss_pct'] = 0.0;
                } else if ($receiveRate >= $expectedRate * 0.5) {
                    // 50-100% of expected - some loss
                    $hostResult['packet_loss_pct'] = round((1 - $receiveRate / $expectedRate) * 100, 1);
                } else if ($receiveRate >= 0.5) {
                    // Below 50% of expected - significant loss
                    $hostResult['packet_loss_pct'] = round((1 - $receiveRate / $expectedRate) * 100, 1);
                } else if ($receiveRate >= 0.1) {
                    // Almost no packets - severe loss
                    $hostResult['packet_loss_pct'] = round((1 - $receiveRate / $expectedRate) * 100, 1);
                } else {
                    // No packets at all
                    $hostResult['packet_loss_pct'] = 100.0;
                }

                $hostResult['packet_loss_quality'] = getQualityRatingGeneric(
                    $hostResult['packet_loss_pct'],
                    WATCHER_PACKET_LOSS_THRESHOLDS['good'],
                    WATCHER_PACKET_LOSS_THRESHOLDS['fair'],
                    WATCHER_PACKET_LOSS_THRESHOLDS['poor']
                );
            }
        }
        // If not enough playing samples, leave packet_loss as null (not enough data)

        // Overall quality
        $hostResult['overall_quality'] = getOverallQualityRatingGeneric(
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
 * For time ranges up to 6 hours, uses raw data to ensure jitter values.
 * For longer ranges, uses rollup data for efficiency.
 */
function getNetworkQualityHistory($hoursBack = 6, $hostname = null) {
    // For time ranges up to 6 hours, use raw data to ensure we have jitter values
    // (rollup buckets may only have 1 sample which isn't enough for jitter calculation)
    if ($hoursBack <= 6) {
        return getNetworkQualityHistoryFromRaw($hoursBack, $hostname);
    }

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
 * Get network quality history from raw data (for short time ranges)
 * Calculates packet loss across consecutive samples for accuracy
 */
function getNetworkQualityHistoryFromRaw($hoursBack, $hostname = null) {
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $rawMetrics = readRawNetworkQualityMetrics($startTime);

    // Filter by hostname if specified
    if ($hostname !== null) {
        $rawMetrics = array_filter($rawMetrics, function($entry) use ($hostname) {
            return ($entry['hostname'] ?? '') === $hostname;
        });
        $rawMetrics = array_values($rawMetrics);
    }

    if (empty($rawMetrics)) {
        return [
            'success' => true,
            'chartData' => ['labels' => [], 'latency' => [], 'jitter' => [], 'packetLoss' => []],
            'tier_info' => ['tier' => 'raw', 'interval' => 60, 'label' => '1 minute (raw)']
        ];
    }

    // Sort by timestamp
    usort($rawMetrics, function($a, $b) {
        return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
    });

    // Group by host and calculate packet loss between consecutive playing samples
    $byHost = [];
    foreach ($rawMetrics as $entry) {
        $host = $entry['hostname'] ?? 'unknown';
        if (!isset($byHost[$host])) {
            $byHost[$host] = [];
        }
        $byHost[$host][] = $entry;
    }

    // Calculate packet loss for each sample based on consecutive playing samples
    $packetLossByHostTime = [];
    foreach ($byHost as $host => $samples) {
        $prevPlaying = null;
        foreach ($samples as $sample) {
            $ts = $sample['timestamp'] ?? 0;
            $isPlaying = !empty($sample['isPlaying']);
            $remotePkts = $sample['remotePacketsReceived'] ?? null;
            $stepTime = $sample['stepTime'] ?? null;

            if ($isPlaying && $remotePkts !== null && $prevPlaying !== null) {
                $timeDelta = $ts - $prevPlaying['timestamp'];
                $pktDelta = $remotePkts - $prevPlaying['packets'];

                // Handle counter reset (negative delta)
                if ($pktDelta >= 0 && $timeDelta > 0) {
                    $receiveRate = $pktDelta / $timeDelta;
                    $expectedRate = getExpectedSyncRate($stepTime ?? $prevPlaying['stepTime'] ?? 25);

                    if ($receiveRate >= $expectedRate) {
                        $packetLossByHostTime[$host][$ts] = 0.0;
                    } else if ($expectedRate > 0) {
                        $loss = (1 - $receiveRate / $expectedRate) * 100;
                        $packetLossByHostTime[$host][$ts] = min(100, max(0, round($loss, 1)));
                    }
                }
            }

            if ($isPlaying && $remotePkts !== null) {
                $prevPlaying = [
                    'timestamp' => $ts,
                    'packets' => $remotePkts,
                    'stepTime' => $stepTime
                ];
            }
        }
    }

    // Group raw samples into 1-minute buckets
    $buckets = [];
    foreach ($rawMetrics as $entry) {
        $ts = $entry['timestamp'] ?? 0;
        if ($ts < $startTime) continue;

        $bucketTs = floor($ts / 60) * 60;
        if (!isset($buckets[$bucketTs])) {
            $buckets[$bucketTs] = [];
        }
        $buckets[$bucketTs][] = $entry;
    }

    ksort($buckets);

    // Build chart data
    $chartData = [
        'labels' => [],
        'latency' => [],
        'jitter' => [],
        'packetLoss' => []
    ];

    foreach ($buckets as $bucketTs => $bucketMetrics) {
        $chartData['labels'][] = $bucketTs * 1000; // JS timestamp

        $latencies = [];
        $jitters = [];
        $losses = [];

        foreach ($bucketMetrics as $entry) {
            $host = $entry['hostname'] ?? 'unknown';
            $ts = $entry['timestamp'] ?? 0;

            if (isset($entry['latency']) && $entry['latency'] !== null) {
                $latencies[] = floatval($entry['latency']);
            }
            if (isset($entry['jitter']) && $entry['jitter'] !== null) {
                $jitters[] = floatval($entry['jitter']);
            }
            // Use pre-calculated packet loss for this sample
            if (isset($packetLossByHostTime[$host][$ts])) {
                $losses[] = $packetLossByHostTime[$host][$ts];
            }
        }

        $chartData['latency'][] = !empty($latencies) ? round(array_sum($latencies) / count($latencies), 1) : null;
        $chartData['jitter'][] = !empty($jitters) ? round(array_sum($jitters) / count($jitters), 2) : null;
        $chartData['packetLoss'][] = !empty($losses) ? round(array_sum($losses) / count($losses), 1) : null;
    }

    return [
        'success' => true,
        'chartData' => $chartData,
        'tier_info' => ['tier' => 'raw', 'interval' => 60, 'label' => '1 minute (raw)']
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
