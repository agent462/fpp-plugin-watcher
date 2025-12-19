<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;

/**
 * Abstract Base Class for Metrics Collectors
 *
 * Provides common functionality for RRD-style rollup of metrics into multiple
 * time resolutions for efficient long-term storage and querying.
 *
 * Subclasses must implement:
 * - initializePaths(): Set dataDir and metricsFile properties
 * - getStateFileSuffix(): Return the state file suffix (e.g., '/rollup-state.json')
 * - aggregateForRollup(): Aggregate metrics for a time bucket
 */
abstract class BaseMetricsCollector
{
    protected static ?self $instance = null;
    protected RollupProcessor $rollup;
    protected MetricsStorage $storage;
    protected Logger $logger;
    protected string $dataDir;
    protected string $metricsFile;

    /**
     * Protected constructor for singleton pattern
     */
    protected function __construct(
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->rollup = $rollup ?? new RollupProcessor();
        $this->storage = $storage ?? new MetricsStorage();
        $this->logger = $logger ?? Logger::getInstance();

        $this->initializePaths();
    }

    /**
     * Get singleton instance
     * Uses late static binding so each subclass maintains its own instance
     */
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * Initialize dataDir and metricsFile paths
     * Called from constructor, must set $this->dataDir and $this->metricsFile
     */
    abstract protected function initializePaths(): void;

    /**
     * Get the state file suffix (e.g., '/rollup-state.json')
     */
    abstract protected function getStateFileSuffix(): string;

    /**
     * Aggregation function for rollup processing
     * Returns aggregated data for a time bucket
     *
     * @param array $bucketMetrics Metrics within the time bucket
     * @param int $bucketStart Start timestamp of the bucket
     * @param int $interval Bucket interval in seconds
     * @return array|null Aggregated data or null if empty
     */
    abstract public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array;

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
        return $this->dataDir . $this->getStateFileSuffix();
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
     * Read raw metrics from the metrics file
     */
    public function readRawMetrics(int $sinceTimestamp = 0): array
    {
        return $this->storage->read($this->metricsFile, $sinceTimestamp);
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
     * Get metrics with automatic tier selection
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

    /**
     * Get the data directory path
     */
    public function getDataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * Get the metrics file path
     */
    public function getMetricsFilePath(): string
    {
        return $this->metricsFile;
    }
}
