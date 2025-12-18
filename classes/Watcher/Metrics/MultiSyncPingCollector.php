<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;

/**
 * Multi-Sync Ping Metrics Collection and Rollup
 *
 * Handles pinging remote multi-sync systems and storing
 * metrics in a similar format to the connectivity check ping metrics.
 * Metrics are stored per-host for historical analysis.
 */
class MultiSyncPingCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';
    private const METRICS_RETENTION_SECONDS = 25 * 60 * 60; // 25 hours

    private static ?self $instance = null;
    private RollupProcessor $rollup;
    private MetricsStorage $storage;
    private Logger $logger;
    private string $dataDir;
    private string $metricsFile;
    private array $jitterState = [];

    private function __construct(
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->rollup = $rollup ?? new RollupProcessor();
        $this->storage = $storage ?? new MetricsStorage();
        $this->logger = $logger ?? Logger::getInstance();

        // Use centralized data directory constant if available
        $this->dataDir = defined('WATCHERMULTISYNCPINGDIR')
            ? WATCHERMULTISYNCPINGDIR
            : '/home/fpp/media/logs/watcher-data/multisync-ping';
        $this->metricsFile = defined('WATCHERMULTISYNCPINGMETRICSFILE')
            ? WATCHERMULTISYNCPINGMETRICSFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher-multisync-ping-metrics.log';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Get rollup file path for a specific tier
     */
    public function getRollupFilePath(string $tier): string
    {
        return $this->dataDir . "/{$tier}.log";
    }

    /**
     * Get state file path
     */
    public function getStateFilePath(): string
    {
        return $this->dataDir . self::STATE_FILE_SUFFIX;
    }

    /**
     * Calculate jitter using RFC 3550 algorithm
     *
     * @param string $hostname Host identifier
     * @param float $latency Current latency measurement in ms
     * @return float|null Calculated jitter or null if first sample
     */
    public function calculateJitter(string $hostname, float $latency): ?float
    {
        return $this->rollup->calculateJitterRFC3550($hostname, $latency, $this->jitterState);
    }

    /**
     * Ping a single remote host and return results
     * Uses 2 second timeout for remote hosts which may have higher latency
     *
     * @param string $address IP address or hostname to ping
     * @param string $networkAdapter Network interface to use for ping
     * @return array Ping result with latency and status
     */
    public function pingRemoteHost(string $address, string $networkAdapter): array
    {
        // Use the global pingHost function
        return pingHost($address, $networkAdapter, 2);
    }

    /**
     * Ping all multi-sync remote systems and collect metrics
     *
     * @param array $remoteSystems Array of remote systems from getMultiSyncRemoteSystems()
     * @param string $networkAdapter Network interface to use for pings
     * @return array Results keyed by hostname
     */
    public function pingMultiSyncSystems(array $remoteSystems, string $networkAdapter): array
    {
        $checkTimestamp = time();
        $results = [];
        $metricsBuffer = [];

        foreach ($remoteSystems as $system) {
            $hostname = $system['hostname'] ?? '';
            $address = $system['address'] ?? '';

            if (empty($address)) {
                continue;
            }

            $pingResult = $this->pingRemoteHost($address, $networkAdapter);

            // Calculate jitter if we have a valid latency
            $jitter = null;
            if ($pingResult['success'] && $pingResult['latency'] !== null) {
                $jitter = $this->calculateJitter($hostname, $pingResult['latency']);
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

        $this->writeMetricsBatch($metricsBuffer);

        return $results;
    }

    /**
     * Write multiple metrics entries in a single file operation
     */
    public function writeMetricsBatch(array $entries): bool
    {
        return $this->storage->writeBatch($this->metricsFile, $entries);
    }

    /**
     * Rotate multi-sync metrics file to remove old entries
     */
    public function rotateMetricsFile(): void
    {
        $this->storage->rotate($this->metricsFile, self::METRICS_RETENTION_SECONDS);
    }

    /**
     * Get or initialize rollup state
     */
    public function getRollupState(): array
    {
        return $this->rollup->getState($this->getStateFilePath());
    }

    /**
     * Save rollup state to disk
     */
    public function saveRollupState(array $state): bool
    {
        return $this->rollup->saveState($this->getStateFilePath(), $state);
    }

    /**
     * Read raw multi-sync ping metrics from the metrics file
     */
    public function readRawMetrics(int $sinceTimestamp = 0): array
    {
        return $this->storage->read($this->metricsFile, $sinceTimestamp);
    }

    /**
     * Aggregate multi-sync metrics for a time period, grouped by hostname
     */
    public function aggregateMetrics(array $metrics): ?array
    {
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
     * Aggregation function for rollup processing
     * Returns array of entries (one per host) instead of single entry
     */
    public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array
    {
        $aggregated = $this->aggregateMetrics($bucketMetrics);

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
    public function processRollupTier(string $tier, array $tierConfig): void
    {
        $self = $this;
        $this->rollup->processTier(
            $tier,
            $tierConfig,
            $this->getStateFilePath(),
            $this->metricsFile,
            fn($t) => $self->getRollupFilePath($t),
            fn($metrics, $start, $interval) => $self->aggregateForRollup($metrics, $start, $interval)
        );
    }

    /**
     * Process all rollup tiers
     */
    public function processAllRollups(): void
    {
        foreach ($this->rollup->getTiers() as $tier => $config) {
            try {
                $this->processRollupTier($tier, $config);
            } catch (\Exception $e) {
                $this->logger->error("ERROR processing multi-sync rollup tier {$tier}: " . $e->getMessage());
            }
        }
    }

    /**
     * Read rollup data from a specific tier
     */
    public function readRollupData(string $tier, ?int $startTime = null, ?int $endTime = null, ?string $hostname = null): array
    {
        $rollupFile = $this->getRollupFilePath($tier);

        // Create hostname filter if specified
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
     * Get the best rollup tier for a given time range
     */
    public function getBestRollupTier(int $hoursBack): string
    {
        return $this->rollup->getBestTierForHours($hoursBack);
    }

    /**
     * Get multi-sync ping metrics with automatic tier selection
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
     * Get raw multi-sync ping metrics with filtering
     *
     * @param int $hoursBack Number of hours to look back
     * @param string|null $hostname Optional hostname filter
     * @return array Result with success, count, data, and period info
     */
    public function getRawMetrics(int $hoursBack = 24, ?string $hostname = null): array
    {
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

        $metrics = $this->readRawMetrics($startTime);

        // Filter by hostname if specified
        if ($hostname !== null) {
            $metrics = array_filter($metrics, function ($m) use ($hostname) {
                return ($m['hostname'] ?? '') === $hostname;
            });
            $metrics = array_values($metrics);
        }

        // Filter to requested time range
        $metrics = array_filter($metrics, function ($m) use ($startTime, $endTime) {
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
     * Get information about available rollup tiers
     *
     * @return array Tier info including interval, retention, and file status
     */
    public function getRollupTiersInfo(): array
    {
        $self = $this;
        return $this->rollup->getTiersInfo(fn($t) => $self->getRollupFilePath($t));
    }

    /**
     * Get the RollupProcessor instance
     */
    public function getRollupProcessor(): RollupProcessor
    {
        return $this->rollup;
    }

    /**
     * Get the MetricsStorage instance
     */
    public function getMetricsStorage(): MetricsStorage
    {
        return $this->storage;
    }
}
