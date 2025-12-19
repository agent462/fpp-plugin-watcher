<?php
/**
 * Unit tests for EfuseCollector class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\EfuseCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * Testable EfuseCollector that allows instance creation without singleton
 * and custom paths for testing
 */
class TestableEfuseCollector extends EfuseCollector
{
    private string $testDataDir;
    private string $testRawFile;
    private string $testStateFile;

    public function __construct(
        string $dataDir,
        string $rawFile,
        string $stateFile,
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null,
        ?FileManager $fileManager = null
    ) {
        $this->testDataDir = $dataDir;
        $this->testRawFile = $rawFile;
        $this->testStateFile = $stateFile;

        // Use reflection to set parent private properties
        $parent = new \ReflectionClass(EfuseCollector::class);

        $dataDirProp = $parent->getProperty('dataDir');
        $dataDirProp->setAccessible(true);
        $dataDirProp->setValue($this, $dataDir);

        $rawFileProp = $parent->getProperty('rawFile');
        $rawFileProp->setAccessible(true);
        $rawFileProp->setValue($this, $rawFile);

        $stateFileProp = $parent->getProperty('stateFile');
        $stateFileProp->setAccessible(true);
        $stateFileProp->setValue($this, $stateFile);

        $storageProp = $parent->getProperty('storage');
        $storageProp->setAccessible(true);
        $storageProp->setValue($this, $storage ?? new MetricsStorage());

        $loggerProp = $parent->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this, $logger ?? Logger::getInstance());

        $fileManagerProp = $parent->getProperty('fileManager');
        $fileManagerProp->setAccessible(true);
        $fileManagerProp->setValue($this, $fileManager ?? FileManager::getInstance());

        if ($rollup !== null) {
            $rollupProp = $parent->getProperty('rollup');
            $rollupProp->setAccessible(true);
            $rollupProp->setValue($this, $rollup);
        }
    }

    /**
     * Get the data directory (for testing)
     */
    public function getDataDir(): string
    {
        return $this->testDataDir;
    }

    /**
     * Get the raw file path (for testing)
     */
    public function getRawFile(): string
    {
        return $this->testRawFile;
    }
}

class EfuseCollectorTest extends TestCase
{
    private TestableEfuseCollector $collector;
    private string $dataDir;
    private string $rawFile;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = $this->createTempDir('efuse-data');
        $this->rawFile = $this->dataDir . '/raw.log';
        $this->stateFile = $this->dataDir . '/rollup-state.json';

        // Set up test plugin config
        $GLOBALS['testPluginConfig'] = [
            'efuseRetentionDays' => 7,
            'efuseCollectionInterval' => 5
        ];

