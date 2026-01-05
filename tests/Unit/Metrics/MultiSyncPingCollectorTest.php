<?php
/**
 * Unit tests for MultiSyncPingCollector class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\MultiSyncPingCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;

/**
 * Testable MultiSyncPingCollector that allows instance creation without singleton
 */
class TestableMultiSyncPingCollector extends MultiSyncPingCollector
{
    private string $testDataDir;
    private string $testMetricsFile;

    public function __construct(
        string $dataDir,
        string $metricsFile,
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->testDataDir = $dataDir;
        $this->testMetricsFile = $metricsFile;

        // Use reflection to set parent properties
        $parent = new \ReflectionClass(MultiSyncPingCollector::class);

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
        $metricsFileProp->setValue($this, $metricsFile);
    }

    /**
     * Get jitter state for testing
     */
    public function getJitterState(): array
    {
        $parent = new \ReflectionClass(MultiSyncPingCollector::class);
        $prop = $parent->getProperty('jitterState');
        $prop->setAccessible(true);
        return $prop->getValue($this);
    }
}

class MultiSyncPingCollectorTest extends TestCase
{
    private TestableMultiSyncPingCollector $collector;
    private string $dataDir;
    private string $metricsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = $this->createTempDir('multisync-ping');
        $this->metricsFile = $this->testTmpDir . '/multisync-ping-metrics.log';

