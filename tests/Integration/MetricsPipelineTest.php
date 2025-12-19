<?php
/**
 * Integration tests for the Metrics Pipeline
 *
 * Tests the complete flow from raw metrics collection through rollup processing
 * to data retrieval.
 *
 * @package Watcher\Tests\Integration
 */

declare(strict_types=1);

namespace Watcher\Tests\Integration;

use Watcher\Tests\TestCase;
use Watcher\Metrics\MetricsStorage;
use Watcher\Metrics\RollupProcessor;

class MetricsPipelineTest extends TestCase
{
    private MetricsStorage $storage;
    private RollupProcessor $processor;
    private string $metricsFile;
    private string $rollupDir;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new MetricsStorage();
        $this->processor = new RollupProcessor();

        $this->metricsFile = $this->testTmpDir . '/metrics.log';
        $this->rollupDir = $this->testTmpDir . '/rollups';
        $this->stateFile = $this->testTmpDir . '/rollup-state.json';

        mkdir($this->rollupDir, 0755, true);
    }

    /**
     * Test the complete metrics pipeline: write -> rollup -> read
     */
    public function testCompleteMetricsPipeline(): void
    {
        $now = time();
        $endTime = $now - 120; // End data 2 minutes ago to ensure buckets are complete

        // Step 1: Write raw metrics
        $rawMetrics = [];
        for ($i = 0; $i < 120; $i++) { // 2 hours of minute-by-minute data
            $rawMetrics[] = [
                'timestamp' => $endTime - (7200 - ($i * 60)),
                'latency' => 20 + mt_rand(0, 30),
                'success' => mt_rand(0, 100) > 5,
            ];
        }

        $writeResult = $this->storage->writeBatch($this->metricsFile, $rawMetrics);
        $this->assertTrue($writeResult, 'Raw metrics write should succeed');

        // Verify raw data was written
        $rawRead = $this->storage->read($this->metricsFile);
        $this->assertCount(120, $rawRead, 'Should have 120 raw metrics');

        // Step 2: Process rollups using the processor
        $getRollupPath = fn($tier) => $this->rollupDir . "/rollup_{$tier}.log";

        // Simple aggregation function for testing
        $aggregateFn = function ($bucketMetrics, $bucketStart, $interval) {
            $latencies = array_filter(array_column($bucketMetrics, 'latency'));
            $successCount = count(array_filter(array_column($bucketMetrics, 'success')));
            $totalCount = count($bucketMetrics);

            if (empty($latencies)) {
                return null;
            }

            return [
                'timestamp' => $bucketStart + ($interval / 2),
                'latency_avg' => round(array_sum($latencies) / count($latencies), 1),
                'latency_min' => min($latencies),
                'latency_max' => max($latencies),
                'success_rate' => round(($successCount / $totalCount) * 100, 1),
                'sample_count' => $totalCount,
            ];
        };

        // Process 1-minute tier
        // First, set last_rollup to past time so the interval check passes
        $state = $this->processor->getState($this->stateFile);
        $state['1min']['last_rollup'] = 0;  // Allow immediate processing
        $this->processor->saveState($this->stateFile, $state);

        $tiers = $this->processor->getTiers();
        $this->processor->processTier(
            '1min',
            $tiers['1min'],
            $this->stateFile,
            $this->metricsFile,
            $getRollupPath,
            $aggregateFn
        );

        // Step 3: Read rollup data
        $rollupFile = $getRollupPath('1min');
        $this->assertFileExists($rollupFile, 'Rollup file should be created');

        $rollupData = $this->processor->readRollupData(
            $rollupFile,
            '1min',
            $endTime - 7200,
            $endTime
        );

        $this->assertTrue($rollupData['success'], 'Rollup read should succeed');
        $this->assertGreaterThan(0, count($rollupData['data']), 'Should have rollup entries');

        // Verify rollup entry structure
        $firstEntry = $rollupData['data'][0];
        $this->assertArrayHasKey('timestamp', $firstEntry);
        $this->assertArrayHasKey('latency_avg', $firstEntry);
        $this->assertArrayHasKey('sample_count', $firstEntry);
    }

    /**
     * Test tier selection based on time range
     */
    public function testTierSelectionByTimeRange(): void
    {
        // Short range should use 1min tier
        $tier = $this->processor->getBestTierForHours(1);
        $this->assertEquals('1min', $tier);

        // Medium range should use 5min tier
        $tier = $this->processor->getBestTierForHours(12);
        $this->assertEquals('5min', $tier);

        // Longer range should use 30min tier
        $tier = $this->processor->getBestTierForHours(72);
        $this->assertEquals('30min', $tier);

        // Very long range should use 2hour tier
        $tier = $this->processor->getBestTierForHours(500);
        $this->assertEquals('2hour', $tier);
    }

    /**
     * Test state persistence across rollup runs
     */
    public function testStatePersistence(): void
    {
        // Initial state
        $state1 = $this->processor->getState($this->stateFile);
        $this->assertArrayHasKey('1min', $state1);
        $this->assertEquals(0, $state1['1min']['last_processed']);

        // Modify and save state
        $state1['1min']['last_processed'] = 1000;
        $state1['1min']['last_bucket_end'] = 1060;
        $this->processor->saveState($this->stateFile, $state1);

        // Read state again
        $state2 = $this->processor->getState($this->stateFile);
        $this->assertEquals(1000, $state2['1min']['last_processed']);
        $this->assertEquals(1060, $state2['1min']['last_bucket_end']);
    }

    /**
     * Test metrics rotation (purging old data)
     */
    public function testMetricsRotation(): void
    {
        $now = time();

        // Write mix of old and new entries
        $entries = [];

        // Old entries (> 1 hour ago, will be purged)
        for ($i = 0; $i < 50; $i++) {
            $entries[] = [
                'timestamp' => $now - 7200 + $i, // 2 hours ago
                'value' => 'old',
            ];
        }

        // New entries (< 30 min ago, will be kept)
        for ($i = 0; $i < 50; $i++) {
            $entries[] = [
                'timestamp' => $now - 1800 + $i, // 30 min ago
                'value' => 'new',
            ];
        }

        $this->storage->writeBatch($this->metricsFile, $entries);

        // Rotate with 1 hour retention
        $result = $this->storage->rotate($this->metricsFile, 3600);

        $this->assertEquals(50, $result['purged']);
        $this->assertEquals(50, $result['kept']);

        // Verify only new entries remain
        $remaining = $this->storage->read($this->metricsFile);
        $this->assertCount(50, $remaining);

        foreach ($remaining as $entry) {
            $this->assertEquals('new', $entry['value']);
        }
    }

    /**
     * Test concurrent write safety
     */
    public function testConcurrentWriteSafety(): void
    {
        // Simulate rapid concurrent writes
        $batchCount = 20;
        $entriesPerBatch = 10;

        for ($batch = 0; $batch < $batchCount; $batch++) {
            $entries = [];
            for ($i = 0; $i < $entriesPerBatch; $i++) {
                $entries[] = [
                    'timestamp' => time(),
                    'batch' => $batch,
                    'index' => $i,
                ];
            }
            $this->storage->writeBatch($this->metricsFile, $entries);
        }

        $all = $this->storage->read($this->metricsFile);

        // Should have all entries
        $this->assertCount($batchCount * $entriesPerBatch, $all);

        // Verify all batches are represented
        $batchCounts = array_count_values(array_column($all, 'batch'));
        $this->assertCount($batchCount, $batchCounts);

        foreach ($batchCounts as $batch => $count) {
            $this->assertEquals($entriesPerBatch, $count);
        }
    }

    /**
     * Test quality rating calculations
     */
    public function testQualityRatingCalculations(): void
    {
        // Test latency ratings using the thresholds
        $this->assertEquals(
            'good',
            $this->processor->getQualityRating(25, 50, 100, 250)
        );
        $this->assertEquals(
            'fair',
            $this->processor->getQualityRating(75, 50, 100, 250)
        );
        $this->assertEquals(
            'poor',
            $this->processor->getQualityRating(150, 50, 100, 250)
        );
        $this->assertEquals(
            'critical',
            $this->processor->getQualityRating(300, 50, 100, 250)
        );
    }

    /**
     * Test jitter calculation on realistic latency data
     */
    public function testJitterCalculationOnRealisticData(): void
    {
        // Simulate realistic ping latencies (stable network)
        $stableLatencies = [15.2, 15.5, 15.3, 15.4, 15.1, 15.6, 15.2, 15.3];
        $stableJitter = $this->processor->calculateJitterFromLatencyArray($stableLatencies);

        // Simulate unstable network
        $unstableLatencies = [15.0, 45.0, 12.0, 80.0, 20.0, 60.0, 15.0, 100.0];
        $unstableJitter = $this->processor->calculateJitterFromLatencyArray($unstableLatencies);

        // Stable network should have lower jitter
        $this->assertLessThan($unstableJitter['avg'], $stableJitter['avg']);
        $this->assertLessThan($unstableJitter['max'], $stableJitter['max']);
    }

    /**
     * Test latency aggregation accuracy
     */
    public function testLatencyAggregationAccuracy(): void
    {
        $latencies = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0, 70.0, 80.0, 90.0, 100.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(10.0, $result['latency_min']);
        $this->assertEquals(100.0, $result['latency_max']);
        $this->assertEquals(55.0, $result['latency_avg']);
        $this->assertEquals(100.0, $result['latency_p95']); // P95 of 10 values is the 10th
    }
}
