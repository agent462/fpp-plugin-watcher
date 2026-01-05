<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\FileManager;
use Watcher\Core\Logger;

/**
 * Generic RRD-style rollup processor for all metric types
 *
 * Handles:
 * - Tier configuration and selection
 * - State management for rollup processing
 * - Quality ratings (latency, jitter, packet loss)
 * - Jitter calculation (RFC 3550)
 * - Latency aggregation
 * - Rollup file operations
 *
 * Migrated from lib/metrics/rollupBase.php
 */
class RollupProcessor
{
    /**
     * Standard rollup tier configuration used by all metric types
     * Tiers: 1min, 5min, 30min, 2hour with increasing retention periods
     */
    public const TIERS = [
        '1min' => [
            'interval' => 60,           // 1 minute buckets
            'retention' => 21600,       // 6 hours
            'label' => '1-minute averages'
        ],
        '5min' => [
            'interval' => 300,          // 5 minute buckets
            'retention' => 172800,      // 48 hours
            'label' => '5-minute averages'
        ],
        '30min' => [
            'interval' => 1800,         // 30 minute buckets
            'retention' => 1209600,     // 14 days
            'label' => '30-minute averages'
        ],
        '2hour' => [
            'interval' => 7200,         // 2 hour buckets
            'retention' => 7776000,     // 90 days
            'label' => '2-hour averages'
        ]
    ];

    /**
     * Quality rating thresholds for latency (ms)
     */
    public const LATENCY_THRESHOLDS = [
        'good' => 50,    // < 50ms = good
        'fair' => 100,   // < 100ms = fair
        'poor' => 250    // < 250ms = poor, >= 250ms = critical
    ];

    /**
     * Quality rating thresholds for jitter (ms)
     */
    public const JITTER_THRESHOLDS = [
        'good' => 10,    // < 10ms = good
        'fair' => 20,    // < 20ms = fair
        'poor' => 50     // < 50ms = poor, >= 50ms = critical
    ];

    /**
     * Quality rating thresholds for packet loss (%)
     */
    public const PACKET_LOSS_THRESHOLDS = [
        'good' => 1,     // < 1% = good
        'fair' => 2,     // < 2% = fair
        'poor' => 5      // < 5% = poor, >= 5% = critical
    ];

    /**
     * Tiers that should use gzip compression
     * These tiers have infrequent writes and rare reads, making compression beneficial
     */
    public const COMPRESSED_TIERS = ['30min', '2hour'];

    private FileManager $fileManager;
    private Logger $logger;
    private array $tiers;

    public function __construct(
        ?array $tiers = null,
        ?FileManager $fileManager = null,
        ?Logger $logger = null
    ) {
        $this->tiers = $tiers ?? self::TIERS;
        $this->fileManager = $fileManager ?? FileManager::getInstance();
        $this->logger = $logger ?? Logger::getInstance();
    }

    // ========================================================================
    // TIER SELECTION
    // ========================================================================

    /**
     * Get the best tier for a given time range
     *
     * @param int $hoursBack Number of hours to look back
     * @return string Tier name
     */
    public function getBestTierForHours(int $hoursBack): string
    {
        if ($hoursBack <= 6) return '1min';
        if ($hoursBack <= 48) return '5min';
        if ($hoursBack <= 336) return '30min';  // 14 days
        return '2hour';
    }

    /**
     * Get tier configuration
     *
     * @return array Tier configuration array
     */
    public function getTiers(): array
    {
        return $this->tiers;
    }

    /**
     * Get information about available rollup tiers
     *
     * @param callable $getFilePath Function to get file path for a tier
     * @return array Tier info array
     */
    public function getTiersInfo(callable $getFilePath): array
    {
        $result = [];

        foreach ($this->tiers as $tier => $config) {
            $filePath = $getFilePath($tier);
            $result[$tier] = [
                'interval' => $config['interval'],
                'interval_label' => self::formatInterval($config['interval']),
                'retention' => $config['retention'],
                'retention_label' => self::formatDuration($config['retention']),
                'label' => $config['label'],
                'file_exists' => file_exists($filePath),
                'file_size' => file_exists($filePath) ? filesize($filePath) : 0,
                'compressed' => $this->shouldCompressTier($tier)
            ];
        }

        return $result;
    }