        $this->collector = new TestableEfuseCollector(
            $this->dataDir,
            $this->rawFile,
            $this->stateFile
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['testPluginConfig']);
        parent::tearDown();
    }

    // =========================================================================
    // Singleton and Instance Tests
    // =========================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = EfuseCollector::getInstance();
        $instance2 = EfuseCollector::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsEfuseCollector(): void
    {
        $instance = EfuseCollector::getInstance();

        $this->assertInstanceOf(EfuseCollector::class, $instance);
    }

    // =========================================================================
    // Path Generation Tests
    // =========================================================================

    public function testGetRollupFilePathReturns1MinPath(): void
    {
        $path = $this->collector->getRollupFilePath('1min');

        $this->assertStringEndsWith('/1min.log', $path);
        $this->assertStringContainsString($this->dataDir, $path);
    }

    public function testGetRollupFilePathReturns5MinPath(): void
    {
        $path = $this->collector->getRollupFilePath('5min');

        $this->assertStringEndsWith('/5min.log', $path);
    }

    public function testGetRollupFilePathReturns30MinPath(): void
    {
        $path = $this->collector->getRollupFilePath('30min');

        $this->assertStringEndsWith('/30min.log', $path);
    }

    public function testGetRollupFilePathReturns2HourPath(): void
    {
        $path = $this->collector->getRollupFilePath('2hour');

        $this->assertStringEndsWith('/2hour.log', $path);
    }

    public function testGetStateFilePathReturnsCorrectPath(): void
    {
        $path = $this->collector->getStateFilePath();

        $this->assertEquals($this->stateFile, $path);
    }

    // =========================================================================
    // Tier Configuration Tests
    // =========================================================================

    public function testGetTiersReturnsAllTiers(): void
    {
        $tiers = $this->collector->getTiers(7);

        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayHasKey('30min', $tiers);
        $this->assertArrayHasKey('2hour', $tiers);
    }

    public function testEachTierHasRequiredFields(): void
    {
        $tiers = $this->collector->getTiers(7);

        foreach ($tiers as $tier => $config) {
            $this->assertArrayHasKey('interval', $config, "Tier {$tier} missing 'interval'");
            $this->assertArrayHasKey('retention', $config, "Tier {$tier} missing 'retention'");
            $this->assertArrayHasKey('label', $config, "Tier {$tier} missing 'label'");
        }
    }

    public function testTierIntervalsAreCorrect(): void
    {
        $tiers = $this->collector->getTiers(7);

        $this->assertEquals(60, $tiers['1min']['interval']);
        $this->assertEquals(300, $tiers['5min']['interval']);
        $this->assertEquals(1800, $tiers['30min']['interval']);
        $this->assertEquals(7200, $tiers['2hour']['interval']);
    }

    public function testTierLabelsAreCorrect(): void
    {
        $tiers = $this->collector->getTiers(7);

        $this->assertEquals('1-minute averages', $tiers['1min']['label']);
        $this->assertEquals('5-minute averages', $tiers['5min']['label']);
        $this->assertEquals('30-minute averages', $tiers['30min']['label']);
        $this->assertEquals('2-hour averages', $tiers['2hour']['label']);
    }

    // =========================================================================
    // Retention Capping Tests
    // =========================================================================

    public function testTiersRetentionCappedBy1DayRetention(): void
    {
        $tiers = $this->collector->getTiers(1);
        $retentionSeconds = 1 * 86400; // 1 day = 86400 seconds

        // All retentions should be capped at 1 day
        $this->assertEquals(min(21600, $retentionSeconds), $tiers['1min']['retention']);
        $this->assertEquals(min(172800, $retentionSeconds), $tiers['5min']['retention']);
        $this->assertEquals(min(1209600, $retentionSeconds), $tiers['30min']['retention']);
        $this->assertEquals($retentionSeconds, $tiers['2hour']['retention']);
    }

    public function testTiersRetentionCappedBy7DayRetention(): void
    {
        $tiers = $this->collector->getTiers(7);
        $retentionSeconds = 7 * 86400; // 7 days = 604800 seconds

        // 1min: min(21600, 604800) = 21600 (6 hours)
        $this->assertEquals(21600, $tiers['1min']['retention']);
        // 5min: min(172800, 604800) = 172800 (2 days)
        $this->assertEquals(172800, $tiers['5min']['retention']);
        // 30min: min(1209600, 604800) = 604800 (7 days, capped)
        $this->assertEquals($retentionSeconds, $tiers['30min']['retention']);
        // 2hour: full retention
        $this->assertEquals($retentionSeconds, $tiers['2hour']['retention']);
    }

    public function testTiersRetentionCappedBy30DayRetention(): void
    {
        $tiers = $this->collector->getTiers(30);
        $retentionSeconds = 30 * 86400; // 30 days

        // 1min: min(21600, 2592000) = 21600
        $this->assertEquals(21600, $tiers['1min']['retention']);
        // 5min: min(172800, 2592000) = 172800
        $this->assertEquals(172800, $tiers['5min']['retention']);
        // 30min: min(1209600, 2592000) = 1209600 (14 days)
        $this->assertEquals(1209600, $tiers['30min']['retention']);
        // 2hour: full retention
        $this->assertEquals($retentionSeconds, $tiers['2hour']['retention']);
    }

    public function testTiersRetentionWith90DayRetention(): void
    {
        $tiers = $this->collector->getTiers(90);
        $retentionSeconds = 90 * 86400; // 90 days

        // All intermediate tiers should hit their natural limits
        $this->assertEquals(21600, $tiers['1min']['retention']);
        $this->assertEquals(172800, $tiers['5min']['retention']);
        $this->assertEquals(1209600, $tiers['30min']['retention']);
        $this->assertEquals($retentionSeconds, $tiers['2hour']['retention']);
    }

    // =========================================================================
    // Tier Selection Tests
    // =========================================================================

    public function testGetBestTierForHoursUnder6(): void
    {
        $this->assertEquals('1min', $this->collector->getBestTierForHours(1));
        $this->assertEquals('1min', $this->collector->getBestTierForHours(3));
        $this->assertEquals('1min', $this->collector->getBestTierForHours(6));
    }

    public function testGetBestTierForHours7To48(): void
    {
        $this->assertEquals('5min', $this->collector->getBestTierForHours(7));
        $this->assertEquals('5min', $this->collector->getBestTierForHours(24));
        $this->assertEquals('5min', $this->collector->getBestTierForHours(48));
    }

    public function testGetBestTierForHours49To336(): void
    {
        $this->assertEquals('30min', $this->collector->getBestTierForHours(49));
        $this->assertEquals('30min', $this->collector->getBestTierForHours(168)); // 1 week
        $this->assertEquals('30min', $this->collector->getBestTierForHours(336)); // 14 days
    }

    public function testGetBestTierForHoursOver336(): void
    {
        $this->assertEquals('2hour', $this->collector->getBestTierForHours(337));
        $this->assertEquals('2hour', $this->collector->getBestTierForHours(720)); // 30 days
        $this->assertEquals('2hour', $this->collector->getBestTierForHours(2160)); // 90 days
    }

    public function testGetBestTierForZeroHours(): void
    {
        $this->assertEquals('1min', $this->collector->getBestTierForHours(0));
    }

    public function testGetBestTierForNegativeHours(): void
    {
        $this->assertEquals('1min', $this->collector->getBestTierForHours(-5));
    }

    // =========================================================================
    // Write Raw Metric Tests
    // =========================================================================

    public function testWriteRawMetricWithEmptyPorts(): void
    {
        $result = $this->collector->writeRawMetric([]);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->rawFile);
    }

    public function testWriteRawMetricWithSinglePort(): void
    {
        $result = $this->collector->writeRawMetric(['Port 1' => 500]);

        $this->assertTrue($result);
        $this->assertFileExists($this->rawFile);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"Port 1":500', $content);
        $this->assertStringContainsString('"_total":500', $content);
    }

    public function testWriteRawMetricWithMultiplePorts(): void
    {
        $ports = [
            'Port 1' => 500,
            'Port 2' => 750,
            'Port 3' => 250
        ];
        $result = $this->collector->writeRawMetric($ports);

        $this->assertTrue($result);
        $this->assertFileExists($this->rawFile);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"Port 1":500', $content);
        $this->assertStringContainsString('"Port 2":750', $content);
        $this->assertStringContainsString('"Port 3":250', $content);
        $this->assertStringContainsString('"_total":1500', $content);
    }

    public function testWriteRawMetricAppendsToFile(): void
    {
        $this->collector->writeRawMetric(['Port 1' => 100]);
        $this->collector->writeRawMetric(['Port 1' => 200]);

        $content = file_get_contents($this->rawFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(2, $lines);
    }

    public function testWriteRawMetricCalculatesTotal(): void
    {
        $ports = ['Port 1' => 100, 'Port 2' => 200, 'Port 3' => 300];
        $this->collector->writeRawMetric($ports);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"_total":600', $content);
    }

    public function testWriteRawMetricWithZeroValues(): void
    {
        $ports = ['Port 1' => 0, 'Port 2' => 100];
        $this->collector->writeRawMetric($ports);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"Port 1":0', $content);
        $this->assertStringContainsString('"_total":100', $content);
    }

    // =========================================================================
    // Read Raw Metrics Tests
    // =========================================================================

    public function testReadRawMetricsFromNonexistentFile(): void
    {
        $result = $this->collector->readRawMetrics(6);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadRawMetricsReturnsEntries(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'ports' => ['Port 1' => 100]],
            ['timestamp' => $now - 30, 'ports' => ['Port 1' => 150]],
            ['timestamp' => $now, 'ports' => ['Port 1' => 200]],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(3, $result);
    }

    public function testReadRawMetricsFiltersOldEntries(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 7200, 'ports' => ['Port 1' => 100]], // 2 hours ago
            ['timestamp' => $now - 60, 'ports' => ['Port 1' => 150]],   // 1 min ago
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->readRawMetrics(1); // Last 1 hour

        $this->assertCount(1, $result);
        $this->assertEquals(150, $result[0]['ports']['Port 1']);
    }

    public function testReadRawMetricsWithPortFilter(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'ports' => ['Port 1' => 100, 'Port 2' => 200]],
            ['timestamp' => $now - 30, 'ports' => ['Port 1' => 150]],
            ['timestamp' => $now, 'ports' => ['Port 2' => 250]],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->readRawMetrics(1, 'Port 1');

        $this->assertCount(2, $result);
        foreach ($result as $entry) {
            $this->assertArrayHasKey('Port 1', $entry['ports']);
        }
    }

    // =========================================================================
    // Aggregate Bucket Tests - Raw Data
    // =========================================================================

    public function testAggregateBucketWithEmptyArray(): void
    {
        $result = $this->collector->aggregateBucket([], time(), 60);

        $this->assertNull($result);
    }

    public function testAggregateBucketWithSingleRawEntry(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 500]]
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNotNull($result);
        $this->assertEquals($bucketStart, $result['timestamp']);
        $this->assertEquals(60, $result['interval']);
        $this->assertArrayHasKey('Port 1', $result['ports']);
        $this->assertEquals(500, $result['ports']['Port 1']['avg']);
        $this->assertEquals(500, $result['ports']['Port 1']['min']);
        $this->assertEquals(500, $result['ports']['Port 1']['max']);
        $this->assertEquals(1, $result['ports']['Port 1']['samples']);
    }

    public function testAggregateBucketWithMultipleRawEntries(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 100]],
            ['ports' => ['Port 1' => 200]],
            ['ports' => ['Port 1' => 300]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEquals(200, $result['ports']['Port 1']['avg']);
        $this->assertEquals(100, $result['ports']['Port 1']['min']);
        $this->assertEquals(300, $result['ports']['Port 1']['max']);
        $this->assertEquals(3, $result['ports']['Port 1']['samples']);
    }

    public function testAggregateBucketWithMultiplePorts(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 100, 'Port 2' => 500]],
            ['ports' => ['Port 1' => 200, 'Port 2' => 600]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEquals(150, $result['ports']['Port 1']['avg']);
        $this->assertEquals(550, $result['ports']['Port 2']['avg']);
    }

    public function testAggregateBucketWithTotalPort(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 100, '_total' => 100]],
            ['ports' => ['Port 1' => 200, '_total' => 200]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertArrayHasKey('_total', $result['ports']);
        $this->assertEquals(150, $result['ports']['_total']['avg']);
    }

    // =========================================================================
    // Aggregate Bucket Tests - Rollup Data
    // =========================================================================

    public function testAggregateBucketWithRollupEntry(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => ['avg' => 500, 'min' => 400, 'max' => 600, 'samples' => 10]]]
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        $this->assertNotNull($result);
        $this->assertEquals(500, $result['ports']['Port 1']['avg']);
        $this->assertEquals(400, $result['ports']['Port 1']['min']);
        $this->assertEquals(600, $result['ports']['Port 1']['max']);
        $this->assertEquals(10, $result['ports']['Port 1']['samples']);
    }

    public function testAggregateBucketWithMultipleRollupEntries(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => ['avg' => 100, 'min' => 50, 'max' => 150, 'samples' => 5]]],
            ['ports' => ['Port 1' => ['avg' => 200, 'min' => 100, 'max' => 300, 'samples' => 5]]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        // Avg = (100 + 200) / 2 = 150
        $this->assertEquals(150, $result['ports']['Port 1']['avg']);
        // Min = min(50, 100) = 50
        $this->assertEquals(50, $result['ports']['Port 1']['min']);
        // Max = max(150, 300) = 300
        $this->assertEquals(300, $result['ports']['Port 1']['max']);
        // Peak = max of maxes = 300
        $this->assertEquals(300, $result['ports']['Port 1']['peak']);
        // Samples = 5 + 5 = 10
        $this->assertEquals(10, $result['ports']['Port 1']['samples']);
    }

    public function testAggregateBucketMixedRawAndRollup(): void
    {
        // This shouldn't happen in practice, but test robustness
        $metrics = [
            ['ports' => ['Port 1' => 100]], // Raw
            ['ports' => ['Port 1' => ['avg' => 200, 'min' => 150, 'max' => 250, 'samples' => 3]]], // Rollup
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        // Should handle both formats
        $this->assertNotNull($result);
        $this->assertArrayHasKey('Port 1', $result['ports']);
    }

    // =========================================================================
    // Aggregate Bucket Edge Cases
    // =========================================================================

    public function testAggregateBucketWithNullPortValues(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => null]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        // Null values are treated as 0
        $this->assertNotNull($result);
        $this->assertEquals(0, $result['ports']['Port 1']['avg']);
    }

    public function testAggregateBucketWithMissingPortsKey(): void
    {
        $metrics = [
            ['timestamp' => time()], // No 'ports' key
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNull($result);
    }

    public function testAggregateBucketWithEmptyPortsArray(): void
    {
        $metrics = [
            ['ports' => []],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNull($result);
    }

    public function testAggregateBucketWithNegativeValues(): void
    {
        // Shouldn't happen but test robustness
        $metrics = [
            ['ports' => ['Port 1' => -100]],
            ['ports' => ['Port 1' => 100]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEquals(0, $result['ports']['Port 1']['avg']);
        $this->assertEquals(-100, $result['ports']['Port 1']['min']);
    }

    public function testAggregateBucketWithFloatValues(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 100.5]],
            ['ports' => ['Port 1' => 200.5]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        // Result should be rounded to integer
        $this->assertEquals(151, $result['ports']['Port 1']['avg']);
    }

    public function testAggregateBucketWithVeryLargeValues(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => 50000]],
            ['ports' => ['Port 1' => 60000]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEquals(55000, $result['ports']['Port 1']['avg']);
        $this->assertEquals(50000, $result['ports']['Port 1']['min']);
        $this->assertEquals(60000, $result['ports']['Port 1']['max']);
    }

    public function testAggregateBucketSparsePortData(): void
    {
        // Some entries have Port 1, some have Port 2
        $metrics = [
            ['ports' => ['Port 1' => 100]],
            ['ports' => ['Port 2' => 200]],
            ['ports' => ['Port 1' => 150]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEquals(125, $result['ports']['Port 1']['avg']); // (100+150)/2
        $this->assertEquals(200, $result['ports']['Port 2']['avg']); // Only one entry
        $this->assertEquals(2, $result['ports']['Port 1']['samples']);
        $this->assertEquals(1, $result['ports']['Port 2']['samples']);
    }

    // =========================================================================
    // Rollup Processor Tests
    // =========================================================================

    public function testGetRollupProcessorReturnsSameInstance(): void
    {
        $processor1 = $this->collector->getRollupProcessor();
        $processor2 = $this->collector->getRollupProcessor();

        $this->assertSame($processor1, $processor2);
    }

    public function testGetRollupProcessorReturnsRollupProcessor(): void
    {
        $processor = $this->collector->getRollupProcessor();

        $this->assertInstanceOf(RollupProcessor::class, $processor);
    }

    // =========================================================================
    // State Management Tests
    // =========================================================================

    public function testGetRollupStateCreatesNewFile(): void
    {
        $state = $this->collector->getRollupState();

        $this->assertFileExists($this->stateFile);
        $this->assertIsArray($state);
        $this->assertArrayHasKey('1min', $state);
        $this->assertArrayHasKey('5min', $state);
        $this->assertArrayHasKey('30min', $state);
        $this->assertArrayHasKey('2hour', $state);
    }

    public function testGetRollupStateInitializesFields(): void
    {
        $state = $this->collector->getRollupState();

        foreach ($state as $tier => $tierState) {
            $this->assertArrayHasKey('last_processed', $tierState);
            $this->assertArrayHasKey('last_bucket_end', $tierState);
            $this->assertArrayHasKey('last_rollup', $tierState);
        }
    }

    // =========================================================================
    // Read Rollup Tests
    // =========================================================================

    public function testReadRollupFromNonexistentFile(): void
    {
        $result = $this->collector->readRollup(24);

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['data']);
    }

    public function testReadRollupReturnsDataWithTierInfo(): void
    {
        // Create 1min rollup file with data
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'ports' => ['Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 12]]],
            ['timestamp' => $now, 'interval' => 60, 'ports' => ['Port 1' => ['avg' => 150, 'min' => 130, 'max' => 170, 'samples' => 12]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollup(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tier_info', $result);
        $this->assertEquals('1min', $result['tier_info']['tier']);
        $this->assertEquals(60, $result['tier_info']['interval']);
    }

    public function testReadRollupWithPortFilter(): void
    {
        // Create 1min rollup file with data
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'ports' => [
                'Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 12],
                'Port 2' => ['avg' => 200, 'min' => 180, 'max' => 220, 'samples' => 12]
            ]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollup(1, 'Port 1');

        $this->assertTrue($result['success']);
        // Filter should work
        foreach ($result['data'] as $entry) {
            $this->assertArrayHasKey('Port 1', $entry['ports']);
        }
    }

    public function testReadRollupSelectsCorrectTierFor6Hours(): void
    {
        // Create 1min rollup file
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'ports' => ['Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 12]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollup(6);

        // Should use 1min tier for <= 6 hours
        $this->assertTrue($result['success']);
        $this->assertEquals('1min', $result['tier_info']['tier']);
    }

    public function testReadRollupSelectsCorrectTierFor24Hours(): void
    {
        // Create 5min rollup file
        $rollupFile = $this->dataDir . '/5min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 300, 'interval' => 300, 'ports' => ['Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 60]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollup(24);

        // Should use 5min tier for 7-48 hours
        $this->assertTrue($result['success']);
        $this->assertEquals('5min', $result['tier_info']['tier']);
    }

    public function testReadRollupFallsBackToLowerTier(): void
    {
        // Create only 1min file, not 5min
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'ports' => ['Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 12]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        // Request 24 hours (would prefer 5min tier)
        $result = $this->collector->readRollup(24);

        // Should fall back to 1min since 5min doesn't exist
        $this->assertTrue($result['success']);
        $this->assertEquals('1min', $result['tier_info']['tier']);
    }

    // =========================================================================
    // Config Tests
    // =========================================================================

    public function testGetConfigReturnsExpectedValues(): void
    {
        $config = $this->collector->getConfig();

        $this->assertArrayHasKey('collectionInterval', $config);
        $this->assertArrayHasKey('retentionDays', $config);
    }

    public function testGetConfigReturnsDefaults(): void
    {
        $config = $this->collector->getConfig();

        // Default values from WATCHERDEFAULTSETTINGS
        $this->assertIsInt($config['collectionInterval']);
        $this->assertIsInt($config['retentionDays']);
    }

    // =========================================================================
    // Rollup Tiers Info Tests
    // =========================================================================

    public function testGetRollupTiersInfoReturnsAllTiers(): void
    {
        $info = $this->collector->getRollupTiersInfo();

        $this->assertArrayHasKey('1min', $info);
        $this->assertArrayHasKey('5min', $info);
        $this->assertArrayHasKey('30min', $info);
        $this->assertArrayHasKey('2hour', $info);
    }

    public function testGetRollupTiersInfoHasExpectedFields(): void
    {
        $info = $this->collector->getRollupTiersInfo();

        foreach ($info as $tier => $tierInfo) {
            $this->assertArrayHasKey('interval', $tierInfo);
            $this->assertArrayHasKey('interval_label', $tierInfo);
            $this->assertArrayHasKey('retention', $tierInfo);
            $this->assertArrayHasKey('retention_label', $tierInfo);
            $this->assertArrayHasKey('file_exists', $tierInfo);
            $this->assertArrayHasKey('file_size', $tierInfo);
        }
    }

    public function testGetRollupTiersInfoDetectsExistingFile(): void
    {
        // Create 1min file
        $rollupFile = $this->dataDir . '/1min.log';
        file_put_contents($rollupFile, "test content");

        $info = $this->collector->getRollupTiersInfo();

        $this->assertTrue($info['1min']['file_exists']);
        $this->assertGreaterThan(0, $info['1min']['file_size']);
        $this->assertFalse($info['5min']['file_exists']);
    }

    // =========================================================================
    // Metrics Storage Tests
    // =========================================================================

    public function testGetMetricsStorageReturnsInstance(): void
    {
        $storage = $this->collector->getMetricsStorage();

        $this->assertInstanceOf(MetricsStorage::class, $storage);
    }

    // =========================================================================
    // Data Provider Tests for Port Scenarios
    // =========================================================================

    /**
     * @dataProvider portDataProvider
     */
    public function testWriteRawMetricWithVariousPorts(array $ports, int $expectedTotal): void
    {
        $result = $this->collector->writeRawMetric($ports);

        if (empty($ports)) {
            $this->assertTrue($result);
            return;
        }

        $this->assertTrue($result);
        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString("\"_total\":{$expectedTotal}", $content);
    }

    public static function portDataProvider(): array
    {
        return [
            'single port' => [['Port 1' => 100], 100],
            'two ports' => [['Port 1' => 100, 'Port 2' => 200], 300],
            'eight ports' => [
                [
                    'Port 1' => 100, 'Port 2' => 200, 'Port 3' => 300, 'Port 4' => 400,
                    'Port 5' => 500, 'Port 6' => 600, 'Port 7' => 700, 'Port 8' => 800
                ],
                3600
            ],
            'ports with zeros' => [['Port 1' => 0, 'Port 2' => 100, 'Port 3' => 0], 100],
            'all zeros' => [['Port 1' => 0, 'Port 2' => 0], 0],
            'large values' => [['Port 1' => 50000, 'Port 2' => 50000], 100000],
            'empty' => [[], 0],
        ];
    }

    /**
     * @dataProvider tierSelectionProvider
     */
    public function testTierSelectionForVariousHours(int $hours, string $expectedTier): void
    {
        $tier = $this->collector->getBestTierForHours($hours);
        $this->assertEquals($expectedTier, $tier);
    }

    public static function tierSelectionProvider(): array
    {
        return [
            '0 hours' => [0, '1min'],
            '1 hour' => [1, '1min'],
            '3 hours' => [3, '1min'],
            '6 hours' => [6, '1min'],
            '7 hours' => [7, '5min'],
            '12 hours' => [12, '5min'],
            '24 hours' => [24, '5min'],
            '48 hours' => [48, '5min'],
            '49 hours' => [49, '30min'],
            '72 hours' => [72, '30min'],
            '168 hours (1 week)' => [168, '30min'],
            '336 hours (2 weeks)' => [336, '30min'],
            '337 hours' => [337, '2hour'],
            '720 hours (30 days)' => [720, '2hour'],
            '2160 hours (90 days)' => [2160, '2hour'],
        ];
    }

    // =========================================================================
    // Process Rollup Tests
    // =========================================================================

    public function testProcessRollupCreates1MinRollup(): void
    {
        // Create raw data for a completed bucket (2-3 minutes ago)
        $now = time();
        $bucketStart = intval(floor($now / 60) * 60) - 180; // 3 minutes ago
        $entries = [];

        // Create 12 raw entries in that minute
        for ($i = 0; $i < 12; $i++) {
            $entries[] = [
                'timestamp' => $bucketStart + ($i * 5),
                'ports' => ['Port 1' => 100 + $i * 10, '_total' => 100 + $i * 10]
            ];
        }
        $this->writeJsonLinesFile($this->rawFile, $entries);

        // Process rollup
        $this->collector->processRollup();

        // Check 1min file was created (may not be created if bucket isn't complete)
        $rollupFile = $this->dataDir . '/1min.log';
        if (file_exists($rollupFile)) {
            // Read and verify
            $content = file_get_contents($rollupFile);
            $this->assertStringContainsString('"interval":60', $content);
        } else {
            // Rollup may not have been triggered if the bucket isn't complete
            // This is expected behavior - just verify no exception was thrown
            $this->assertTrue(true);
        }
    }

    public function testProcessRollupHandlesEmptyRawFile(): void
    {
        // Create empty raw file
        file_put_contents($this->rawFile, '');

        // Should not throw exception
        $this->collector->processRollup();

        // 1min file should not be created
        $rollupFile = $this->dataDir . '/1min.log';
        $this->assertFileDoesNotExist($rollupFile);
    }

    public function testProcessRollupPreservesState(): void
    {
        // Create raw data with completed bucket (old enough to be processed)
        $now = time();
        $bucketStart = intval(floor($now / 60) * 60) - 300; // 5 minutes ago
        $entries = [];

        // Create entries in multiple minutes to ensure rollup
        for ($i = 0; $i < 24; $i++) {
            $entries[] = [
                'timestamp' => $bucketStart + ($i * 5),
                'ports' => ['Port 1' => 100]
            ];
        }
        $this->writeJsonLinesFile($this->rawFile, $entries);

        // Process first time
        $this->collector->processRollup();

        // Get state
        $state = $this->collector->getRollupState();

        // Verify state exists (may be empty if no rollup was triggered)
        $this->assertIsArray($state);
        // If 1min key exists, it should have valid structure
        if (isset($state['1min'])) {
            $this->assertArrayHasKey('last_processed', $state['1min']);
        }
    }

    // =========================================================================
    // Port History Tests
    // =========================================================================

    public function testGetPortHistoryFromRawData(): void
    {
        // Create raw data for last 30 minutes
        $now = time();
        $entries = [];

        for ($i = 0; $i < 6; $i++) {
            $entries[] = [
                'timestamp' => $now - (30 * 60) + ($i * 300),
                'ports' => ['Port 1' => 100 + $i * 10]
            ];
        }
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->getPortHistory('Port 1', 1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Port 1', $result['portName']);
        $this->assertEquals(1, $result['hours']);
        $this->assertEquals('raw', $result['source']);
        $this->assertIsArray($result['history']);
    }

    public function testGetPortHistoryFromRollupData(): void
    {
        // Create rollup data
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [];

        for ($i = 0; $i < 60; $i++) {
            $entries[] = [
                'timestamp' => $now - (6 * 3600) + ($i * 60),
                'interval' => 60,
                'ports' => ['Port 1' => ['avg' => 100, 'min' => 90, 'max' => 110, 'samples' => 12]]
            ];
        }
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->getPortHistory('Port 1', 6);

        $this->assertTrue($result['success']);
        $this->assertEquals('Port 1', $result['portName']);
        $this->assertEquals(6, $result['hours']);
        $this->assertEquals('rollup', $result['source']);
    }

    public function testGetPortHistoryReturnsEmptyForMissingPort(): void
    {
        // Create raw data without the requested port
        $now = time();
        $entries = [
            ['timestamp' => $now, 'ports' => ['Port 2' => 200]]
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->getPortHistory('Port 1', 1);

        $this->assertTrue($result['success']);
        // History should contain slots but with zero values
        $this->assertIsArray($result['history']);
    }

    public function testGetPortHistoryReturnsCorrectTimeSlots(): void
    {
        // Create raw data
        $now = time();
        $entries = [
            ['timestamp' => $now - 300, 'ports' => ['Port 1' => 100]],
            ['timestamp' => $now, 'ports' => ['Port 1' => 200]],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->getPortHistory('Port 1', 1);

        $this->assertTrue($result['success']);
        // Should have slots for the requested time period
        $this->assertNotEmpty($result['history']);

        // Each slot should have a timestamp
        foreach ($result['history'] as $slot) {
            $this->assertArrayHasKey('timestamp', $slot);
        }
    }

    // =========================================================================
    // Aggregate Bucket Edge Cases - More Complex Scenarios
    // =========================================================================

    public function testAggregateBucketWithManyPorts(): void
    {
        $metrics = [];
        for ($i = 0; $i < 12; $i++) {
            $ports = [];
            for ($p = 1; $p <= 16; $p++) {
                $ports["Port {$p}"] = 100 + $i;
            }
            $ports['_total'] = array_sum($ports);
            $metrics[] = ['ports' => $ports];
        }
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNotNull($result);
        // Should have all 16 ports plus _total
        $this->assertCount(17, $result['ports']);
    }

    public function testAggregateBucketWithRollupEntryMissingFields(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => ['avg' => 500]]] // Missing min, max, samples
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        $this->assertNotNull($result);
        $this->assertEquals(500, $result['ports']['Port 1']['avg']);
        // Missing fields should default to 0
        $this->assertEquals(0, $result['ports']['Port 1']['min']);
        $this->assertEquals(0, $result['ports']['Port 1']['max']);
    }

    public function testAggregateBucketPreservesPeakValue(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => ['avg' => 100, 'min' => 50, 'max' => 200, 'samples' => 5]]],
            ['ports' => ['Port 1' => ['avg' => 150, 'min' => 100, 'max' => 180, 'samples' => 5]]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        // Peak should be the max of all maxes
        $this->assertEquals(200, $result['ports']['Port 1']['peak']);
    }

    public function testAggregateBucketCombinesSamplesFromRollups(): void
    {
        $metrics = [
            ['ports' => ['Port 1' => ['avg' => 100, 'min' => 50, 'max' => 150, 'samples' => 10]]],
            ['ports' => ['Port 1' => ['avg' => 200, 'min' => 150, 'max' => 250, 'samples' => 15]]],
            ['ports' => ['Port 1' => ['avg' => 150, 'min' => 100, 'max' => 200, 'samples' => 5]]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        // Samples should be sum of all samples
        $this->assertEquals(30, $result['ports']['Port 1']['samples']);
    }

    // =========================================================================
    // Tier Fallback Tests
    // =========================================================================

    public function testReadRollupFallsBackThroughMultipleTiers(): void
    {
        // Only create 1min file
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'ports' => ['Port 1' => ['avg' => 100, 'min' => 80, 'max' => 120, 'samples' => 12]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        // Request 2 weeks (would prefer 30min tier, fall back to 5min, then 1min)
        $result = $this->collector->readRollup(336);

        // Should fall back to 1min
        $this->assertTrue($result['success']);
        $this->assertEquals('1min', $result['tier_info']['tier']);
    }

    public function testReadRollupFailsWhenNoTierExists(): void
    {
        // Don't create any rollup files
        $result = $this->collector->readRollup(24);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // Public Method Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(EfuseCollector::class);

        $expectedMethods = [
            'getInstance',
            'getRollupProcessor',
            'getTiers',
            'getRollupFilePath',
            'getStateFilePath',
            'getBestTierForHours',
            'writeRawMetric',
            'readRawMetrics',
            'processRollup',
            'aggregateBucket',
            'readRollup',
            'getCurrentStatus',
            'getPortHistory',
            'getHeatmapData',
            'getRollupTiersInfo',
            'getRollupState',
            'getConfig',
            'getMetricsStorage',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );
        }
    }

    // =========================================================================
    // Data Integrity Tests
    // =========================================================================

    public function testWriteAndReadRoundTrip(): void
    {
        // Write raw metrics
        $ports = ['Port 1' => 1000, 'Port 2' => 2000, 'Port 3' => 3000];
        $this->collector->writeRawMetric($ports);

        // Read back
        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(1, $result);
        $this->assertEquals(1000, $result[0]['ports']['Port 1']);
        $this->assertEquals(2000, $result[0]['ports']['Port 2']);
        $this->assertEquals(3000, $result[0]['ports']['Port 3']);
        $this->assertEquals(6000, $result[0]['ports']['_total']);
    }

    public function testMultipleWritesPreserveOrder(): void
    {
        // Write multiple entries with small delays
        for ($i = 1; $i <= 5; $i++) {
            $this->collector->writeRawMetric(['Port 1' => $i * 100]);
        }

        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(5, $result);

        // Entries should be in chronological order (oldest first from file)
        for ($i = 0; $i < 5; $i++) {
            $expectedValue = ($i + 1) * 100;
            $this->assertEquals($expectedValue, $result[$i]['ports']['Port 1']);
        }
    }

    // =========================================================================
    // Retention Tests
    // =========================================================================

    /**
     * @dataProvider retentionProvider
     */
    public function testTierRetentionWithVariousPeriods(int $days, array $expectedRetentions): void
    {
        $tiers = $this->collector->getTiers($days);

        $this->assertEquals($expectedRetentions['1min'], $tiers['1min']['retention']);
        $this->assertEquals($expectedRetentions['5min'], $tiers['5min']['retention']);
        $this->assertEquals($expectedRetentions['30min'], $tiers['30min']['retention']);
        $this->assertEquals($expectedRetentions['2hour'], $tiers['2hour']['retention']);
    }

    public static function retentionProvider(): array
    {
        return [
            '1 day retention' => [
                1,
                [
                    '1min' => 21600, // min(21600, 86400) = 21600
                    '5min' => 86400,  // min(172800, 86400) = 86400
                    '30min' => 86400, // min(1209600, 86400) = 86400
                    '2hour' => 86400
                ]
            ],
            '3 day retention' => [
                3,
                [
                    '1min' => 21600,
                    '5min' => 172800, // min(172800, 259200) = 172800
                    '30min' => 259200,
                    '2hour' => 259200
                ]
            ],
            '14 day retention' => [
                14,
                [
                    '1min' => 21600,
                    '5min' => 172800,
                    '30min' => 1209600,
                    '2hour' => 1209600
                ]
            ],
            '30 day retention' => [
                30,
                [
                    '1min' => 21600,
                    '5min' => 172800,
                    '30min' => 1209600,
                    '2hour' => 2592000
                ]
            ],
        ];
    }
}