        $this->collector = new TestableMultiSyncPingCollector(
            $this->dataDir,
            $this->metricsFile
        );
    }

    // =========================================================================
    // Singleton and Instance Tests
    // =========================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = MultiSyncPingCollector::getInstance();
        $instance2 = MultiSyncPingCollector::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsMultiSyncPingCollector(): void
    {
        $instance = MultiSyncPingCollector::getInstance();

        $this->assertInstanceOf(MultiSyncPingCollector::class, $instance);
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

    public function testCalculateJitterFollowsRFC3550(): void
    {
        // First sample
        $this->collector->calculateJitter('host1', 50.0);

        // Second sample: J = 0 + (|60-50| - 0) / 16 = 0.625
        $jitter = $this->collector->calculateJitter('host1', 60.0);

        $this->assertEquals(0.63, $jitter);  // Rounded to 2 decimal places
    }

    public function testCalculateJitterIndependentHosts(): void
    {
        // Initialize both hosts
        $this->collector->calculateJitter('host1', 50.0);
        $this->collector->calculateJitter('host2', 100.0);

        // Second samples with different deltas
        $jitter1 = $this->collector->calculateJitter('host1', 60.0);   // diff = 10
        $jitter2 = $this->collector->calculateJitter('host2', 150.0);  // diff = 50

        $this->assertNotEquals($jitter1, $jitter2);
        $this->assertLessThan($jitter2, $jitter1);  // Larger diff = higher jitter
    }

    public function testCalculateJitterUpdatesState(): void
    {
        $this->collector->calculateJitter('testhost', 100.0);

        $state = $this->collector->getJitterState();

        $this->assertArrayHasKey('testhost', $state);
        $this->assertEquals(100.0, $state['testhost']['prevLatency']);
    }

    public function testCalculateJitterZeroDifference(): void
    {
        $this->collector->calculateJitter('host1', 50.0);
        $jitter = $this->collector->calculateJitter('host1', 50.0);

        $this->assertEquals(0.0, $jitter);
    }

    // =========================================================================
    // Aggregation Tests - Basic
    // =========================================================================

    public function testAggregateMetricsReturnsNullForEmptyArray(): void
    {
        $result = $this->collector->aggregateMetrics([]);

        $this->assertNull($result);
    }

    public function testAggregateMetricsGroupsByHostname(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 20.0, 'status' => 'success'],
            ['hostname' => 'host2', 'address' => '192.168.1.2', 'latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(2, $result);
        $hostnames = array_column($result, 'hostname');
        $this->assertContains('host1', $hostnames);
        $this->assertContains('host2', $hostnames);
    }

    public function testAggregateMetricsCalculatesLatencyStats(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 20.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(10.0, $host['min_latency']);
        $this->assertEquals(30.0, $host['max_latency']);
        $this->assertEquals(20.0, $host['avg_latency']);
        $this->assertEquals(3, $host['sample_count']);
    }

    public function testAggregateMetricsCountsSuccessAndFailure(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 20.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => null, 'status' => 'failure'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(2, $host['success_count']);
        $this->assertEquals(1, $host['failure_count']);
        $this->assertEquals(3, $host['sample_count']);
    }

    public function testAggregateMetricsCalculatesJitterStats(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'jitter' => 1.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 20.0, 'jitter' => 2.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 30.0, 'jitter' => 3.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(2.0, $host['avg_jitter']);
        $this->assertEquals(3.0, $host['max_jitter']);
    }

    public function testAggregateMetricsPreservesAddress(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'address' => '192.168.1.100', 'latency' => 10.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals('192.168.1.100', $host['address']);
    }

    // =========================================================================
    // Aggregation Tests - Edge Cases
    // =========================================================================

    public function testAggregateMetricsWithNullLatencies(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => null, 'status' => 'failure'],
            ['hostname' => 'host1', 'latency' => null, 'status' => 'failure'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertNull($host['min_latency']);
        $this->assertNull($host['max_latency']);
        $this->assertNull($host['avg_latency']);
        $this->assertEquals(2, $host['failure_count']);
    }

    public function testAggregateMetricsWithNoJitter(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 20.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertNull($host['avg_jitter']);
        $this->assertNull($host['max_jitter']);
    }

    public function testAggregateMetricsWithUnknownHostname(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],  // No hostname
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(1, $result);
        $this->assertEquals('unknown', $result[0]['hostname']);
    }

    public function testAggregateMetricsMixedNullLatencies(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => null, 'status' => 'failure'],
            ['hostname' => 'host1', 'latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // Only non-null latencies should be used
        $this->assertEquals(10.0, $host['min_latency']);
        $this->assertEquals(30.0, $host['max_latency']);
        $this->assertEquals(20.0, $host['avg_latency']);
    }

    public function testAggregateMetricsSingleEntry(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 50.5, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(50.5, $host['min_latency']);
        $this->assertEquals(50.5, $host['max_latency']);
        $this->assertEquals(50.5, $host['avg_latency']);
        $this->assertEquals(1, $host['sample_count']);
    }

    public function testAggregateMetricsStringLatencies(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => '10.5', 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => '20.5', 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        // Should convert strings to floats
        $this->assertEquals(10.5, $host['min_latency']);
        $this->assertEquals(20.5, $host['max_latency']);
    }

    public function testAggregateMetricsHighLatency(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 500.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 1000.0, 'status' => 'success'],
            ['hostname' => 'host1', 'latency' => 2000.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(500.0, $host['min_latency']);
        $this->assertEquals(2000.0, $host['max_latency']);
        $this->assertEqualsWithDelta(1166.667, $host['avg_latency'], 0.001);
    }

    public function testAggregateMetricsManyHosts(): void
    {
        $metrics = [];
        for ($i = 1; $i <= 10; $i++) {
            $metrics[] = [
                'hostname' => "host{$i}",
                'address' => "192.168.1.{$i}",
                'latency' => (float)($i * 10),
                'status' => 'success'
            ];
        }

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(10, $result);
    }

    public function testAggregateMetricsImplicitFailure(): void
    {
        // Missing status should count as failure
        $metrics = [
            ['hostname' => 'host1', 'latency' => null],  // No status field
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals(1, $host['success_count']);
        $this->assertEquals(1, $host['failure_count']);
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
        $metrics = [
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host2', 'address' => '192.168.1.2', 'latency' => 20.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);  // One entry per host
    }

    public function testAggregateForRollupAddsTimestamps(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'latency' => 10.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);
        $entry = $result[0];

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('period_start', $entry);
        $this->assertArrayHasKey('period_end', $entry);
        $this->assertEquals(1000, $entry['timestamp']);
        $this->assertEquals(1000, $entry['period_start']);
        $this->assertEquals(1060, $entry['period_end']);
    }

    public function testAggregateForRollupIncludesHostData(): void
    {
        $metrics = [
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 10.0, 'status' => 'success'],
            ['hostname' => 'host1', 'address' => '192.168.1.1', 'latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);
        $entry = $result[0];

        $this->assertEquals('host1', $entry['hostname']);
        $this->assertEquals('192.168.1.1', $entry['address']);
        $this->assertEquals(10.0, $entry['min_latency']);
        $this->assertEquals(30.0, $entry['max_latency']);
    }

    // =========================================================================
    // State Management Tests
    // =========================================================================

    public function testGetRollupStateInitializesNewState(): void
    {
        $state = $this->collector->getRollupState();

        $this->assertIsArray($state);
        $this->assertArrayHasKey('1min', $state);
        $this->assertArrayHasKey('5min', $state);
    }

    public function testSaveRollupStateWritesFile(): void
    {
        $state = [
            '1min' => ['last_processed' => 1000, 'last_bucket_end' => 1060, 'last_rollup' => 1100]
        ];

        $result = $this->collector->saveRollupState($state);

        $this->assertTrue($result);
        $this->assertFileExists($this->collector->getStateFilePath());
    }

    public function testGetRollupStateReadsExistingState(): void
    {
        $state = ['1min' => ['last_processed' => 5000]];
        file_put_contents($this->collector->getStateFilePath(), json_encode($state));

        $loaded = $this->collector->getRollupState();

        $this->assertEquals(5000, $loaded['1min']['last_processed']);
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
        $this->assertEquals('5min', $this->collector->getBestRollupTier(12));
        $this->assertEquals('5min', $this->collector->getBestRollupTier(48));
    }

    public function testGetBestRollupTierReturns30MinForLongerRange(): void
    {
        $this->assertEquals('30min', $this->collector->getBestRollupTier(72));
        $this->assertEquals('30min', $this->collector->getBestRollupTier(336));
    }

    public function testGetBestRollupTierReturns2HourForLongRange(): void
    {
        $this->assertEquals('2hour', $this->collector->getBestRollupTier(500));
        $this->assertEquals('2hour', $this->collector->getBestRollupTier(720));
    }

    // =========================================================================
    // Read/Write Operations Tests
    // =========================================================================

    public function testWriteMetricsBatchWritesToFile(): void
    {
        $entries = [
            ['timestamp' => time(), 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => time(), 'hostname' => 'host2', 'latency' => 20.0],
        ];

        $result = $this->collector->writeMetricsBatch($entries);

        $this->assertTrue($result);
        $this->assertFileExists($this->metricsFile);
    }

    public function testReadRawMetricsReturnsEmptyForMissingFile(): void
    {
        $result = $this->collector->readRawMetrics(0);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadRawMetricsReadsFromFile(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 20.0],
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->readRawMetrics(0);

        $this->assertCount(2, $result);
    }

    public function testReadRawMetricsFiltersByTimestamp(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 3600, 'hostname' => 'host1', 'latency' => 10.0],  // Old
            ['timestamp' => $now - 60, 'hostname' => 'host2', 'latency' => 20.0],   // Recent
            ['timestamp' => $now, 'hostname' => 'host3', 'latency' => 30.0],        // Now
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->readRawMetrics($now - 120);  // Last 2 minutes

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // readRollupData Tests
    // =========================================================================

    public function testReadRollupDataFromNonexistentFile(): void
    {
        $result = $this->collector->readRollupData('1min');

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['data']);
    }

    public function testReadRollupDataReturnsEntries(): void
    {
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('1min');

        $entries = [
            ['timestamp' => $now - 120, 'hostname' => 'host1', 'avg_latency' => 10.0],
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'avg_latency' => 20.0],
            ['timestamp' => $now, 'hostname' => 'host1', 'avg_latency' => 30.0],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollupData('1min', $now - 180, $now + 60);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);
    }

    public function testReadRollupDataFiltersByHostname(): void
    {
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('1min');

        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'avg_latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'avg_latency' => 20.0],
            ['timestamp' => $now, 'hostname' => 'host1', 'avg_latency' => 30.0],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollupData('1min', $now - 60, $now + 60, 'host1');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $entry) {
            $this->assertEquals('host1', $entry['hostname']);
        }
    }

    public function testReadRollupDataSortsByTimestampAndHostname(): void
    {
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('1min');

        // Write in random order
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host2', 'value' => 3],
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'value' => 1],
            ['timestamp' => $now, 'hostname' => 'host1', 'value' => 2],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollupData('1min', $now - 120, $now + 60);

        $this->assertTrue($result['success']);

        // Should be sorted by timestamp, then hostname
        $this->assertEquals($now - 60, $result['data'][0]['timestamp']);
        $this->assertEquals($now, $result['data'][1]['timestamp']);
        $this->assertEquals('host1', $result['data'][1]['hostname']);
        $this->assertEquals('host2', $result['data'][2]['hostname']);
    }

    // =========================================================================
    // getMetrics Tests
    // =========================================================================

    public function testGetMetricsReturnsEmptyForNoData(): void
    {
        $result = $this->collector->getMetrics(24);

        $this->assertFalse($result['success']);
    }

    public function testGetMetricsIncludesTierInfo(): void
    {
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('1min');

        $entries = [
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'avg_latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host1', 'avg_latency' => 20.0],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->getMetrics(1);  // 1 hour = 1min tier

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tier_info', $result);
        $this->assertEquals('1min', $result['tier_info']['tier']);
    }

    public function testGetMetricsFiltersByHostname(): void
    {
        $now = time();
        $rollupFile = $this->collector->getRollupFilePath('1min');

        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'avg_latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'avg_latency' => 20.0],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->getMetrics(1, 'host1');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('host1', $result['data'][0]['hostname']);
    }

    // =========================================================================
    // getRawMetrics Tests
    // =========================================================================

    public function testGetRawMetricsReturnsEmptyForNoData(): void
    {
        $result = $this->collector->getRawMetrics(24);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['data']);
    }

    public function testGetRawMetricsReturnsData(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 20.0],
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->getRawMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['count']);
    }

    public function testGetRawMetricsFiltersByHostname(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now, 'hostname' => 'host1', 'latency' => 10.0],
            ['timestamp' => $now, 'hostname' => 'host2', 'latency' => 20.0],
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->getRawMetrics(1, 'host1');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('host1', $result['data'][0]['hostname']);
        $this->assertEquals('host1', $result['hostname']);
    }

    public function testGetRawMetricsIncludesPeriodInfo(): void
    {
        $result = $this->collector->getRawMetrics(24);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('start', $result['period']);
        $this->assertArrayHasKey('end', $result['period']);
        $this->assertArrayHasKey('hours', $result['period']);
        $this->assertEquals(24, $result['period']['hours']);
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

    public function testGetRollupTiersInfoShowsFileStatus(): void
    {
        // Create 1min file
        $rollupFile = $this->collector->getRollupFilePath('1min');
        file_put_contents($rollupFile, 'test content');

        $info = $this->collector->getRollupTiersInfo();

        $this->assertTrue($info['1min']['file_exists']);
        $this->assertGreaterThan(0, $info['1min']['file_size']);
        $this->assertFalse($info['5min']['file_exists']);
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
    // Data Provider Tests
    // =========================================================================

    /**
     * @dataProvider latencyDataProvider
     */
    public function testAggregateMetricsWithVariousLatencies(array $latencies, float $expectedMin, float $expectedMax, float $expectedAvg): void
    {
        $metrics = array_map(fn($l) => [
            'hostname' => 'host1',
            'latency' => $l,
            'status' => 'success'
        ], $latencies);

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals($expectedMin, $host['min_latency']);
        $this->assertEquals($expectedMax, $host['max_latency']);
        $this->assertEqualsWithDelta($expectedAvg, $host['avg_latency'], 0.001);
    }

    public static function latencyDataProvider(): array
    {
        return [
            'single value' => [[50.0], 50.0, 50.0, 50.0],
            'two values' => [[10.0, 20.0], 10.0, 20.0, 15.0],
            'three values' => [[10.0, 20.0, 30.0], 10.0, 30.0, 20.0],
            'unsorted' => [[30.0, 10.0, 20.0], 10.0, 30.0, 20.0],
            'with decimals' => [[1.5, 2.5, 3.5], 1.5, 3.5, 2.5],
            'large range' => [[1.0, 1000.0], 1.0, 1000.0, 500.5],
        ];
    }

    /**
     * @dataProvider jitterDataProvider
     */
    public function testAggregateMetricsWithVariousJitters(array $jitters, float $expectedAvg, float $expectedMax): void
    {
        $metrics = array_map(fn($j) => [
            'hostname' => 'host1',
            'latency' => 10.0,
            'jitter' => $j,
            'status' => 'success'
        ], $jitters);

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals($expectedAvg, $host['avg_jitter']);
        $this->assertEquals($expectedMax, $host['max_jitter']);
    }

    public static function jitterDataProvider(): array
    {
        return [
            'single value' => [[2.0], 2.0, 2.0],
            'two values' => [[1.0, 3.0], 2.0, 3.0],
            'three values' => [[1.0, 2.0, 3.0], 2.0, 3.0],
            'all same' => [[5.0, 5.0, 5.0], 5.0, 5.0],
            'with zero' => [[0.0, 2.0, 4.0], 2.0, 4.0],
        ];
    }

    /**
     * @dataProvider statusDataProvider
     */
    public function testAggregateMetricsCountsStatuses(array $statuses, int $expectedSuccess, int $expectedFailure): void
    {
        $metrics = array_map(fn($s) => [
            'hostname' => 'host1',
            'latency' => $s === 'success' ? 10.0 : null,
            'status' => $s
        ], $statuses);

        $result = $this->collector->aggregateMetrics($metrics);
        $host = $result[0];

        $this->assertEquals($expectedSuccess, $host['success_count']);
        $this->assertEquals($expectedFailure, $host['failure_count']);
    }

    public static function statusDataProvider(): array
    {
        return [
            'all success' => [['success', 'success', 'success'], 3, 0],
            'all failure' => [['failure', 'failure'], 0, 2],
            'mixed' => [['success', 'failure', 'success'], 2, 1],
            'mostly success' => [['success', 'success', 'success', 'failure'], 3, 1],
        ];
    }

    /**
     * @dataProvider tierSelectionDataProvider
     */
    public function testGetBestRollupTierForVariousHours(int $hours, string $expectedTier): void
    {
        $result = $this->collector->getBestRollupTier($hours);

        $this->assertEquals($expectedTier, $result);
    }

    public static function tierSelectionDataProvider(): array
    {
        return [
            '1 hour' => [1, '1min'],
            '6 hours' => [6, '1min'],
            '7 hours' => [7, '5min'],
            '24 hours' => [24, '5min'],
            '48 hours' => [48, '5min'],
            '49 hours' => [49, '30min'],
            '1 week' => [168, '30min'],
            '2 weeks' => [336, '30min'],
            '15 days' => [360, '2hour'],
            '30 days' => [720, '2hour'],
        ];
    }
}