    /**
     * Check if a tier should use gzip compression
     *
     * @param string $tier Tier name
     * @return bool True if tier should be compressed
     */
    public function shouldCompressTier(string $tier): bool
    {
        return in_array($tier, self::COMPRESSED_TIERS, true);
    }

    /**
     * Get rollup file path with appropriate extension
     *
     * Adds .gz extension for compressed tiers.
     *
     * @param string $baseDir Base directory for rollup files
     * @param string $tier Tier name
     * @return string Full file path
     */
    public function getRollupFilePath(string $baseDir, string $tier): string
    {
        $basePath = rtrim($baseDir, '/') . '/' . $tier . '.log';
        return $this->shouldCompressTier($tier) ? $basePath . '.gz' : $basePath;
    }

    /**
     * Migrate uncompressed tier file to compressed format
     *
     * Called automatically when writing to a compressed tier that has an
     * existing uncompressed file.
     *
     * @param string $baseDir Base directory for rollup files
     * @param string $tier Tier name
     * @return bool True if migration occurred, false if no migration needed
     */
    public function migrateToCompressed(string $baseDir, string $tier): bool
    {
        if (!$this->shouldCompressTier($tier)) {
            return false;
        }

        $uncompressedPath = rtrim($baseDir, '/') . '/' . $tier . '.log';
        $compressedPath = $uncompressedPath . '.gz';

        // Check if uncompressed file exists and compressed doesn't
        if (!file_exists($uncompressedPath) || file_exists($compressedPath)) {
            return false;
        }

        // Read entries from uncompressed file
        $entries = $this->fileManager->readJsonLinesFile($uncompressedPath);

        if (empty($entries)) {
            // No entries to migrate, just remove the old file
            @unlink($uncompressedPath);
            return true;
        }

        // Write to compressed file
        if ($this->fileManager->appendGzipJsonLines($compressedPath, $entries)) {
            // Successfully migrated, remove old file
            @unlink($uncompressedPath);
            $this->logger->info("Migrated {$tier} tier to compressed format: " . count($entries) . " entries");
            return true;
        }

        return false;
    }

    // ========================================================================
    // QUALITY RATINGS
    // ========================================================================

    /**
     * Get quality rating based on value and thresholds
     *
     * @param float $value The metric value
     * @param float $goodThreshold Below this = good
     * @param float $fairThreshold Below this = fair
     * @param float $poorThreshold Below this = poor, at or above = critical
     * @return string Quality rating: 'good', 'fair', 'poor', or 'critical'
     */
    public function getQualityRating(float $value, float $goodThreshold, float $fairThreshold, float $poorThreshold): string
    {
        if ($value < $goodThreshold) return 'good';
        if ($value < $fairThreshold) return 'fair';
        if ($value < $poorThreshold) return 'poor';
        return 'critical';
    }

    /**
     * Get overall quality rating from individual ratings
     *
     * @param string $latencyRating Latency quality rating
     * @param string $jitterRating Jitter quality rating
     * @param string $packetLossRating Packet loss quality rating
     * @return string Overall quality rating
     */
    public function getOverallQualityRating(string $latencyRating, string $jitterRating, string $packetLossRating): string
    {
        $ratings = [$latencyRating, $jitterRating, $packetLossRating];

        if (in_array('critical', $ratings)) return 'critical';
        if (in_array('poor', $ratings)) return 'poor';
        if (in_array('fair', $ratings)) return 'fair';
        return 'good';
    }

    // ========================================================================
    // JITTER CALCULATION (RFC 3550)
    // ========================================================================

