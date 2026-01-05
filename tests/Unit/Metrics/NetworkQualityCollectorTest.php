<?php
/**
 * Unit tests for NetworkQualityCollector class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\NetworkQualityCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;

/**
 * Testable NetworkQualityCollector that allows instance creation without singleton
 */
class TestableNetworkQualityCollector extends NetworkQualityCollector
{
    private string $testDataDir;

    public function __construct(
        string $dataDir,
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->testDataDir = $dataDir;

        // Use reflection to set parent properties
        $parent = new \ReflectionClass(NetworkQualityCollector::class);

        $rollupProp = $parent->getProperty('rollup');
        $rollupProp->setAccessible(true);
        $rollupProp->setValue($this, $rollup ?? new RollupProcessor());

        $storageProp = $parent->getProperty('storage');
        $storageProp->setAccessible(true);
        $storageProp->setValue($this, $storage ?? new MetricsStorage());

        $loggerProp = $parent->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this, $logger ?? Logger::getInstance());

        $dataDirProp = $parent->getProperty('dataDir');
        $dataDirProp->setAccessible(true);
        $dataDirProp->setValue($this, $dataDir);

        $metricsFileProp = $parent->getProperty('metricsFile');
        $metricsFileProp->setAccessible(true);
        $metricsFileProp->setValue($this, $dataDir . '/raw.log');
    }

    /**
     * Get jitter state for testing
     */
    public function getJitterState(): array
    {
        $parent = new \ReflectionClass(NetworkQualityCollector::class);
        $prop = $parent->getProperty('jitterState');
        $prop->setAccessible(true);
        return $prop->getValue($this);
    }
}

class NetworkQualityCollectorTest extends TestCase
{
    private TestableNetworkQualityCollector $collector;
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = $this->createTempDir('network-quality');

