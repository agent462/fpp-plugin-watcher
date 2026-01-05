<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;
use Watcher\Controllers\VoltageHardware;

/**
 * Voltage Metrics Storage and Rollup
 *
 * Handles storage of voltage readings with simplified 24-hour retention.
 * Raw data is collected every 3 seconds and rolled up to 1-minute averages.
 */
class VoltageCollector extends BaseMetricsCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';
    private const RAW_RETENTION = 21600; // 6 hours of raw data
    private const DEFAULT_RETENTION_DAYS = 1;

    protected static ?self $instance = null;
    private FileManager $fileManager;
    private string $stateFile;
    private ?int $retentionDays = null;

    /**
     * Override constructor for additional FileManager dependency
     */
    protected function __construct(
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null,
        ?FileManager $fileManager = null
    ) {
        $this->storage = $storage ?? new MetricsStorage();
        $this->logger = $logger ?? Logger::getInstance();
        $this->fileManager = $fileManager ?? FileManager::getInstance();

        $this->initializePaths();

        // Lazy initialization of rollup processor (with custom tiers)
        if ($rollup !== null) {
            $this->rollup = $rollup;
        }
    }

    /**
     * Initialize data directory and metrics file paths
     */
    protected function initializePaths(): void
    {
        $this->dataDir = defined('WATCHERVOLTAGEDIR')
            ? WATCHERVOLTAGEDIR
            : '/home/fpp/media/plugindata/fpp-plugin-watcher/voltage';
        $this->metricsFile = defined('WATCHERVOLTAGERAWFILE')
            ? WATCHERVOLTAGERAWFILE
            : $this->dataDir . '/raw.log';
        $this->stateFile = $this->dataDir . self::STATE_FILE_SUFFIX;
    }

    /**
     * Get state file suffix
     */
    protected function getStateFileSuffix(): string
    {
        return self::STATE_FILE_SUFFIX;
    }

    /**
     * Override getStateFilePath to use the stored property
     */
    public function getStateFilePath(): string
    {
        return $this->stateFile;
    }

    /**
     * Get or create the rollup processor with custom tiers
     */
    public function getRollupProcessor(): RollupProcessor
    {
        if (!isset($this->rollup)) {
            $this->rollup = new RollupProcessor($this->getTiers());
        }
        return $this->rollup;
    }

    /**
     * Get voltage rollup tiers based on configured retention
     * Tiers are capped by the retention period
     */
    public function getTiers(?int $retentionDays = null): array
    {
        if ($retentionDays === null) {
            $config = readPluginConfig();
            $retentionDays = $config['voltageRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS;
        }

        $this->retentionDays = $retentionDays;
        $retentionSeconds = $retentionDays * 86400;

        // Build tiers based on retention period
        // For 1 day: just 1min tier
        // For longer periods: add higher tiers for efficiency
        $tiers = [
            '1min' => [
                'interval' => 60,
                'retention' => min(21600, $retentionSeconds), // 6 hours max for 1min
                'label' => '1-minute averages'
            ]
        ];

        // Add 5min tier if retention > 1 day
        if ($retentionDays > 1) {
            $tiers['5min'] = [
                'interval' => 300,
                'retention' => min(172800, $retentionSeconds), // 48 hours max
                'label' => '5-minute averages'
            ];
        }

        // Add 30min tier if retention > 3 days
        if ($retentionDays > 3) {
            $tiers['30min'] = [
                'interval' => 1800,
                'retention' => min(604800, $retentionSeconds), // 7 days max
                'label' => '30-minute averages'
            ];
        }

        // Add 2hour tier if retention > 7 days
        if ($retentionDays > 7) {
            $tiers['2hour'] = [
                'interval' => 7200,
                'retention' => $retentionSeconds, // Full retention
                'label' => '2-hour averages'
            ];
        }

        return $tiers;
    }

    /**
     * Select best tier for requested time range
     */
    public function getBestTierForHours(int $hours): string
    {
        $tiers = $this->getTiers();

        // Select tier based on time range and available tiers
        if ($hours <= 6 || !isset($tiers['5min'])) {
            return '1min';
        }
        if ($hours <= 48 || !isset($tiers['30min'])) {
            return '5min';
        }
        if ($hours <= 168 || !isset($tiers['2hour'])) { // 7 days
            return '30min';
        }
        return '2hour';
    }

    /**
     * Write a raw voltage reading (legacy single-value method for compatibility)
     *
     * @param float $voltage Core voltage value
     * @return bool Success
     */
    public function writeRawMetric(float $voltage): bool
    {
        return $this->writeRawMetrics(['core' => $voltage]);
    }

    /**
     * Write raw voltage readings for all rails
     *
     * @param array $voltages Associative array of rail => voltage values
     * @return bool Success
     */
    public function writeRawMetrics(array $voltages): bool
    {
        if (empty($voltages)) {
            return false;
        }

        $entry = [
            'timestamp' => time(),
            'voltages' => $voltages
        ];

        $jsonData = json_encode($entry);

        // Ensure directory exists
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }

        $fp = @fopen($this->metricsFile, 'a');
        if (!$fp) {
            $this->logger->error("Unable to open voltage raw metrics file for writing");
            return false;
        }

        $success = false;
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, "{$jsonData}\n");
            fflush($fp);
            flock($fp, LOCK_UN);
            $success = true;
        }

        fclose($fp);
        $this->fileManager->ensureFppOwnership($this->metricsFile);

        return $success;
    }

    /**
     * Read raw voltage metrics from log file
     */
    public function readRawMetrics(int $hoursBack = 6): array
    {
        $sinceTimestamp = time() - ($hoursBack * 3600);
        return FileManager::getInstance()->readJsonLinesFile($this->metricsFile, $sinceTimestamp);
    }

    /**
     * Process all voltage rollup tiers
     */
    public function processRollup(): void
    {
        $processor = $this->getRollupProcessor();
        $tiers = $this->getTiers();
        $self = $this;

        foreach ($tiers as $tierName => $tierConfig) {
            // Determine source: raw data for 1min, previous tier for higher tiers
            if ($tierName === '1min') {
                $sourceFile = $this->metricsFile;
            } else {
                // Higher tiers aggregate from the previous tier
                $tierOrder = array_keys($tiers);
                $tierIndex = array_search($tierName, $tierOrder);
                $previousTier = $tierOrder[$tierIndex - 1];
                $sourceFile = $this->getRollupFilePath($previousTier);
            }

            $processor->processTier(
                $tierName,
                $tierConfig,
                $this->stateFile,
                $sourceFile,
                fn($t) => $self->getRollupFilePath($t),
                fn($metrics, $start, $interval) => $self->aggregateBucket($metrics, $start, $interval)
            );
        }

        // Rotate the raw file
        $this->storage->rotate($this->metricsFile, self::RAW_RETENTION);
    }

    /**
     * Aggregate voltage metrics for a time bucket
     * Handles both raw metrics and rollup metrics for multiple voltage rails
     *
     * Data formats:
     * - Raw: {timestamp, voltages: {rail1: value, rail2: value, ...}}
     * - Legacy raw: {timestamp, voltage: value} (single core voltage)
     * - Rollup: {timestamp, voltages: {rail1: {avg, min, max, samples}, ...}}
     * - Legacy rollup: {timestamp, voltage: {avg, min, max, samples}}
     */
    public function aggregateBucket(array $metrics, int $bucketStart, int $interval): ?array
    {
        if (empty($metrics)) {
            return null;
        }

        // Collect all voltage values by rail
        $railData = [];

        foreach ($metrics as $metric) {
            // Handle new multi-rail format
            if (isset($metric['voltages']) && is_array($metric['voltages'])) {
                foreach ($metric['voltages'] as $rail => $value) {
                    if (!isset($railData[$rail])) {
                        $railData[$rail] = ['values' => [], 'mins' => [], 'maxs' => [], 'samples' => 0];
                    }

                    if (is_array($value)) {
                        // Rollup entry
                        $railData[$rail]['values'][] = $value['avg'] ?? 0;
                        $railData[$rail]['mins'][] = $value['min'] ?? 0;
                        $railData[$rail]['maxs'][] = $value['max'] ?? 0;
                        $railData[$rail]['samples'] += $value['samples'] ?? 1;
                    } else {
                        // Raw entry
                        $railData[$rail]['values'][] = $value;
                        $railData[$rail]['mins'][] = $value;
                        $railData[$rail]['maxs'][] = $value;
                        $railData[$rail]['samples'] += 1;
                    }
                }
            }
            // Handle legacy single-voltage format for backwards compatibility
            elseif (isset($metric['voltage'])) {
                $voltage = $metric['voltage'];
                $rail = 'core'; // Legacy format was always core voltage

                if (!isset($railData[$rail])) {
                    $railData[$rail] = ['values' => [], 'mins' => [], 'maxs' => [], 'samples' => 0];
                }

                if (is_array($voltage)) {
                    // Legacy rollup entry
                    $railData[$rail]['values'][] = $voltage['avg'] ?? 0;
                    $railData[$rail]['mins'][] = $voltage['min'] ?? 0;
                    $railData[$rail]['maxs'][] = $voltage['max'] ?? 0;
                    $railData[$rail]['samples'] += $voltage['samples'] ?? 1;
                } else {
                    // Legacy raw entry
                    $railData[$rail]['values'][] = $voltage;
                    $railData[$rail]['mins'][] = $voltage;
                    $railData[$rail]['maxs'][] = $voltage;
                    $railData[$rail]['samples'] += 1;
                }
            }
        }

        if (empty($railData)) {
            return null;
        }

        // Aggregate each rail
        $voltages = [];
        foreach ($railData as $rail => $data) {
            if (empty($data['values'])) {
                continue;
            }
            $count = count($data['values']);
            $voltages[$rail] = [
                'avg' => round(array_sum($data['values']) / $count, 4),
                'min' => round(min($data['mins']), 4),
                'max' => round(max($data['maxs']), 4),
                'samples' => $data['samples']
            ];
        }

        if (empty($voltages)) {
            return null;
        }

        return [
            'timestamp' => $bucketStart,
            'interval' => $interval,
            'voltages' => $voltages
        ];
    }

    /**
     * Aggregation function for rollup processing (interface compatibility)
     */
    public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array
    {
        return $this->aggregateBucket($bucketMetrics, $bucketStart, $interval);
    }

    /**
     * Read voltage rollup data
     */
    public function readRollup(int $hoursBack = 24): array
    {
        $processor = $this->getRollupProcessor();
        $bestTier = $this->getBestTierForHours($hoursBack);
        $tiers = $this->getTiers();
        $tierOrder = array_keys($tiers);
        $interval = $tiers[$bestTier]['interval'] ?? 60;

        // Align start/end times to interval boundaries
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);
        $startTime = intval(floor($startTime / $interval) * $interval);

        // Try preferred tier first, fall back to lower tiers if file doesn't exist
        $selectedTier = $bestTier;
        $selectedFile = $this->getRollupFilePath($bestTier);

        if (!file_exists($selectedFile)) {
            $tierIndex = array_search($bestTier, $tierOrder);
            for ($i = $tierIndex - 1; $i >= 0; $i--) {
                $fallbackTier = $tierOrder[$i];
                $fallbackFile = $this->getRollupFilePath($fallbackTier);
                if (file_exists($fallbackFile)) {
                    $selectedTier = $fallbackTier;
                    $selectedFile = $fallbackFile;
                    break;
                }
            }
        }

        $result = $processor->readRollupData(
            $selectedFile,
            $selectedTier,
            $startTime,
            $endTime
        );

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
     * Get current voltage status with throttle info for all rails
     */
    public function getCurrentStatus(): array
    {
        $hw = VoltageHardware::getInstance();
        $hardware = $hw->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'supported' => false,
                'error' => 'Voltage monitoring not supported on this platform'
            ];
        }

        $reading = $hw->readAllVoltages();
        $throttle = $hw->getThrottleStatus();

        if (!$reading['success']) {
            return [
                'success' => false,
                'supported' => true,
                'error' => $reading['error'] ?? 'Failed to read voltages'
            ];
        }

        // Get core voltage for backwards compatibility
        $coreVoltage = $reading['voltages']['VDD_CORE_V']
            ?? $reading['voltages']['core']
            ?? reset($reading['voltages']);

        return [
            'success' => true,
            'supported' => true,
            'timestamp' => time(),
            'voltage' => $coreVoltage, // Legacy: single core voltage
            'voltages' => $reading['voltages'], // New: all voltage rails
            'labels' => $reading['labels'], // Human-readable labels
            'throttled' => $throttle['throttled'] ?? false,
            'flags' => $throttle['flags'] ?? '0x0',
            'undervoltage_now' => $throttle['details']['undervoltage_now'] ?? false,
            'undervoltage_occurred' => $throttle['details']['undervoltage_occurred'] ?? false
        ];
    }

    /**
     * Get voltage metrics for display (base class compatibility)
     */
    public function getMetrics(int $hoursBack = 24): array
    {
        return $this->getMetricsWithFractionalHours((float)$hoursBack);
    }

    /**
     * Get voltage metrics with fractional hours support
     * Returns raw data for short time ranges (≤1 hour) for maximum granularity
     */
    public function getMetricsWithFractionalHours(float $hoursBack = 24): array
    {
        // Cap to configured retention max (in hours)
        $config = readPluginConfig();
        $retentionDays = $config['voltageRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS;
        $maxHours = $retentionDays * 24;
        $hoursBack = min($hoursBack, $maxHours);

        // Get labels for the voltage rails
        $labels = VoltageHardware::getInstance()->getRailLabels();

        // For short time ranges (1 hour or less), return raw data for maximum granularity
        if ($hoursBack <= 1) {
            $rawData = $this->readRawMetrics(max(1, (int)ceil($hoursBack)));
            if (!empty($rawData)) {
                // Filter to exact time range
                $sinceTimestamp = time() - ($hoursBack * 3600);
                $filteredData = array_filter($rawData, fn($r) => $r['timestamp'] >= $sinceTimestamp);

                // Normalize data format (handle legacy single-voltage format)
                $normalizedData = array_map(function($entry) {
                    // Convert legacy format {voltage: X} to {voltages: {core: X}}
                    if (isset($entry['voltage']) && !isset($entry['voltages'])) {
                        $entry['voltages'] = ['core' => $entry['voltage']];
                        unset($entry['voltage']);
                    }
                    return $entry;
                }, array_values($filteredData));

                return [
                    'success' => true,
                    'data' => $normalizedData,
                    'labels' => $labels,
                    'rails' => array_keys($labels),
                    'tier_info' => [
                        'tier' => 'raw',
                        'interval' => null,
                        'label' => 'Raw readings'
                    ]
                ];
            }
        }

        $result = $this->readRollup((int)$hoursBack);
        if ($result['success']) {
            $result['labels'] = $labels;
            $result['rails'] = array_keys($labels);
        }
        return $result;
    }

    /**
     * Get configured voltage settings
     */
    public function getConfig(): array
    {
        $config = readPluginConfig();
        return [
            'collectionInterval' => $config['voltageCollectionInterval'] ?? 3,
            'retentionDays' => $config['voltageRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS
        ];
    }

    /**
     * Get rollup tier information
     */
    public function getRollupTiersInfo(): array
    {
        $processor = $this->getRollupProcessor();
        $self = $this;
        return $processor->getTiersInfo(fn($t) => $self->getRollupFilePath($t));
    }

    /**
     * Get the rollup state
     */
    public function getRollupState(): array
    {
        return $this->getRollupProcessor()->getState($this->stateFile);
    }
}