    /**
     * Calculate jitter using RFC 3550 algorithm
     * J(i) = J(i-1) + (|D(i-1,i)| - J(i-1)) / 16
     *
     * @param string $hostname Host identifier for state tracking
     * @param float $latency Current latency measurement in ms
     * @param array &$state By-reference state array for persistence
     * @return float|null Calculated jitter or null if first sample
     */
    public function calculateJitterRFC3550(string $hostname, float $latency, array &$state): ?float
    {
        if (!isset($state[$hostname])) {
            $state[$hostname] = [
                'prevLatency' => $latency,
                'jitter' => 0.0
            ];
            return null;
        }

        $prevLatency = $state[$hostname]['prevLatency'];
        $prevJitter = $state[$hostname]['jitter'];
        $d = abs($latency - $prevLatency);
        $jitter = $prevJitter + ($d - $prevJitter) / 16.0;

        $state[$hostname]['prevLatency'] = $latency;
        $state[$hostname]['jitter'] = $jitter;

        return round($jitter, 2);
    }

    /**
     * Calculate jitter from an array of consecutive latency samples
     *
     * @param array $latencies Array of latency values in time order
     * @return array|null Array with 'avg' and 'max' jitter, or null if insufficient samples
     */
    public function calculateJitterFromLatencyArray(array $latencies): ?array
    {
        if (count($latencies) < 2) {
            return null;
        }

        $jitter = 0.0;
        $maxJitter = 0.0;
        $jitterSamples = [];

        for ($i = 1; $i < count($latencies); $i++) {
            $d = abs($latencies[$i] - $latencies[$i - 1]);
            $jitter = $jitter + ($d - $jitter) / 16.0;
            $jitterSamples[] = $jitter;
            if ($jitter > $maxJitter) {
                $maxJitter = $jitter;
            }
        }

        if (empty($jitterSamples)) {
            return null;
        }

        return [
            'avg' => round(array_sum($jitterSamples) / count($jitterSamples), 2),
            'max' => round($maxJitter, 2)
        ];
    }

    // ========================================================================
    // LATENCY AGGREGATION
    // ========================================================================

    /**
     * Aggregate latency values with consistent field naming and precision
     *
     * @param array $latencies Array of latency values
     * @param int $precision Decimal places for rounding (default 1)
     * @param bool $includeP95 Whether to calculate P95 (default true)
     * @return array Aggregated values with latency_min, latency_max, latency_avg, latency_p95
     */
    public function aggregateLatencies(array $latencies, int $precision = 1, bool $includeP95 = true): array
    {
        if (empty($latencies)) {
            return [
                'latency_min' => null,
                'latency_max' => null,
                'latency_avg' => null,
                'latency_p95' => null
            ];
        }

        $sorted = $latencies;
        sort($sorted);
        $count = count($sorted);

        $result = [
            'latency_min' => round(min($sorted), $precision),
            'latency_max' => round(max($sorted), $precision),
            'latency_avg' => round(array_sum($sorted) / $count, $precision)
        ];

        if ($includeP95) {
            $p95Index = (int)ceil($count * 0.95) - 1;
            $result['latency_p95'] = round($sorted[max(0, $p95Index)], $precision);
        }

        return $result;
    }

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    /**
     * Get or initialize rollup state
     *
     * @param string $stateFile Path to state file
     * @return array State array
     */
    public function getState(string $stateFile): array
    {
        $buildFreshState = function(): array {
            $state = [];
            foreach (array_keys($this->tiers) as $tier) {
                $state[$tier] = [
                    'last_processed' => 0,
                    'last_bucket_end' => 0,
                    'last_rollup' => time()
                ];
            }
            return $state;
        };

        $state = $this->fileManager->readJsonFile($stateFile);

        if ($state === null) {
            // File doesn't exist or is empty - create fresh state
            $state = $buildFreshState();
            $this->saveState($stateFile, $state);
            return $state;
        }

        if (!is_array($state) || empty($state)) {
            $this->logger->info("Corrupted rollup state file detected: {$stateFile}. Rebuilding fresh state.");
            $state = $buildFreshState();
            $this->saveState($stateFile, $state);
        }

        // Backfill new state fields if needed
        foreach (array_keys($this->tiers) as $tier) {
            if (!isset($state[$tier])) {
                $state[$tier] = [
                    'last_processed' => 0,
                    'last_bucket_end' => 0,
                    'last_rollup' => time()
                ];
                continue;
            }

            if (!isset($state[$tier]['last_bucket_end'])) {
                $state[$tier]['last_bucket_end'] = 0;
            }
            if (!isset($state[$tier]['last_rollup'])) {
                $state[$tier]['last_rollup'] = time();
            }
            if (!isset($state[$tier]['last_processed'])) {
                $state[$tier]['last_processed'] = 0;
            }
        }

        return $state;
    }