        $this->collector = new TestableNetworkQualityCollector($this->dataDir);
    }

    // =========================================================================
    // Singleton and Instance Tests
    // =========================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = NetworkQualityCollector::getInstance();
        $instance2 = NetworkQualityCollector::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Path Generation Tests
    // =========================================================================

    public function testGetRollupFilePathReturnsCorrectPath(): void
    {
        $path = $this->collector->getRollupFilePath('1min');

        $this->assertEquals($this->dataDir . '/1min.log', $path);
    }

    public function testGetRollupFilePathForAllTiers(): void
    {
        $tiers = ['1min', '5min', '30min', '2hour'];
        $compressedTiers = ['30min', '2hour'];

        foreach ($tiers as $tier) {
            $path = $this->collector->getRollupFilePath($tier);
            // Compressed tiers use .log.gz extension
            $expectedExt = in_array($tier, $compressedTiers, true) ? "/{$tier}.log.gz" : "/{$tier}.log";
            $this->assertStringEndsWith($expectedExt, $path);
        }
    }

    public function testGetStateFilePathReturnsCorrectPath(): void
    {
        $path = $this->collector->getStateFilePath();

        $this->assertEquals($this->dataDir . '/rollup-state.json', $path);
    }

    // =========================================================================
    // Expected Sync Rate Tests
    // =========================================================================

    public function testGetExpectedSyncRateWithNullStepTime(): void
    {
        $rate = $this->collector->getExpectedSyncRate(null);

        $this->assertEquals(2.0, $rate);  // Default for null
    }

    public function testGetExpectedSyncRateWithZeroStepTime(): void
    {
        $rate = $this->collector->getExpectedSyncRate(0);

        $this->assertEquals(2.0, $rate);  // Default for 0
    }

    public function testGetExpectedSyncRateWithNegativeStepTime(): void
    {
        $rate = $this->collector->getExpectedSyncRate(-10);

        $this->assertEquals(2.0, $rate);  // Default for negative
    }

    public function testGetExpectedSyncRateWith25msStepTime(): void
    {
        // 25ms = 40fps, sync every 10 frames = 4 packets/second
        $rate = $this->collector->getExpectedSyncRate(25);

        $this->assertEquals(4.0, $rate);
    }

    public function testGetExpectedSyncRateWith50msStepTime(): void
    {
        // 50ms = 20fps, sync every 10 frames = 2 packets/second
        $rate = $this->collector->getExpectedSyncRate(50);

        $this->assertEquals(2.0, $rate);
    }

    public function testGetExpectedSyncRateWith100msStepTime(): void
    {
        // 100ms = 10fps, sync every 10 frames = 1 packet/second
        $rate = $this->collector->getExpectedSyncRate(100);

        $this->assertEquals(1.0, $rate);
    }

    /**
     * @dataProvider stepTimeRateProvider
     */
    public function testGetExpectedSyncRateWithVariousStepTimes(int $stepTime, float $expectedRate): void
    {
        $rate = $this->collector->getExpectedSyncRate($stepTime);

        $this->assertEqualsWithDelta($expectedRate, $rate, 0.01);
    }

    public static function stepTimeRateProvider(): array
    {
        return [
            '20ms (50fps)' => [20, 5.0],
            '25ms (40fps)' => [25, 4.0],
            '33ms (30fps)' => [33, 3.03],
            '50ms (20fps)' => [50, 2.0],
            '100ms (10fps)' => [100, 1.0],
            '200ms (5fps)' => [200, 0.5],
        ];
    }

    // =========================================================================
    // Jitter Calculation Tests
    // =========================================================================

    public function testCalculateJitterFirstSampleReturnsNull(): void
    {
        $jitter = $this->collector->calculateJitter('host1', 50.0);

        $this->assertNull($jitter);
    }

    public function testCalculateJitterSecondSampleReturnsValue(): void
    {
        $this->collector->calculateJitter('host1', 50.0);
        $jitter = $this->collector->calculateJitter('host1', 60.0);

        $this->assertNotNull($jitter);
        $this->assertIsFloat($jitter);
    }

    public function testCalculateJitterUpdatesState(): void
    {
        $this->collector->calculateJitter('host1', 50.0);

        $state = $this->collector->getJitterState();
        $this->assertArrayHasKey('host1', $state);
    }

    public function testCalculateJitterIndependentHosts(): void
    {
        // Initialize both hosts
        $this->collector->calculateJitter('host1', 50.0);
        $this->collector->calculateJitter('host2', 100.0);

        // Second samples
        $jitter1 = $this->collector->calculateJitter('host1', 60.0);
        $jitter2 = $this->collector->calculateJitter('host2', 80.0);

        // Different differences should produce different jitters
        // host1: |60-50| = 10
        // host2: |80-100| = 20
        $this->assertNotEquals($jitter1, $jitter2);
    }

    public function testCalculateJitterFromLatenciesInsufficientSamples(): void
    {
        $result = $this->collector->calculateJitterFromLatencies([50.0]);

        $this->assertNull($result);
    }

    public function testCalculateJitterFromLatenciesEmptyArray(): void
    {
        $result = $this->collector->calculateJitterFromLatencies([]);

        $this->assertNull($result);
    }

    public function testCalculateJitterFromLatenciesReturnsAvgAndMax(): void
    {
        $result = $this->collector->calculateJitterFromLatencies([50.0, 60.0, 55.0, 70.0]);

        $this->assertArrayHasKey('avg', $result);
        $this->assertArrayHasKey('max', $result);
        $this->assertGreaterThanOrEqual($result['avg'], $result['max']);
    }

    public function testCalculateJitterFromLatenciesStableValues(): void
    {
        $result = $this->collector->calculateJitterFromLatencies([50.0, 50.0, 50.0, 50.0]);

        $this->assertEquals(0.0, $result['avg']);
        $this->assertEquals(0.0, $result['max']);
    }

    // =========================================================================
    // Aggregation Tests - Basic
    // =========================================================================

    public function testAggregateMetricsReturnsNullForEmptyArray(): void
    {
        $result = $this->collector->aggregateMetrics([]);

        $this->assertNull($result);
    }

    public function testAggregateMetricsGroupsByHost(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 10.0],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 20.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'address' => '192.168.1.2', 'latency' => 30.0],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(2, $result);
        $hostnames = array_column($result, 'hostname');
        $this->assertContains('host1', $hostnames);
        $this->assertContains('host2', $hostnames);
    }

    public function testAggregateMetricsCalculatesLatencyStats(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => 20.0],
            ['timestamp' => $now + 2, 'hostname' => 'host1', 'latency' => 30.0],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(10.0, $host['latency_min']);
        $this->assertEquals(30.0, $host['latency_max']);
        $this->assertEquals(20.0, $host['latency_avg']);
        $this->assertEquals(3, $host['sample_count']);
    }

    public function testAggregateMetricsCalculatesP95Latency(): void
    {
        $now = time();
        $metrics = [];

        // Create 100 samples with latencies 1-100
        for ($i = 1; $i <= 100; $i++) {
            $metrics[] = ['timestamp' => $now + $i, 'hostname' => 'host1', 'latency' => (float)$i];
        }

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(95.0, $host['latency_p95']);
    }

    public function testAggregateMetricsCalculatesJitter(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0, 'jitter' => 1.0],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => 20.0, 'jitter' => 2.0],
            ['timestamp' => $now + 2, 'hostname' => 'host1', 'latency' => 30.0, 'jitter' => 3.0],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertArrayHasKey('jitter_avg', $host);
        $this->assertArrayHasKey('jitter_max', $host);
    }

    public function testAggregateMetricsIncludesQualityRatings(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 30.0],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => 40.0],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertArrayHasKey('latency_quality', $host);
        $this->assertContains($host['latency_quality'], ['good', 'fair', 'poor', 'critical']);
        $this->assertArrayHasKey('overall_quality', $host);
    }

    // =========================================================================
    // Aggregation Tests - Packet Loss
    // =========================================================================

    public function testAggregateMetricsCalculatesPacketLoss(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 25  // 4 pkt/s expected
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 130,  // Received 30 packets in 10 seconds (3 pkt/s)
                'stepTime' => 25
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // Expected 4 pkt/s, got 3 pkt/s = 25% loss
        $this->assertArrayHasKey('packet_loss_pct', $host);
        $this->assertNotNull($host['packet_loss_pct']);
        $this->assertArrayHasKey('packet_loss_quality', $host);
    }

    public function testAggregateMetricsZeroPacketLoss(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 50  // 2 pkt/s expected
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 120,  // 20 packets in 10s = 2 pkt/s, meeting expectation
                'stepTime' => 50
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(0.0, $host['packet_loss_pct']);
    }

    public function testAggregateMetricsHandlesCounterReset(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 1000,
                'stepTime' => 25
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 50,  // Counter reset (value dropped)
                'stepTime' => 25
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // Counter reset should result in null packet loss
        $this->assertNull($host['packet_loss_pct']);
    }

    public function testAggregateMetricsIgnoresNonPlayingForPacketLoss(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => false,  // Not playing
                'remotePacketsReceived' => 100,
                'stepTime' => 25
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => false,
                'remotePacketsReceived' => 100,
                'stepTime' => 25
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // No playing samples = null packet loss
        $this->assertNull($host['packet_loss_pct']);
    }

    public function testAggregateMetricsHandlesZeroTimeWindow(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 25
            ],
            [
                'timestamp' => $now,  // Same timestamp
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 25
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // Zero time window = null packet loss
        $this->assertNull($host['packet_loss_pct']);
    }

    // =========================================================================
    // Aggregation Tests - Edge Cases
    // =========================================================================

    public function testAggregateMetricsWithNullLatencies(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => null],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => null],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertNull($host['latency_min']);
        $this->assertNull($host['latency_max']);
        $this->assertNull($host['latency_avg']);
    }

    public function testAggregateMetricsWithUnknownHostname(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'latency' => 10.0],  // No hostname
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(1, $result);
        $this->assertEquals('unknown', $result[0]['hostname']);
    }

    public function testAggregateMetricsSortsInputByTimestamp(): void
    {
        $now = time();
        // Metrics in reverse order
        $metrics = [
            ['timestamp' => $now + 2, 'hostname' => 'host1', 'latency' => 30.0],
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => 20.0],
        ];

        // Should still produce correct jitter (based on sorted order)
        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertNotNull($result);
    }

    public function testAggregateMetricsWithJitterOnlyNoLatencies(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => null, 'jitter' => 1.5],
            ['timestamp' => $now + 1, 'hostname' => 'host1', 'latency' => null, 'jitter' => 2.5],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertNull($host['latency_avg']);
        $this->assertEquals(2.0, $host['jitter_avg']);
        $this->assertEquals(2.5, $host['jitter_max']);
    }

    // =========================================================================
    // aggregateForRollup Tests
    // =========================================================================

    public function testAggregateForRollupReturnsNullForEmptyMetrics(): void
    {
        $result = $this->collector->aggregateForRollup([], 1000, 60);

        $this->assertNull($result);
    }

    public function testAggregateForRollupReturnsArrayOfEntries(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 20.0],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);  // One per host
    }

    public function testAggregateForRollupAddsTimestamps(): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);
        $entry = $result[0];

        $this->assertEquals(1000, $entry['timestamp']);
        $this->assertEquals(1000, $entry['period_start']);
        $this->assertEquals(1060, $entry['period_end']);
    }

    // =========================================================================
    // State Management Tests
    // =========================================================================

    public function testGetRollupStateInitializesNewState(): void
    {
        $state = $this->collector->getRollupState();

        $this->assertIsArray($state);
        $this->assertArrayHasKey('1min', $state);
    }

    public function testSaveRollupStateWritesFile(): void
    {
        $state = ['1min' => ['last_processed' => 1000]];

        $result = $this->collector->saveRollupState($state);

        $this->assertTrue($result);
        $this->assertFileExists($this->collector->getStateFilePath());
    }

    // =========================================================================
    // Read/Write Tests
    // =========================================================================

    public function testWriteMetricsWritesToFile(): void
    {
        $entries = [
            ['timestamp' => time(), 'hostname' => 'host1', 'latency' => 10.0],
        ];

        $result = $this->collector->writeMetrics($entries);

        $this->assertTrue($result);
        $this->assertFileExists($this->dataDir . '/raw.log');
    }

    public function testReadRawMetricsReturnsEmpty(): void
    {
        $result = $this->collector->readRawMetrics(0);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadRawMetricsReadsWrittenData(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->readRawMetrics(0);

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // Tier Selection Tests
    // =========================================================================

    public function testGetBestRollupTierReturns1MinForShortRange(): void
    {
        $this->assertEquals('1min', $this->collector->getBestRollupTier(1));
        $this->assertEquals('1min', $this->collector->getBestRollupTier(6));
    }

    public function testGetBestRollupTierReturns5MinForMediumRange(): void
    {
        $this->assertEquals('5min', $this->collector->getBestRollupTier(24));
        $this->assertEquals('5min', $this->collector->getBestRollupTier(48));
    }

    // =========================================================================
    // getStatus Tests
    // =========================================================================

    public function testGetStatusWithNoData(): void
    {
        $result = $this->collector->getStatus();

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['hosts']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNull($result['summary']['avgLatency']);
        $this->assertEquals('unknown', $result['summary']['overallQuality']);
    }

    public function testGetStatusWithData(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'latency' => 10.0, 'address' => '192.168.1.1'],
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 20.0, 'address' => '192.168.1.1'],
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->getStatus();

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['hosts']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNotNull($result['summary']['avgLatency']);
    }

    public function testGetStatusCalculatesSummaryStats(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0, 'jitter' => 1.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 30.0, 'jitter' => 3.0],
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->getStatus();

        // Average latency should be (10 + 30) / 2 = 20
        $this->assertEquals(20.0, $result['summary']['avgLatency']);
    }

    public function testGetStatusWorstQualityWins(): void
    {
        $now = time();
        // Create metrics that will produce different quality ratings
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],  // Good
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 500.0], // Critical
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->getStatus();

        // Critical should dominate
        $this->assertEquals('critical', $result['summary']['overallQuality']);
    }

    // =========================================================================
    // getHistory Tests
    // =========================================================================

    public function testGetHistoryFromRawReturnsEmptyForNoData(): void
    {
        $result = $this->collector->getHistoryFromRaw(1);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['chartData']['labels']);
    }

    public function testGetHistoryFromRawReturnsChartData(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 120, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'latency' => 20.0],
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 30.0],
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->getHistoryFromRaw(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('chartData', $result);
        $this->assertNotEmpty($result['chartData']['labels']);
        $this->assertNotEmpty($result['chartData']['latency']);
    }

    public function testGetHistoryFromRawFiltersByHostname(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 100.0],
        ];
        $this->writeJsonLinesFile($this->dataDir . '/raw.log', $entries);

        $result = $this->collector->getHistoryFromRaw(1, 'host1');

        $this->assertTrue($result['success']);
        // Should only include host1 data
        foreach ($result['chartData']['latency'] as $latency) {
            if ($latency !== null) {
                $this->assertLessThan(50, $latency);  // Only host1's 10.0
            }
        }
    }

    public function testGetHistoryUsesRollupForLongerRanges(): void
    {
        // Create rollup file for 12-hour query
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('5min');
        $entries = [
            ['timestamp' => $now - 3600, 'hostname' => 'host1', 'latency_avg' => 15.0],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->getHistory(12);  // > 6 hours

        // Should use getMetrics which reads from rollup tiers
        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // getRollupTiersInfo Tests
    // =========================================================================

    public function testGetRollupTiersInfoReturnsAllTiers(): void
    {
        $info = $this->collector->getRollupTiersInfo();

        $this->assertArrayHasKey('1min', $info);
        $this->assertArrayHasKey('5min', $info);
        $this->assertArrayHasKey('30min', $info);
        $this->assertArrayHasKey('2hour', $info);
    }

    // =========================================================================
    // Dependency Injection Tests
    // =========================================================================

    public function testGetRollupProcessorReturnsInstance(): void
    {
        $processor = $this->collector->getRollupProcessor();

        $this->assertInstanceOf(RollupProcessor::class, $processor);
    }

    public function testGetMetricsStorageReturnsInstance(): void
    {
        $storage = $this->collector->getMetricsStorage();

        $this->assertInstanceOf(MetricsStorage::class, $storage);
    }

    // =========================================================================
    // Quality Rating Integration Tests
    // =========================================================================

    /**
     * @dataProvider latencyQualityProvider
     */
    public function testLatencyQualityRatings(float $latency, string $expectedQuality): void
    {
        $now = time();
        $metrics = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => $latency],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals($expectedQuality, $host['latency_quality']);
    }

    public static function latencyQualityProvider(): array
    {
        return [
            'good latency' => [30.0, 'good'],
            'fair latency' => [75.0, 'fair'],
            'poor latency' => [150.0, 'poor'],
            'critical latency' => [300.0, 'critical'],
        ];
    }

    // =========================================================================
    // Packet Loss Edge Cases
    // =========================================================================

    public function testPacketLossFullLoss(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 25  // 4 pkt/s expected
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,  // 0 packets received in 10 seconds
                'stepTime' => 25
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(100.0, $host['packet_loss_pct']);
    }

    public function testPacketLossExceedsExpected(): void
    {
        $now = time();
        $metrics = [
            [
                'timestamp' => $now,
                'hostname' => 'host1',
                'latency' => 10.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 100,
                'stepTime' => 50  // 2 pkt/s expected
            ],
            [
                'timestamp' => $now + 10,
                'hostname' => 'host1',
                'latency' => 20.0,
                'isPlaying' => true,
                'remotePacketsReceived' => 150,  // 5 pkt/s, exceeds expectation
                'stepTime' => 50
            ],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // When receive rate exceeds expected, loss should be 0
        $this->assertEquals(0.0, $host['packet_loss_pct']);
    }
}
