<?php
/**
 * Unit tests for PingCollector class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\PingCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;

/**
 * Testable PingCollector that allows instance creation without singleton
 */
class TestablePingCollector extends PingCollector
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
        $parent = new \ReflectionClass(PingCollector::class);

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
}

class PingCollectorTest extends TestCase
{
    private TestablePingCollector $collector;
    private string $dataDir;
    private string $metricsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = $this->createTempDir('ping-data');
        $this->metricsFile = $this->testTmpDir . '/ping-metrics.log';

        $this->collector = new TestablePingCollector(
            $this->dataDir,
            $this->metricsFile
        );
    }

    // =========================================================================
    // Singleton and Instance Tests
    // =========================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = PingCollector::getInstance();
        $instance2 = PingCollector::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsPingCollector(): void
    {
        $instance = PingCollector::getInstance();

        $this->assertInstanceOf(PingCollector::class, $instance);
    }

    // =========================================================================
    // Path Generation Tests
    // =========================================================================

    public function testGetRollupFilePathReturnsCorrectPath(): void
    {
        $path = $this->collector->getRollupFilePath('1min');

        $this->assertEquals($this->dataDir . '/1min.log', $path);
    }

    public function testGetRollupFilePathForDifferentTiers(): void
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
    // Aggregation Tests - Basic
    // =========================================================================

    public function testAggregateMetricsReturnsNullForEmptyArray(): void
    {
        $result = $this->collector->aggregateMetrics([]);

        $this->assertNull($result);
    }

    public function testAggregateMetricsCalculatesBasicStats(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success', 'host' => 'host1'],
            ['latency' => 20.0, 'status' => 'success', 'host' => 'host1'],
            ['latency' => 30.0, 'status' => 'success', 'host' => 'host2'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(10.0, $result['min_latency']);
        $this->assertEquals(30.0, $result['max_latency']);
        $this->assertEquals(20.0, $result['avg_latency']);
        $this->assertEquals(3, $result['sample_count']);
    }

    public function testAggregateMetricsCountsSuccessAndFailure(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],
            ['latency' => 20.0, 'status' => 'success'],
            ['latency' => null, 'status' => 'failure'],
            ['latency' => null, 'status' => 'failure'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(2, $result['success_count']);
        $this->assertEquals(2, $result['failure_count']);
        $this->assertEquals(4, $result['sample_count']);
    }

    public function testAggregateMetricsCalculatesFailureFromSampleCount(): void
    {
        // When failure_count is not explicit, calculate from total - success
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],
            ['latency' => null],  // No status = implicit failure
            ['latency' => null],  // No status = implicit failure
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(2, $result['failure_count']);
    }

    public function testAggregateMetricsTracksHosts(): void
    {
        $metrics = [
            ['latency' => 10.0, 'host' => 'host1'],
            ['latency' => 20.0, 'host' => 'host1'],
            ['latency' => 30.0, 'host' => 'host2'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertArrayHasKey('hosts', $result);
        $this->assertEquals(2, $result['hosts']['host1']);
        $this->assertEquals(1, $result['hosts']['host2']);
    }

    // =========================================================================
    // Aggregation Tests - Edge Cases
    // =========================================================================

    public function testAggregateMetricsWithAllNullLatencies(): void
    {
        $metrics = [
            ['latency' => null, 'status' => 'failure'],
            ['latency' => null, 'status' => 'failure'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertNull($result['min_latency']);
        $this->assertNull($result['max_latency']);
        $this->assertNull($result['avg_latency']);
        $this->assertEquals(2, $result['sample_count']);
        $this->assertEquals(0, $result['success_count']);
        $this->assertEquals(2, $result['failure_count']);
    }

    public function testAggregateMetricsSingleEntry(): void
    {
        $metrics = [
            ['latency' => 50.5, 'status' => 'success', 'host' => 'single-host'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(50.5, $result['min_latency']);
        $this->assertEquals(50.5, $result['max_latency']);
        $this->assertEquals(50.5, $result['avg_latency']);
        $this->assertEquals(1, $result['sample_count']);
    }

    public function testAggregateMetricsRoundsPrecision(): void
    {
        $metrics = [
            ['latency' => 10.12345, 'status' => 'success'],
            ['latency' => 20.67891, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        // Should round to 3 decimal places
        $this->assertEquals(10.123, $result['min_latency']);
        $this->assertEquals(20.679, $result['max_latency']);
        $this->assertEquals(15.401, $result['avg_latency']);
    }

    public function testAggregateMetricsWithMixedNullLatencies(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],
            ['latency' => null, 'status' => 'failure'],
            ['latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        // Only non-null latencies should be used for calculations
        $this->assertEquals(10.0, $result['min_latency']);
        $this->assertEquals(30.0, $result['max_latency']);
        $this->assertEquals(20.0, $result['avg_latency']);
    }

    public function testAggregateMetricsWithStringLatencies(): void
    {
        $metrics = [
            ['latency' => '10.5', 'status' => 'success'],
            ['latency' => '20.5', 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        // Should convert strings to floats
        $this->assertEquals(10.5, $result['min_latency']);
        $this->assertEquals(20.5, $result['max_latency']);
        $this->assertEquals(15.5, $result['avg_latency']);
    }

    public function testAggregateMetricsWithZeroLatency(): void
    {
        $metrics = [
            ['latency' => 0.0, 'status' => 'success'],
            ['latency' => 10.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(0.0, $result['min_latency']);
        $this->assertEquals(10.0, $result['max_latency']);
        $this->assertEquals(5.0, $result['avg_latency']);
    }

    public function testAggregateMetricsWithHighLatency(): void
    {
        $metrics = [
            ['latency' => 1000.0, 'status' => 'success'],
            ['latency' => 2000.0, 'status' => 'success'],
            ['latency' => 5000.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals(1000.0, $result['min_latency']);
        $this->assertEquals(5000.0, $result['max_latency']);
        $this->assertEqualsWithDelta(2666.667, $result['avg_latency'], 0.001);
    }

    public function testAggregateMetricsWithManyHosts(): void
    {
        $metrics = [];
        for ($i = 1; $i <= 10; $i++) {
            for ($j = 0; $j < $i; $j++) {
                $metrics[] = ['latency' => 10.0, 'host' => "host{$i}"];
            }
        }

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertCount(10, $result['hosts']);
        $this->assertEquals(1, $result['hosts']['host1']);
        $this->assertEquals(10, $result['hosts']['host10']);
    }

    // =========================================================================
    // aggregateForRollup Tests
    // =========================================================================

    public function testAggregateForRollupReturnsNullForEmptyMetrics(): void
    {
        $result = $this->collector->aggregateForRollup([], 1000, 60);

        $this->assertNull($result);
    }

    public function testAggregateForRollupAddsTimestamps(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],
            ['latency' => 20.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);

        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertEquals(1000, $result['timestamp']);
        $this->assertEquals(1000, $result['period_start']);
        $this->assertEquals(1060, $result['period_end']);
    }

    public function testAggregateForRollupIncludesAggregatedData(): void
    {
        $metrics = [
            ['latency' => 10.0, 'status' => 'success'],
            ['latency' => 30.0, 'status' => 'success'],
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);

        $this->assertEquals(10.0, $result['min_latency']);
        $this->assertEquals(30.0, $result['max_latency']);
        $this->assertEquals(20.0, $result['avg_latency']);
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
            ['timestamp' => $now - 60, 'latency' => 10.0],
            ['timestamp' => $now, 'latency' => 20.0],
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->readRawMetrics(0);

        $this->assertCount(2, $result);
    }

    public function testReadRawMetricsFiltersByTimestamp(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 3600, 'latency' => 10.0],  // Old
            ['timestamp' => $now - 60, 'latency' => 20.0],   // Recent
            ['timestamp' => $now, 'latency' => 30.0],        // Now
        ];
        $this->writeJsonLinesFile($this->metricsFile, $entries);

        $result = $this->collector->readRawMetrics($now - 120);  // Last 2 minutes

        $this->assertCount(2, $result);
    }

    public function testAppendRollupEntriesWritesToFile(): void
    {
        $entries = [
            ['timestamp' => time(), 'value' => 'test'],
        ];
        $rollupFile = $this->collector->getRollupFilePath('1min');

        $result = $this->collector->appendRollupEntries($rollupFile, $entries);

        $this->assertTrue($result);
        $this->assertFileExists($rollupFile);
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
            ['timestamp' => $now - 120, 'value' => 1],
            ['timestamp' => $now - 60, 'value' => 2],
            ['timestamp' => $now, 'value' => 3],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollupData('1min', $now - 180, $now + 60);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);
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
            ['timestamp' => $now - 60, 'value' => 1],
            ['timestamp' => $now, 'value' => 2],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->getMetrics(1);  // 1 hour = 1min tier

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tier_info', $result);
        $this->assertEquals('1min', $result['tier_info']['tier']);
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
        $metrics = array_map(fn($l) => ['latency' => $l, 'status' => 'success'], $latencies);

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals($expectedMin, $result['min_latency']);
        $this->assertEquals($expectedMax, $result['max_latency']);
        $this->assertEqualsWithDelta($expectedAvg, $result['avg_latency'], 0.001);
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
     * @dataProvider statusDataProvider
     */
    public function testAggregateMetricsCountsStatuses(array $statuses, int $expectedSuccess, int $expectedFailure): void
    {
        $metrics = array_map(fn($s) => ['latency' => 10.0, 'status' => $s], $statuses);

        $result = $this->collector->aggregateMetrics($metrics);

        $this->assertEquals($expectedSuccess, $result['success_count']);
        $this->assertEquals($expectedFailure, $result['failure_count']);
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
}
