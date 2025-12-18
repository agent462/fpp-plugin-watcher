<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;

/**
 * Ping Metrics Collection and Rollup
 *
 * Handles RRD-style rollup of ping metrics into multiple time resolutions
 * for efficient long-term storage and querying.
 */
class PingCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';

    private static ?self $instance = null;
    private RollupProcessor $rollup;
    private MetricsStorage $storage;
    private Logger $logger;
    private string $dataDir;
    private string $metricsFile;

    private function __construct(
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->rollup = $rollup ?? new RollupProcessor();
        $this->storage = $storage ?? new MetricsStorage();
        $this->logger = $logger ?? Logger::getInstance();

        // Use centralized data directory constant if available
        $this->dataDir = defined('WATCHERPINGDIR')
            ? WATCHERPINGDIR
            : '/home/fpp/media/logs/watcher-data/ping';
        $this->metricsFile = defined('WATCHERPINGMETRICSFILE')
            ? WATCHERPINGMETRICSFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log';
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
     * Read raw ping metrics from the metrics file
     */
    public function readRawMetrics(int $sinceTimestamp = 0): array
    {
        return $this->storage->read($this->metricsFile, $sinceTimestamp);
    }

    /**
     * Aggregate metrics into a time period
     * Returns aggregated statistics for the period
     */
    public function aggregateMetrics(array $metrics): ?array
    {
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
     * Aggregation function for rollup processing
     */
    public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array
    {
        $aggregated = $this->aggregateMetrics($bucketMetrics);

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
     * Append rollup entries to a rollup file
     */
    public function appendRollupEntries(string $rollupFile, array $entries): bool
    {
        return $this->rollup->appendRollupEntries($rollupFile, $entries);
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
                $this->logger->error("ERROR processing rollup tier {$tier}: " . $e->getMessage());
            }
        }
    }

    /**
     * Read rollup data from a specific tier
     */
    public function readRollupData(string $tier, ?int $startTime = null, ?int $endTime = null): array
    {
        $rollupFile = $this->getRollupFilePath($tier);
        return $this->rollup->readRollupData($rollupFile, $tier, $startTime, $endTime);
    }

    /**
     * Get the best rollup tier for a given time range
     */
    public function getBestRollupTier(int $hoursBack): string
    {
        return $this->rollup->getBestTierForHours($hoursBack);
    }

    /**
     * Get ping metrics with automatic tier selection
     */
    public function getMetrics(int $hoursBack = 24): array
    {
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

        $tier = $this->getBestRollupTier($hoursBack);
        $tiers = $this->rollup->getTiers();
        $tierConfig = $tiers[$tier];

        $result = $this->readRollupData($tier, $startTime, $endTime);

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
