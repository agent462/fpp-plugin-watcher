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
class PingCollector extends BaseMetricsCollector
{
    private const STATE_FILE_SUFFIX = '/rollup-state.json';

    protected static ?self $instance = null;

    /**
     * Initialize data directory and metrics file paths
     */
    protected function initializePaths(): void
    {
        $this->dataDir = defined('WATCHERPINGDIR')
            ? WATCHERPINGDIR
            : '/home/fpp/media/logs/watcher-data/ping';
        $this->metricsFile = defined('WATCHERPINGMETRICSFILE')
            ? WATCHERPINGMETRICSFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log';
    }

    /**
     * Get state file suffix
     */
    protected function getStateFileSuffix(): string
    {
        return self::STATE_FILE_SUFFIX;
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
}