    /**
     * Save rollup state to disk
     *
     * @param string $stateFile Path to state file
     * @param array $state State array to save
     * @return bool Success
     */
    public function saveState(string $stateFile, array $state): bool
    {
        if (!$this->fileManager->writeJsonFile($stateFile, $state)) {
            $this->logger->error("Unable to write rollup state file: {$stateFile}");
            return false;
        }
        return true;
    }

    // ========================================================================
    // ROLLUP DATA OPERATIONS
    // ========================================================================

    /**
     * Read rollup data from a file with time filtering
     *
     * Automatically handles both compressed (.gz) and uncompressed files.
     *
     * @param string $rollupFile Path to rollup file
     * @param string $tier Tier name
     * @param int|null $startTime Start timestamp (null for tier default)
     * @param int|null $endTime End timestamp (null for now)
     * @param callable|null $filterFn Optional filter function for entries
     * @return array Result with success, count, data, tier, and period
     */
    public function readRollupData(
        string $rollupFile,
        string $tier,
        ?int $startTime = null,
        ?int $endTime = null,
        ?callable $filterFn = null
    ): array {
        if (!file_exists($rollupFile)) {
            return [
                'success' => false,
                'error' => 'Rollup file not found',
                'data' => []
            ];
        }

        if ($endTime === null) {
            $endTime = time();
        }

        if ($startTime === null) {
            $tierConfig = $this->tiers[$tier] ?? null;
            if ($tierConfig) {
                $startTime = $endTime - $tierConfig['retention'];
            } else {
                $startTime = $endTime - (24 * 3600);
            }
        }

        // Check if this is a gzip file
        $isGzip = str_ends_with($rollupFile, '.gz');

        // Build time range filter
        $timeRangeFilter = function($entry) use ($startTime, $endTime, $filterFn) {
            $timestamp = $entry['timestamp'] ?? 0;
            if ($timestamp < $startTime || $timestamp > $endTime) {
                return false;
            }
            // Apply custom filter if provided
            return $filterFn === null || $filterFn($entry);
        };

        if ($isGzip) {
            // Use gzip reader for compressed files
            $data = $this->fileManager->readGzipJsonLines($rollupFile, 0, $timeRangeFilter);
        } else {
            // Use standard reader for uncompressed files
            $data = [];
            $fp = fopen($rollupFile, 'r');

            if (!$fp) {
                return [
                    'success' => false,
                    'error' => 'Unable to read rollup file',
                    'data' => []
                ];
            }

            if (flock($fp, LOCK_SH)) {
                while (($line = fgets($fp)) !== false) {
                    $entry = FileManager::parseJsonLine($line);

                    if ($entry && isset($entry['timestamp']) && $timeRangeFilter($entry)) {
                        $data[] = $entry;
                    }
                }
                flock($fp, LOCK_UN);
            }

            fclose($fp);

            // Sort by timestamp
            usort($data, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
        }

        return [
            'success' => true,
            'count' => count($data),
            'data' => $data,
            'tier' => $tier,
            'period' => [
                'start' => $startTime,
                'end' => $endTime
            ]
        ];
    }

    /**
     * Append rollup entries to a rollup file
     *
     * Automatically uses gzip for .gz files.
     *
     * @param string $rollupFile Path to rollup file
     * @param array $entries Array of entries to append
     * @return bool Success
     */
    public function appendRollupEntries(string $rollupFile, array $entries): bool
    {
        if (empty($entries)) {
            return true;
        }

        // Check if this is a gzip file
        if (str_ends_with($rollupFile, '.gz')) {
            return $this->fileManager->appendGzipJsonLines($rollupFile, $entries);
        }

        // Standard uncompressed file
        $fp = fopen($rollupFile, 'a');

        if (!$fp) {
            $this->logger->error("Unable to open rollup file for writing: {$rollupFile}");
            return false;
        }

        if (flock($fp, LOCK_EX)) {
            foreach ($entries as $entry) {
                $jsonData = json_encode($entry);
                fwrite($fp, "{$jsonData}\n");
            }
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
        $this->fileManager->ensureFppOwnership($rollupFile);
        return true;
    }

    /**
     * Rotate rollup file to keep only entries within retention period
     *
     * Automatically handles both compressed (.gz) and uncompressed files.
     * For gzip files, uses a lower size threshold since they're already compressed.
     *
     * @param string $rollupFile Path to rollup file
     * @param int $retentionSeconds Retention period in seconds
     */
    public function rotateRollupFile(string $rollupFile, int $retentionSeconds): void
    {
        if (!file_exists($rollupFile)) {
            return;
        }

        $isGzip = str_ends_with($rollupFile, '.gz');
        $fileSize = filesize($rollupFile);

        // For gzip files, use lower threshold (100KB) since they're already compressed
        // For uncompressed, use 1MB threshold
        $sizeThreshold = $isGzip ? 100 * 1024 : 1024 * 1024;

        // Only rotate if file exceeds threshold to avoid excessive I/O
        if ($fileSize < $sizeThreshold) {
            return;
        }

        $cutoffTime = time() - $retentionSeconds;

        if ($isGzip) {
            // For gzip files, read all entries and filter
            $allEntries = $this->fileManager->readGzipJsonLines($rollupFile);
            $recentEntries = array_filter(
                $allEntries,
                fn($entry) => isset($entry['timestamp']) && $entry['timestamp'] >= $cutoffTime
            );

            if (!empty($recentEntries)) {
                // Rewrite with only recent entries
                $fp = @gzopen($rollupFile, 'w6');
                if ($fp) {
                    foreach ($recentEntries as $entry) {
                        gzwrite($fp, json_encode($entry) . "\n");
                    }
                    gzclose($fp);
                }
            } else {
                // Create empty gzip file
                $fp = @gzopen($rollupFile, 'w6');
                if ($fp) {
                    gzclose($fp);
                }
            }
        } else {
            // Standard uncompressed file rotation
            $recentEntries = [];

            $fp = fopen($rollupFile, 'r');
            if (!$fp) {
                return;
            }

            if (flock($fp, LOCK_SH)) {
                while (($line = fgets($fp)) !== false) {
                    $entry = FileManager::parseJsonLine($line);

                    if ($entry && isset($entry['timestamp'])) {
                        if ($entry['timestamp'] >= $cutoffTime) {
                            $recentEntries[] = json_encode($entry) . "\n";
                        }
                    }
                }
                flock($fp, LOCK_UN);
            }

            fclose($fp);

            if (!empty($recentEntries)) {
                $fp = fopen($rollupFile, 'w');
                if ($fp && flock($fp, LOCK_EX)) {
                    foreach ($recentEntries as $line) {
                        fwrite($fp, $line);
                    }
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                }
            } else {
                file_put_contents($rollupFile, '');
            }
        }

        $this->fileManager->ensureFppOwnership($rollupFile);
    }

    // ========================================================================
    // GENERIC TIER PROCESSING
    // ========================================================================

    /**
     * Process a single rollup tier
     *
     * @param string $tier Tier name
     * @param array $tierConfig Tier configuration
     * @param string $stateFile Path to state file
     * @param string $metricsFile Path to raw metrics file
     * @param callable $getRollupFilePath Function to get rollup file path (tier => path)
     * @param callable $aggregateFn Function to aggregate metrics for a bucket
     * @param callable|null $getStateFn Optional custom state getter
     * @param callable|null $saveStateFn Optional custom state saver
     */
    public function processTier(
        string $tier,
        array $tierConfig,
        string $stateFile,
        string $metricsFile,
        callable $getRollupFilePath,
        callable $aggregateFn,
        ?callable $getStateFn = null,
        ?callable $saveStateFn = null
    ): void {
        // Use provided functions or defaults
        $getState = $getStateFn ?? fn() => $this->getState($stateFile);
        $saveState = $saveStateFn ?? fn($state) => $this->saveState($stateFile, $state);

        $state = $getState();
        $lastProcessed = $state[$tier]['last_processed'] ?? 0;
        $lastBucketEnd = $state[$tier]['last_bucket_end'] ?? 0;
        $lastRollup = $state[$tier]['last_rollup'] ?? 0;
        $interval = $tierConfig['interval'];
        $retention = $tierConfig['retention'];
        $now = time();

        // Prevent running a tier more frequently than its interval
        if (($now - $lastRollup) < $interval) {
            return;
        }

        $storage = new MetricsStorage($this->fileManager, $this->logger);
        $rawMetrics = $storage->read($metricsFile, $lastProcessed);
        $processingCutoff = $now - 1;

        if (empty($rawMetrics)) {
            $state[$tier]['last_rollup'] = $now;
            $saveState($state);
            return;
        }

        // Group metrics into time buckets
        $buckets = [];
        foreach ($rawMetrics as $metric) {
            $timestamp = $metric['timestamp'];
            $bucketStart = (int)floor($timestamp / $interval) * $interval;

            if (!isset($buckets[$bucketStart])) {
                $buckets[$bucketStart] = [];
            }

            $buckets[$bucketStart][] = $metric;
        }

        $rollupFile = $getRollupFilePath($tier);
        $newEntries = [];

        ksort($buckets);
        $latestProcessedBucketEnd = $lastBucketEnd;

        foreach ($buckets as $bucketStart => $bucketMetrics) {
            $bucketEnd = $bucketStart + $interval;

            if ($bucketEnd <= $lastBucketEnd) {
                continue;
            }

            if ($bucketEnd > $processingCutoff) {
                continue;
            }

            $aggregated = $aggregateFn($bucketMetrics, $bucketStart, $interval);

            if ($aggregated === null) {
                continue;
            }

            // Handle both single entry and array of entries (for per-host aggregation)
            if (isset($aggregated[0])) {
                // Array of entries (multi-sync per-host)
                foreach ($aggregated as $entry) {
                    $newEntries[] = $entry;
                }
            } else {
                // Single entry
                $newEntries[] = $aggregated;
            }

            $latestProcessedBucketEnd = max($latestProcessedBucketEnd, $bucketEnd);
        }

        if (!empty($newEntries)) {
            $this->appendRollupEntries($rollupFile, $newEntries);

            $state[$tier]['last_processed'] = $latestProcessedBucketEnd - 1;
            $state[$tier]['last_bucket_end'] = $latestProcessedBucketEnd;
            $state[$tier]['last_rollup'] = $now;
            $saveState($state);
        } else {
            $state[$tier]['last_rollup'] = $now;
            $saveState($state);
        }

        $this->rotateRollupFile($rollupFile, $retention);
    }

    // ========================================================================
    // FORMATTING UTILITIES
    // ========================================================================

    /**
     * Format interval in human-readable form
     *
     * @param int $seconds Interval in seconds
     * @return string Formatted interval
     */
    public static function formatInterval(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        } elseif ($seconds < 3600) {
            return ($seconds / 60) . ' minutes';
        } else {
            return ($seconds / 3600) . ' hours';
        }
    }

    /**
     * Format duration in human-readable form
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            return ($seconds / 60) . ' minutes';
        } elseif ($seconds < 86400) {
            return ($seconds / 3600) . ' hours';
        } else {
            return ($seconds / 86400) . ' days';
        }
    }
}
