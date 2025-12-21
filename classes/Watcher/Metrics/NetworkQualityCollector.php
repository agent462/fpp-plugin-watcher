<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;
use Watcher\Http\ApiClient;

/**
 * Network Quality Metrics Collection and Rollup
 *
 * Tracks network quality metrics for multi-sync remotes:
 * - Packet loss percentage (sync packets sent vs received)
 * - Round-trip latency (HTTP API response time)
 * - Jitter (latency variance using RFC 3550 algorithm)
 */
class NetworkQualityCollector extends BaseMetricsCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';
    private const METRICS_RETENTION_SECONDS = 25 * 60 * 60; // 25 hours
    private const EXPECTED_SYNC_RATE_PER_SECOND = 40;

    protected static ?self $instance = null;
    private array $jitterState = [];
    private array $sequenceStepTimeCache = [];

    /**
     * Initialize data directory and metrics file paths
     */
    protected function initializePaths(): void
    {
        $this->dataDir = defined('WATCHERNETWORKQUALITYDIR')
            ? WATCHERNETWORKQUALITYDIR
            : '/home/fpp/media/logs/watcher-data/network-quality';
        $this->metricsFile = $this->dataDir . '/raw.log';
    }

    /**
     * Get state file suffix
     */
    protected function getStateFileSuffix(): string
    {
        return self::STATE_FILE_SUFFIX;
    }

    /**
     * Get step time for a sequence (cached)
     * Returns step time in ms, or null if unavailable
     */
    public function getSequenceStepTime(string $sequenceName): ?int
    {
        if (empty($sequenceName)) {
            return null;
        }

        // Check cache first
        if (isset($this->sequenceStepTimeCache[$sequenceName])) {
            return $this->sequenceStepTimeCache[$sequenceName];
        }

        // Fetch from API (only on cache miss)
        $encoded = urlencode($sequenceName);
        $meta = ApiClient::getInstance()->get("http://127.0.0.1/api/sequence/{$encoded}/meta", 2);

        if ($meta && isset($meta['StepTime'])) {
            $this->sequenceStepTimeCache[$sequenceName] = intval($meta['StepTime']);
            return $this->sequenceStepTimeCache[$sequenceName];
        }

        // Cache null to avoid repeated failed lookups
        $this->sequenceStepTimeCache[$sequenceName] = null;
        return null;
    }

    /**
     * Calculate expected sync packet rate based on step time
     * FPP sends sync every 10 frames after frame 32
     */
    public function getExpectedSyncRate(?int $stepTimeMs): float
    {
        if ($stepTimeMs === null || $stepTimeMs <= 0) {
            return 2; // Default: assume 50ms (20fps) = 2 pkt/s
        }
        $fps = 1000 / $stepTimeMs;
        return $fps / 10; // Sync sent every 10 frames
    }

    /**
     * Calculate jitter using RFC 3550 algorithm
     */
    public function calculateJitter(string $hostname, float $latency): ?float
    {
        return $this->rollup->calculateJitterRFC3550($hostname, $latency, $this->jitterState);
    }

    /**
     * Calculate jitter from consecutive latency samples
     */
    public function calculateJitterFromLatencies(array $latencies): ?array
    {
        return $this->rollup->calculateJitterFromLatencyArray($latencies);
    }

    /**
     * Collect network quality metrics from all remote systems
     * Uses data from comparison API (response time) and sync metrics (packet counts)
     *
     * @return array Collected metrics
     */
    public function collectMetrics(): array
    {
        $timestamp = time();
        $metrics = [];

        // Get comparison data (includes response times)
        $comparison = \Watcher\MultiSync\Comparator::getInstance()->getComparison(getMultiSyncRemoteSystems());

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
                $stepTime = $this->getSequenceStepTime($currentSeq);
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
                $jitter = $this->calculateJitter($hostname, $latency);
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
                $metricEntry['latencyQuality'] = $this->rollup->getQualityRating(
                    $latency,
                    RollupProcessor::LATENCY_THRESHOLDS['good'],
                    RollupProcessor::LATENCY_THRESHOLDS['fair'],
                    RollupProcessor::LATENCY_THRESHOLDS['poor']
                );
            }
            if ($jitter !== null) {
                $metricEntry['jitterQuality'] = $this->rollup->getQualityRating(
                    $jitter,
                    RollupProcessor::JITTER_THRESHOLDS['good'],
                    RollupProcessor::JITTER_THRESHOLDS['fair'],
                    RollupProcessor::JITTER_THRESHOLDS['poor']
                );
            }

            $metrics[] = $metricEntry;
        }

        // Write metrics to log file
        if (!empty($metrics)) {
            $this->writeMetrics($metrics);
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
     */
    public function writeMetrics(array $entries): bool
    {
        return $this->storage->writeBatch($this->metricsFile, $entries);
    }

    /**
     * Aggregate network quality metrics for a time period, grouped by hostname
     */
    public function aggregateMetrics(array $metrics): ?array
    {
        if (empty($metrics)) {
            return null;
        }

        // Sort by timestamp to ensure correct order for jitter calculation
        usort($metrics, function ($a, $b) {
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
                    'jitters' => [],
                    'stepTimes' => [],
                    'address' => $entry['address'] ?? '',
                    'firstTimestamp' => $ts,
                    'lastTimestamp' => $ts,
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

            if (isset($entry['jitter']) && $entry['jitter'] !== null) {
                $byHost[$hostname]['jitters'][] = floatval($entry['jitter']);
            }

            // Track packet counts ONLY from samples where player was actively playing
            if ($isPlaying) {
                $byHost[$hostname]['playingSampleCount']++;
                $remotePkts = $entry['remotePacketsReceived'] ?? null;
                $stepTime = $entry['stepTime'] ?? null;

                if ($stepTime !== null) {
                    $byHost[$hostname]['stepTimes'][] = $stepTime;
                }

                if ($remotePkts !== null) {
                    // Detect counter reset: if current packets < last packets, counter was reset
                    // Reset our tracking to start fresh from this point
                    if ($byHost[$hostname]['lastPlayingPackets'] !== null &&
                        $remotePkts < $byHost[$hostname]['lastPlayingPackets']) {
                        // Counter reset detected - restart tracking from this sample
                        $byHost[$hostname]['firstPlayingTimestamp'] = $ts;
                        $byHost[$hostname]['firstPlayingPackets'] = $remotePkts;
                        $byHost[$hostname]['playingSampleCount'] = 1;
                        $byHost[$hostname]['stepTimes'] = $stepTime !== null ? [$stepTime] : [];
                    } elseif ($byHost[$hostname]['firstPlayingPackets'] === null) {
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

                $hostResult['latency_quality'] = $this->rollup->getQualityRating(
                    $hostResult['latency_avg'],
                    RollupProcessor::LATENCY_THRESHOLDS['good'],
                    RollupProcessor::LATENCY_THRESHOLDS['fair'],
                    RollupProcessor::LATENCY_THRESHOLDS['poor']
                );

                // Calculate jitter from time-ordered latencies
                $jitterResult = $this->calculateJitterFromLatencies($data['latencies']);
                if ($jitterResult !== null) {
                    $hostResult['jitter_avg'] = $jitterResult['avg'];
                    $hostResult['jitter_max'] = $jitterResult['max'];
                } else if (!empty($data['jitters'])) {
                    $hostResult['jitter_avg'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
                    $hostResult['jitter_max'] = round(max($data['jitters']), 2);
                } else {
                    $hostResult['jitter_avg'] = null;
                    $hostResult['jitter_max'] = null;
                }

                if ($hostResult['jitter_avg'] !== null) {
                    $hostResult['jitter_quality'] = $this->rollup->getQualityRating(
                        $hostResult['jitter_avg'],
                        RollupProcessor::JITTER_THRESHOLDS['good'],
                        RollupProcessor::JITTER_THRESHOLDS['fair'],
                        RollupProcessor::JITTER_THRESHOLDS['poor']
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
                if (!empty($data['jitters'])) {
                    $hostResult['jitter_avg'] = round(array_sum($data['jitters']) / count($data['jitters']), 2);
                    $hostResult['jitter_max'] = round(max($data['jitters']), 2);
                    $hostResult['jitter_quality'] = $this->rollup->getQualityRating(
                        $hostResult['jitter_avg'],
                        RollupProcessor::JITTER_THRESHOLDS['good'],
                        RollupProcessor::JITTER_THRESHOLDS['fair'],
                        RollupProcessor::JITTER_THRESHOLDS['poor']
                    );
                } else {
                    $hostResult['jitter_avg'] = null;
                    $hostResult['jitter_max'] = null;
                    $hostResult['jitter_quality'] = null;
                }
            }

            // Packet loss estimation
            $hostResult['packet_loss_pct'] = null;
            $hostResult['packet_loss_quality'] = null;
            $hostResult['receive_rate'] = null;

            $playingTimeWindow = 0;
            if ($data['firstPlayingTimestamp'] !== null && $data['lastPlayingTimestamp'] !== null) {
                $playingTimeWindow = $data['lastPlayingTimestamp'] - $data['firstPlayingTimestamp'];
            }

            if ($data['firstPlayingPackets'] !== null && $data['lastPlayingPackets'] !== null &&
                $data['playingSampleCount'] >= 2 && $playingTimeWindow > 0) {

                $remoteReceivedDelta = $data['lastPlayingPackets'] - $data['firstPlayingPackets'];

                if ($remoteReceivedDelta < 0) {
                    // Counter was reset
                    $hostResult['receive_rate'] = null;
                    $hostResult['packet_loss_pct'] = null;
                    $hostResult['packet_loss_quality'] = null;
                } else {
                    $receiveRate = $remoteReceivedDelta / $playingTimeWindow;
                    $hostResult['receive_rate'] = round($receiveRate, 1);

                    $stepTimes = $data['stepTimes'] ?? [];
                    if (!empty($stepTimes)) {
                        sort($stepTimes);
                        $medianStepTime = $stepTimes[intval(count($stepTimes) / 2)];
                        $expectedRate = $this->getExpectedSyncRate($medianStepTime);
                    } else {
                        $expectedRate = 2;
                    }

                    if ($receiveRate >= $expectedRate) {
                        $hostResult['packet_loss_pct'] = 0.0;
                    } else if ($receiveRate >= 0.1) {
                        $hostResult['packet_loss_pct'] = round((1 - $receiveRate / $expectedRate) * 100, 1);
                    } else {
                        $hostResult['packet_loss_pct'] = 100.0;
                    }

                    $hostResult['packet_loss_quality'] = $this->rollup->getQualityRating(
                        $hostResult['packet_loss_pct'],
                        RollupProcessor::PACKET_LOSS_THRESHOLDS['good'],
                        RollupProcessor::PACKET_LOSS_THRESHOLDS['fair'],
                        RollupProcessor::PACKET_LOSS_THRESHOLDS['poor']
                    );
                }
            }

            // Overall quality
            $hostResult['overall_quality'] = $this->rollup->getOverallQualityRating(
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
    public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array
    {
        $aggregated = $this->aggregateMetrics($bucketMetrics);

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
     * Read rollup data from a specific tier with optional hostname filtering
     */
    public function readRollupData(string $tier, ?int $startTime = null, ?int $endTime = null, ?string $hostname = null): array
    {
        $rollupFile = $this->getRollupFilePath($tier);

        $filterFn = null;
        if ($hostname !== null) {
            $filterFn = function ($entry) use ($hostname) {
                return ($entry['hostname'] ?? '') === $hostname;
            };
        }

        $result = $this->rollup->readRollupData($rollupFile, $tier, $startTime, $endTime, $filterFn);

        // Sort by timestamp and hostname
        if ($result['success'] && !empty($result['data'])) {
            usort($result['data'], function ($a, $b) {
                $timeDiff = $a['timestamp'] - $b['timestamp'];
                if ($timeDiff !== 0) return $timeDiff;
                return strcmp($a['hostname'] ?? '', $b['hostname'] ?? '');
            });
        }

        return $result;
    }

    /**
     * Get network quality metrics with automatic tier selection
     */
    public function getMetrics(int $hoursBack = 24, ?string $hostname = null): array
    {
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

        $tier = $this->getBestRollupTier($hoursBack);
        $tiers = $this->rollup->getTiers();
        $tierConfig = $tiers[$tier];

        $result = $this->readRollupData($tier, $startTime, $endTime, $hostname);

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
     */
    public function getStatus(): array
    {
        $rawMetrics = $this->readRawMetrics(time() - 3600);

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

        $aggregated = $this->aggregateMetrics($rawMetrics);

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
    public function getHistory(int $hoursBack = 6, ?string $hostname = null): array
    {
        // For time ranges up to 6 hours, use raw data for jitter accuracy
        if ($hoursBack <= 6) {
            return $this->getHistoryFromRaw($hoursBack, $hostname);
        }

        $result = $this->getMetrics($hoursBack, $hostname);

        if (!$result['success']) {
            return $result;
        }

        // Organize data for charting
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

        foreach ($byTimestamp as $ts => $entries) {
            $chartData['labels'][] = $ts * 1000; // JS timestamp

            if ($hostname !== null) {
                $entry = $entries[0];
                $chartData['latency'][] = $entry['latency_avg'] ?? null;
                $chartData['jitter'][] = $entry['jitter_avg'] ?? null;
                $chartData['packetLoss'][] = $entry['packet_loss_pct'] ?? null;
            } else {
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
     */
    public function getHistoryFromRaw(int $hoursBack, ?string $hostname = null): array
    {
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

        $rawMetrics = $this->readRawMetrics($startTime);

        if ($hostname !== null) {
            $rawMetrics = array_filter($rawMetrics, function ($entry) use ($hostname) {
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

        usort($rawMetrics, function ($a, $b) {
            return ($a['timestamp'] ?? 0) - ($b['timestamp'] ?? 0);
        });

        // Group by host and calculate packet loss
        $byHost = [];
        foreach ($rawMetrics as $entry) {
            $host = $entry['hostname'] ?? 'unknown';
            if (!isset($byHost[$host])) {
                $byHost[$host] = [];
            }
            $byHost[$host][] = $entry;
        }

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

                    if ($pktDelta >= 0 && $timeDelta > 0) {
                        $receiveRate = $pktDelta / $timeDelta;
                        $expectedRate = $this->getExpectedSyncRate($stepTime ?? $prevPlaying['stepTime'] ?? 25);

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

        // Group into 1-minute buckets
        $buckets = [];
        foreach ($rawMetrics as $entry) {
            $ts = $entry['timestamp'] ?? 0;
            if ($ts < $startTime) continue;

            $bucketTs = (int)floor($ts / 60) * 60;
            if (!isset($buckets[$bucketTs])) {
                $buckets[$bucketTs] = [];
            }
            $buckets[$bucketTs][] = $entry;
        }

        ksort($buckets);

        $chartData = [
            'labels' => [],
            'latency' => [],
            'jitter' => [],
            'packetLoss' => []
        ];

        foreach ($buckets as $bucketTs => $bucketMetrics) {
            $chartData['labels'][] = $bucketTs * 1000;

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
     * Rotate raw metrics file
     */
    public function rotateMetricsFile(): void
    {
        $this->storage->rotate($this->metricsFile, self::METRICS_RETENTION_SECONDS);
    }
}
