<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * eFuse Metrics Storage and Rollup
 *
 * Handles storage of eFuse current readings with time-based rollup aggregation.
 * Raw data is collected every 5 seconds and rolled up to 1-minute averages.
 */
class EfuseCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';
    private const RAW_RETENTION = 21600; // 6 hours
    private const DEFAULT_RETENTION_DAYS = 7;

    private static ?self $instance = null;
    private ?RollupProcessor $rollup = null;
    private MetricsStorage $storage;
    private Logger $logger;
    private FileManager $fileManager;
    private string $dataDir;
    private string $rawFile;
    private string $stateFile;
    private ?int $retentionDays = null;

    private function __construct(
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null,
        ?FileManager $fileManager = null
    ) {
        $this->storage = $storage ?? new MetricsStorage();
        $this->logger = $logger ?? Logger::getInstance();
        $this->fileManager = $fileManager ?? FileManager::getInstance();

        // Use centralized data directory constant if available
        $this->dataDir = defined('WATCHEREFUSEDIR')
            ? WATCHEREFUSEDIR
            : '/home/fpp/media/logs/watcher-data/efuse';
        $this->rawFile = defined('WATCHEREFUSERAWFILE')
            ? WATCHEREFUSERAWFILE
            : $this->dataDir . '/raw.log';
        $this->stateFile = defined('WATCHEREFUSEROLLUPSTATEFILE')
            ? WATCHEREFUSEROLLUPSTATEFILE
            : $this->dataDir . self::STATE_FILE_SUFFIX;

        // If a rollup processor was provided, use it
        if ($rollup !== null) {
            $this->rollup = $rollup;
        }
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Get or create the rollup processor with custom tiers
     */
    public function getRollupProcessor(): RollupProcessor
    {
        if ($this->rollup === null) {
            $this->rollup = new RollupProcessor($this->getTiers());
        }
        return $this->rollup;
    }

    /**
     * Get eFuse rollup tiers based on configured retention
     * Tiers are capped by the retention period
     */
    public function getTiers(?int $retentionDays = null): array
    {
        if ($retentionDays === null) {
            $config = readPluginConfig();
            $retentionDays = $config['efuseRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS;
        }

        $this->retentionDays = $retentionDays;
        $retentionSeconds = $retentionDays * 86400;

        return [
            '1min' => [
                'interval' => 60,
                'retention' => min(21600, $retentionSeconds),
                'label' => '1-minute averages'
            ],
            '5min' => [
                'interval' => 300,
                'retention' => min(172800, $retentionSeconds),
                'label' => '5-minute averages'
            ],
            '30min' => [
                'interval' => 1800,
                'retention' => min(1209600, $retentionSeconds),
                'label' => '30-minute averages'
            ],
            '2hour' => [
                'interval' => 7200,
                'retention' => $retentionSeconds,
                'label' => '2-hour averages'
            ]
        ];
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
        return $this->stateFile;
    }

    /**
     * Select best tier for requested time range
     */
    public function getBestTierForHours(int $hours): string
    {
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
    public function writeRawMetric(array $ports): bool
    {
        if (empty($ports)) {
            return true; // Nothing to write
        }

        // Calculate total from all ports
        $total = array_sum($ports);
        $ports['_total'] = $total;

        $entry = [
            'timestamp' => time(),
            'ports' => $ports
        ];

        $timestamp = date('Y-m-d H:i:s');
        $jsonData = json_encode($entry);

        $fp = @fopen($this->rawFile, 'a');
        if (!$fp) {
            // Ensure directory exists
            ensureDataDirectories();
            $fp = @fopen($this->rawFile, 'a');
            if (!$fp) {
                $this->logger->error("Unable to open eFuse raw metrics file for writing");
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
        $this->fileManager->ensureFppOwnership($this->rawFile);

        return $success;
    }

    /**
     * Read raw eFuse metrics from log file
     */
    public function readRawMetrics(int $hoursBack = 6, ?string $portFilter = null): array
    {
        $sinceTimestamp = time() - ($hoursBack * 3600);

        $filterFn = null;
        if ($portFilter !== null) {
            $filterFn = function ($entry) use ($portFilter) {
                return isset($entry['ports'][$portFilter]);
            };
        }

        return readJsonLinesFile($this->rawFile, $sinceTimestamp, $filterFn);
    }

    /**
     * Process all eFuse rollup tiers
     */
    public function processRollup(): void
    {
        $processor = $this->getRollupProcessor();
        $tiers = $this->getTiers();
        $self = $this;

        foreach ($tiers as $tierName => $tierConfig) {
            // Determine source: raw data for 1min, previous tier for higher tiers
            if ($tierName === '1min') {
                $sourceFile = $this->rawFile;
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
        $this->storage->rotate($this->rawFile, self::RAW_RETENTION);
    }

    /**
     * Aggregate eFuse metrics for a time bucket
     * Handles both raw metrics (port value is integer) and rollup metrics (port value is object)
     */
    public function aggregateBucket(array $metrics, int $bucketStart, int $interval): ?array
    {
        if (empty($metrics)) {
            return null;
        }

        $portData = [];

        foreach ($metrics as $metric) {
            $ports = $metric['ports'] ?? [];
            foreach ($ports as $portName => $mA) {
                if (!isset($portData[$portName])) {
                    $portData[$portName] = ['values' => [], 'mins' => [], 'maxs' => [], 'samples' => 0];
                }

                if (is_array($mA)) {
                    // Rollup entry
                    $portData[$portName]['values'][] = $mA['avg'] ?? 0;
                    $portData[$portName]['mins'][] = $mA['min'] ?? 0;
                    $portData[$portName]['maxs'][] = $mA['max'] ?? 0;
                    $portData[$portName]['samples'] += $mA['samples'] ?? 1;
                } else {
                    // Raw entry
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
                'peak' => max($maxs),
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
     */
    public function readRollup(int $hoursBack = 24, ?string $portFilter = null): array
    {
        $processor = $this->getRollupProcessor();
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

        $bestTier = $this->getBestTierForHours($hoursBack);
        $tiers = $this->getTiers();
        $tierOrder = array_keys($tiers);

        $filterFn = null;
        if ($portFilter !== null) {
            $filterFn = function ($entry) use ($portFilter) {
                return isset($entry['ports'][$portFilter]);
            };
        }

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
            $endTime,
            $filterFn
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
     * Get current eFuse status with port details
     */
    public function getCurrentStatus(): array
    {
        $hardware = \Watcher\Controllers\EfuseHardware::getInstance()->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'supported' => false,
                'error' => 'No compatible eFuse hardware detected'
            ];
        }

        $reading = \Watcher\Controllers\EfuseHardware::getInstance()->readEfuseData();

        if (!$reading['success']) {
            return [
                'success' => false,
                'supported' => true,
                'error' => $reading['error'] ?? 'Failed to read eFuse data'
            ];
        }

        $efuseHardware = \Watcher\Controllers\EfuseHardware::getInstance();
        $efuseOutputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance();

        $outputConfig = $efuseOutputConfig->getOutputConfig();
        $portSummary = $efuseHardware->getPortCurrentSummary($reading['ports']);
        $totals = $efuseHardware->calculateTotalCurrent($reading['ports']);

        return [
            'success' => true,
            'supported' => true,
            'timestamp' => $reading['timestamp'],
            'hardware' => $efuseHardware->getSummary(),
            'ports' => $portSummary,
            'totals' => $totals,
            'outputConfig' => $outputConfig
        ];
    }

    /**
     * Get historical eFuse data for a specific port
     */
    public function getPortHistory(string $portName, int $hoursBack = 24): array
    {
        $tierInfo = null;

        // Use raw data for very short periods
        if ($hoursBack <= 1) {
            $data = $this->readRawMetrics($hoursBack, $portName);
            $source = 'raw';
            $config = readPluginConfig();
            $interval = $config['efuseCollectionInterval'] ?? 5;
        } else {
            $result = $this->readRollup($hoursBack, $portName);
            $data = $result['data'] ?? [];
            $source = 'rollup';
            $tierInfo = $result['tier_info'] ?? null;
            $interval = $tierInfo['interval'] ?? 60;
        }

        // Build indexed lookup by timestamp
        $dataByTimestamp = [];
        foreach ($data as $entry) {
            $ts = $entry['timestamp'];
            $alignedTs = intval(floor($ts / $interval) * $interval);
            $dataByTimestamp[$alignedTs] = $entry['ports'][$portName] ?? null;
        }

        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);
        $startTime = intval(floor($startTime / $interval) * $interval);
        $endTime = intval(floor($endTime / $interval) * $interval);

        $history = [];
        for ($ts = $startTime; $ts <= $endTime; $ts += $interval) {
            $portData = $dataByTimestamp[$ts] ?? null;

            if ($portData !== null) {
                if (is_array($portData)) {
                    $history[] = [
                        'timestamp' => $ts,
                        'avg' => $portData['avg'] ?? 0,
                        'min' => $portData['min'] ?? 0,
                        'max' => $portData['max'] ?? 0
                    ];
                } else {
                    $history[] = [
                        'timestamp' => $ts,
                        'value' => $portData
                    ];
                }
            } else {
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

        $portConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getPortOutputConfig($portName);

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
     */
    public function getHeatmapData(int $hoursBack = 24): array
    {
        $hardware = \Watcher\Controllers\EfuseHardware::getInstance()->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'error' => 'No compatible eFuse hardware detected'
            ];
        }

        $result = $this->readRollup($hoursBack);
        $rollupData = $result['data'] ?? [];
        $tierInfo = $result['tier_info'] ?? null;

        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();
        $portNames = array_keys($outputConfig['ports']);

        // Build indexed lookup
        $dataByTimestamp = [];
        foreach ($rollupData as $entry) {
            $ts = $entry['timestamp'];
            $dataByTimestamp[$ts] = $entry['ports'] ?? [];
        }

        $interval = $tierInfo['interval'] ?? 60;
        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);
        $startTime = intval(floor($startTime / $interval) * $interval);
        $endTime = intval(floor($endTime / $interval) * $interval) - (2 * $interval);

        $allTimestamps = [];
        for ($ts = $startTime; $ts <= $endTime; $ts += $interval) {
            $allTimestamps[] = $ts;
        }

        $timeSeries = [];
        $peaks = [];

        // Include _total if present
        $allPortsIncludingTotal = $portNames;
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

        // Extract total history
        $totalHistory = [];
        if (isset($timeSeries['_total'])) {
            $totalHistory = $timeSeries['_total'];
            unset($timeSeries['_total']);
        }
        unset($peaks['_total']);

        return [
            'success' => true,
            'hours' => $hoursBack,
            'hardware' => \Watcher\Controllers\EfuseHardware::getInstance()->getHardwareSummary(),
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

    /**
     * Get configured eFuse settings
     */
    public function getConfig(): array
    {
        $config = readPluginConfig();
        return [
            'collectionInterval' => $config['efuseCollectionInterval'] ?? 5,
            'retentionDays' => $config['efuseRetentionDays'] ?? self::DEFAULT_RETENTION_DAYS
        ];
    }

    /**
     * Get the MetricsStorage instance
     */
    public function getMetricsStorage(): MetricsStorage
    {
        return $this->storage;
    }
}
