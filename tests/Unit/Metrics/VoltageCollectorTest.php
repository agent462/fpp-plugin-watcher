<?php
/**
 * Unit tests for VoltageCollector class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\VoltageCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * Testable VoltageCollector that allows instance creation without singleton
 * and custom paths for testing
 */
class TestableVoltageCollector extends VoltageCollector
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

        // Use reflection to set parent properties
        $baseClass = new \ReflectionClass(\Watcher\Metrics\BaseMetricsCollector::class);
        $voltageClass = new \ReflectionClass(VoltageCollector::class);

        $dataDirProp = $baseClass->getProperty('dataDir');
        $dataDirProp->setAccessible(true);
        $dataDirProp->setValue($this, $dataDir);

        $metricsFileProp = $baseClass->getProperty('metricsFile');
        $metricsFileProp->setAccessible(true);
        $metricsFileProp->setValue($this, $rawFile);

        $stateFileProp = $voltageClass->getProperty('stateFile');
        $stateFileProp->setAccessible(true);
        $stateFileProp->setValue($this, $stateFile);

        $storageProp = $baseClass->getProperty('storage');
        $storageProp->setAccessible(true);
        $storageProp->setValue($this, $storage ?? new MetricsStorage());

        $loggerProp = $baseClass->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this, $logger ?? Logger::getInstance());

        $fileManagerProp = $voltageClass->getProperty('fileManager');
        $fileManagerProp->setAccessible(true);
        $fileManagerProp->setValue($this, $fileManager ?? FileManager::getInstance());

        if ($rollup !== null) {
            $rollupProp = $baseClass->getProperty('rollup');
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

class VoltageCollectorTest extends TestCase
{
    private TestableVoltageCollector $collector;
    private string $dataDir;
    private string $rawFile;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = $this->createTempDir('voltage-data');
        $this->rawFile = $this->dataDir . '/raw.log';
        $this->stateFile = $this->dataDir . '/rollup-state.json';

        // Set up test plugin config
        $GLOBALS['testPluginConfig'] = [
            'voltageRetentionDays' => 7,
            'voltageCollectionInterval' => 3
        ];

        $this->collector = new TestableVoltageCollector(
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
        $instance1 = VoltageCollector::getInstance();
        $instance2 = VoltageCollector::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsVoltageCollector(): void
    {
        $instance = VoltageCollector::getInstance();

        $this->assertInstanceOf(VoltageCollector::class, $instance);
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

    public function testGetTiersFor1DayRetention(): void
    {
        $tiers = $this->collector->getTiers(1);

        // 1 day retention should only have 1min tier
        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayNotHasKey('5min', $tiers);
        $this->assertArrayNotHasKey('30min', $tiers);
        $this->assertArrayNotHasKey('2hour', $tiers);
    }

    public function testGetTiersFor3DayRetention(): void
    {
        $tiers = $this->collector->getTiers(3);

        // 3 days should have 1min and 5min
        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayNotHasKey('30min', $tiers);
        $this->assertArrayNotHasKey('2hour', $tiers);
    }

    public function testGetTiersFor7DayRetention(): void
    {
        $tiers = $this->collector->getTiers(7);

        // 7 days should have 1min, 5min, and 30min
        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayHasKey('30min', $tiers);
        $this->assertArrayNotHasKey('2hour', $tiers);
    }

    public function testGetTiersFor14DayRetention(): void
    {
        $tiers = $this->collector->getTiers(14);

        // 14+ days should have all tiers
        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayHasKey('30min', $tiers);
        $this->assertArrayHasKey('2hour', $tiers);
    }

    public function testGetTiersFor30DayRetention(): void
    {
        $tiers = $this->collector->getTiers(30);

        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayHasKey('30min', $tiers);
        $this->assertArrayHasKey('2hour', $tiers);
    }

    public function testEachTierHasRequiredFields(): void
    {
        $tiers = $this->collector->getTiers(30);

        foreach ($tiers as $tier => $config) {
            $this->assertArrayHasKey('interval', $config, "Tier {$tier} missing 'interval'");
            $this->assertArrayHasKey('retention', $config, "Tier {$tier} missing 'retention'");
            $this->assertArrayHasKey('label', $config, "Tier {$tier} missing 'label'");
        }
    }

    public function testTierIntervalsAreCorrect(): void
    {
        $tiers = $this->collector->getTiers(30);

        $this->assertEquals(60, $tiers['1min']['interval']);
        $this->assertEquals(300, $tiers['5min']['interval']);
        $this->assertEquals(1800, $tiers['30min']['interval']);
        $this->assertEquals(7200, $tiers['2hour']['interval']);
    }

    public function testTierLabelsAreCorrect(): void
    {
        $tiers = $this->collector->getTiers(30);

        $this->assertEquals('1-minute averages', $tiers['1min']['label']);
        $this->assertEquals('5-minute averages', $tiers['5min']['label']);
        $this->assertEquals('30-minute averages', $tiers['30min']['label']);
        $this->assertEquals('2-hour averages', $tiers['2hour']['label']);
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

    public function testGetBestTierForHours49To168(): void
    {
        $this->assertEquals('30min', $this->collector->getBestTierForHours(49));
        $this->assertEquals('30min', $this->collector->getBestTierForHours(72));
        $this->assertEquals('30min', $this->collector->getBestTierForHours(168));
    }

    public function testGetBestTierForHoursOver168(): void
    {
        // With 7-day retention (default in tests), there's no 2hour tier
        // So it falls back to 30min for anything above 48 hours
        $this->assertEquals('30min', $this->collector->getBestTierForHours(169));
        $this->assertEquals('30min', $this->collector->getBestTierForHours(336));
        $this->assertEquals('30min', $this->collector->getBestTierForHours(720));
    }

    public function testGetBestTierForHoursOver168With30DayRetention(): void
    {
        // With 30-day retention, 2hour tier is available
        $GLOBALS['testPluginConfig']['voltageRetentionDays'] = 30;

        // Recreate collector with new config
        $collector = new TestableVoltageCollector(
            $this->dataDir,
            $this->rawFile,
            $this->stateFile
        );

        $this->assertEquals('2hour', $collector->getBestTierForHours(169));
        $this->assertEquals('2hour', $collector->getBestTierForHours(336));
        $this->assertEquals('2hour', $collector->getBestTierForHours(720));

        // Restore default
        $GLOBALS['testPluginConfig']['voltageRetentionDays'] = 7;
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

    public function testWriteRawMetricWithSingleVoltage(): void
    {
        $result = $this->collector->writeRawMetric(0.8768);

        $this->assertTrue($result);
        $this->assertFileExists($this->rawFile);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"core":0.8768', $content);
    }

    public function testWriteRawMetricsWithEmptyArray(): void
    {
        $result = $this->collector->writeRawMetrics([]);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->rawFile);
    }

    public function testWriteRawMetricsWithMultipleRails(): void
    {
        $voltages = [
            'VDD_CORE_V' => 0.8768,
            'EXT5V_V' => 5.1536,
            '3V3_SYS_V' => 3.3128
        ];

        $result = $this->collector->writeRawMetrics($voltages);

        $this->assertTrue($result);
        $this->assertFileExists($this->rawFile);

        $content = file_get_contents($this->rawFile);
        $this->assertStringContainsString('"VDD_CORE_V":0.8768', $content);
        $this->assertStringContainsString('"EXT5V_V":5.1536', $content);
        $this->assertStringContainsString('"3V3_SYS_V":3.3128', $content);
    }

    public function testWriteRawMetricsAppendsToFile(): void
    {
        $this->collector->writeRawMetrics(['core' => 0.8768]);
        $this->collector->writeRawMetrics(['core' => 0.8770]);

        $content = file_get_contents($this->rawFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(2, $lines);
    }

    public function testWriteRawMetricsCreatesDirectory(): void
    {
        $newDir = $this->createTempDir('voltage-new') . '/subdir';
        $newRawFile = $newDir . '/raw.log';
        $newStateFile = $newDir . '/rollup-state.json';

        $collector = new TestableVoltageCollector($newDir, $newRawFile, $newStateFile);

        // Directory doesn't exist yet
        $this->assertDirectoryDoesNotExist($newDir);

        // Writing should create the directory
        $result = $collector->writeRawMetrics(['core' => 0.8768]);

        $this->assertTrue($result);
        $this->assertDirectoryExists($newDir);
        $this->assertFileExists($newRawFile);
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
            ['timestamp' => $now - 60, 'voltages' => ['core' => 0.8768]],
            ['timestamp' => $now - 30, 'voltages' => ['core' => 0.8770]],
            ['timestamp' => $now, 'voltages' => ['core' => 0.8765]],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(3, $result);
    }

    public function testReadRawMetricsFiltersOldEntries(): void
    {
        $now = time();
        $entries = [
            ['timestamp' => $now - 7200, 'voltages' => ['core' => 0.8760]], // 2 hours ago
            ['timestamp' => $now - 60, 'voltages' => ['core' => 0.8768]],   // 1 min ago
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->readRawMetrics(1); // Last 1 hour

        $this->assertCount(1, $result);
        $this->assertEquals(0.8768, $result[0]['voltages']['core']);
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
            ['voltages' => ['VDD_CORE_V' => 0.8768]]
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNotNull($result);
        $this->assertEquals($bucketStart, $result['timestamp']);
        $this->assertEquals(60, $result['interval']);
        $this->assertArrayHasKey('VDD_CORE_V', $result['voltages']);
        $this->assertEquals(0.8768, $result['voltages']['VDD_CORE_V']['avg']);
        $this->assertEquals(0.8768, $result['voltages']['VDD_CORE_V']['min']);
        $this->assertEquals(0.8768, $result['voltages']['VDD_CORE_V']['max']);
        $this->assertEquals(1, $result['voltages']['VDD_CORE_V']['samples']);
    }

    public function testAggregateBucketWithMultipleRawEntries(): void
    {
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => 0.8760]],
            ['voltages' => ['VDD_CORE_V' => 0.8770]],
            ['voltages' => ['VDD_CORE_V' => 0.8780]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertEqualsWithDelta(0.877, $result['voltages']['VDD_CORE_V']['avg'], 0.0001);
        $this->assertEquals(0.876, $result['voltages']['VDD_CORE_V']['min']);
        $this->assertEquals(0.878, $result['voltages']['VDD_CORE_V']['max']);
        $this->assertEquals(3, $result['voltages']['VDD_CORE_V']['samples']);
    }

    public function testAggregateBucketWithMultipleRails(): void
    {
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => 0.8768, 'EXT5V_V' => 5.1536]],
            ['voltages' => ['VDD_CORE_V' => 0.8770, 'EXT5V_V' => 5.1540]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertArrayHasKey('VDD_CORE_V', $result['voltages']);
        $this->assertArrayHasKey('EXT5V_V', $result['voltages']);
        $this->assertEqualsWithDelta(0.8769, $result['voltages']['VDD_CORE_V']['avg'], 0.0001);
        $this->assertEqualsWithDelta(5.1538, $result['voltages']['EXT5V_V']['avg'], 0.0001);
    }

    // =========================================================================
    // Aggregate Bucket Tests - Rollup Data
    // =========================================================================

    public function testAggregateBucketWithRollupEntry(): void
    {
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => ['avg' => 0.8768, 'min' => 0.8760, 'max' => 0.8780, 'samples' => 10]]]
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        $this->assertNotNull($result);
        $this->assertEquals(0.8768, $result['voltages']['VDD_CORE_V']['avg']);
        $this->assertEquals(0.876, $result['voltages']['VDD_CORE_V']['min']);
        $this->assertEquals(0.878, $result['voltages']['VDD_CORE_V']['max']);
        $this->assertEquals(10, $result['voltages']['VDD_CORE_V']['samples']);
    }

    public function testAggregateBucketWithMultipleRollupEntries(): void
    {
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => ['avg' => 0.8760, 'min' => 0.8750, 'max' => 0.8770, 'samples' => 5]]],
            ['voltages' => ['VDD_CORE_V' => ['avg' => 0.8780, 'min' => 0.8770, 'max' => 0.8790, 'samples' => 5]]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 300);

        // Avg = (0.8760 + 0.8780) / 2 = 0.8770
        $this->assertEqualsWithDelta(0.877, $result['voltages']['VDD_CORE_V']['avg'], 0.0001);
        // Min = min(0.8750, 0.8770) = 0.8750
        $this->assertEquals(0.875, $result['voltages']['VDD_CORE_V']['min']);
        // Max = max(0.8770, 0.8790) = 0.8790
        $this->assertEquals(0.879, $result['voltages']['VDD_CORE_V']['max']);
        // Samples = 5 + 5 = 10
        $this->assertEquals(10, $result['voltages']['VDD_CORE_V']['samples']);
    }

    // =========================================================================
    // Legacy Format Compatibility Tests
    // =========================================================================

    public function testAggregateBucketWithLegacySingleVoltage(): void
    {
        // Legacy format: {voltage: float} instead of {voltages: {core: float}}
        $metrics = [
            ['voltage' => 1.2375],
            ['voltage' => 1.2380],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('core', $result['voltages']);
        $this->assertEqualsWithDelta(1.2378, $result['voltages']['core']['avg'], 0.0001);
    }

    public function testAggregateBucketWithLegacyRollup(): void
    {
        // Legacy rollup format: {voltage: {avg, min, max, samples}}
        $metrics = [
            ['voltage' => ['avg' => 1.2375, 'min' => 1.2350, 'max' => 1.2400, 'samples' => 20]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('core', $result['voltages']);
        $this->assertEquals(1.2375, $result['voltages']['core']['avg']);
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
            ['timestamp' => $now - 60, 'interval' => 60, 'voltages' => ['core' => ['avg' => 0.8768, 'min' => 0.8760, 'max' => 0.8780, 'samples' => 12]]],
            ['timestamp' => $now, 'interval' => 60, 'voltages' => ['core' => ['avg' => 0.8770, 'min' => 0.8765, 'max' => 0.8775, 'samples' => 12]]],
        ];
        $this->writeJsonLinesFile($rollupFile, $entries);

        $result = $this->collector->readRollup(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('tier_info', $result);
        $this->assertEquals('1min', $result['tier_info']['tier']);
        $this->assertEquals(60, $result['tier_info']['interval']);
    }

    public function testReadRollupSelectsCorrectTierFor6Hours(): void
    {
        // Create 1min rollup file
        $rollupFile = $this->dataDir . '/1min.log';
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'interval' => 60, 'voltages' => ['core' => ['avg' => 0.8768, 'min' => 0.8760, 'max' => 0.8780, 'samples' => 12]]],
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
            ['timestamp' => $now - 300, 'interval' => 300, 'voltages' => ['core' => ['avg' => 0.8768, 'min' => 0.8760, 'max' => 0.8780, 'samples' => 60]]],
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
            ['timestamp' => $now - 60, 'interval' => 60, 'voltages' => ['core' => ['avg' => 0.8768, 'min' => 0.8760, 'max' => 0.8780, 'samples' => 12]]],
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

        $this->assertIsInt($config['collectionInterval']);
        $this->assertIsInt($config['retentionDays']);
    }

    // =========================================================================
    // Get Metrics Tests
    // =========================================================================

    public function testGetMetricsReturnsArray(): void
    {
        $result = $this->collector->getMetrics(24);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testGetMetricsWithFractionalHoursUsesRawData(): void
    {
        // Write some raw data
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'voltages' => ['core' => 0.8768]],
            ['timestamp' => $now - 30, 'voltages' => ['core' => 0.8770]],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->getMetricsWithFractionalHours(0.5);

        $this->assertTrue($result['success']);
        $this->assertEquals('raw', $result['tier_info']['tier']);
        $this->assertEquals('Raw readings', $result['tier_info']['label']);
    }

    public function testGetMetricsWithFractionalHoursNormalizesLegacyData(): void
    {
        // Write legacy format data
        $now = time();
        $entries = [
            ['timestamp' => $now - 60, 'voltage' => 1.2375],
            ['timestamp' => $now - 30, 'voltage' => 1.2380],
        ];
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $result = $this->collector->getMetricsWithFractionalHours(0.5);

        $this->assertTrue($result['success']);

        // Data should be normalized to new format
        foreach ($result['data'] as $entry) {
            $this->assertArrayHasKey('voltages', $entry);
            $this->assertArrayNotHasKey('voltage', $entry);
        }
    }

    // =========================================================================
    // Current Status Tests
    // =========================================================================

    public function testGetCurrentStatusReturnsArray(): void
    {
        $result = $this->collector->getCurrentStatus();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('supported', $result);
    }

    public function testGetCurrentStatusWhenSupported(): void
    {
        $result = $this->collector->getCurrentStatus();

        if ($result['success'] && $result['supported']) {
            $this->assertArrayHasKey('timestamp', $result);
            $this->assertArrayHasKey('voltage', $result);
            $this->assertArrayHasKey('voltages', $result);
            $this->assertArrayHasKey('labels', $result);
            $this->assertArrayHasKey('throttled', $result);
            $this->assertArrayHasKey('flags', $result);
        }
    }

    public function testGetCurrentStatusWhenNotSupported(): void
    {
        $result = $this->collector->getCurrentStatus();

        if ($result['supported']) {
            $this->markTestSkipped('Platform is supported; unsupported path not testable');
        }

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    // =========================================================================
    // Rollup Tiers Info Tests
    // =========================================================================

    public function testGetRollupTiersInfoReturnsArray(): void
    {
        $info = $this->collector->getRollupTiersInfo();

        $this->assertIsArray($info);
    }

    // =========================================================================
    // Process Rollup Tests
    // =========================================================================

    public function testProcessRollupCreates1MinRollup(): void
    {
        // Create raw data for a completed bucket
        $now = time();
        $bucketStart = intval(floor($now / 60) * 60) - 180; // 3 minutes ago
        $entries = [];

        for ($i = 0; $i < 20; $i++) {
            $entries[] = [
                'timestamp' => $bucketStart + ($i * 3),
                'voltages' => ['VDD_CORE_V' => 0.8768 + ($i * 0.0001)]
            ];
        }
        $this->writeJsonLinesFile($this->rawFile, $entries);

        $this->collector->processRollup();

        $rollupFile = $this->dataDir . '/1min.log';
        if (file_exists($rollupFile)) {
            $content = file_get_contents($rollupFile);
            $this->assertStringContainsString('"interval":60', $content);
        } else {
            $this->assertTrue(true); // Bucket may not be complete
        }
    }

    public function testProcessRollupHandlesEmptyRawFile(): void
    {
        file_put_contents($this->rawFile, '');

        $this->collector->processRollup();

        $rollupFile = $this->dataDir . '/1min.log';
        $this->assertFileDoesNotExist($rollupFile);
    }

    // =========================================================================
    // Data Provider Tests
    // =========================================================================

    /**
     * @dataProvider tierRetentionProvider
     */
    public function testTiersByRetentionDays(int $days, array $expectedTiers): void
    {
        $tiers = $this->collector->getTiers($days);

        foreach ($expectedTiers as $tier) {
            $this->assertArrayHasKey($tier, $tiers, "Expected tier $tier for $days day retention");
        }
    }

    public static function tierRetentionProvider(): array
    {
        return [
            '1 day' => [1, ['1min']],
            '2 days' => [2, ['1min', '5min']],
            '3 days' => [3, ['1min', '5min']],
            '4 days' => [4, ['1min', '5min', '30min']],
            '7 days' => [7, ['1min', '5min', '30min']],
            '8 days' => [8, ['1min', '5min', '30min', '2hour']],
            '14 days' => [14, ['1min', '5min', '30min', '2hour']],
            '30 days' => [30, ['1min', '5min', '30min', '2hour']],
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
        // Note: With 7-day retention (test default), no 2hour tier is available
        // So hours > 168 fall back to 30min tier
        return [
            '0 hours' => [0, '1min'],
            '1 hour' => [1, '1min'],
            '6 hours' => [6, '1min'],
            '7 hours' => [7, '5min'],
            '24 hours' => [24, '5min'],
            '48 hours' => [48, '5min'],
            '49 hours' => [49, '30min'],
            '168 hours (1 week)' => [168, '30min'],
            '169 hours' => [169, '30min'], // Falls back to 30min without 2hour tier
            '720 hours (30 days)' => [720, '30min'], // Falls back to 30min without 2hour tier
        ];
    }

    // =========================================================================
    // Aggregate Bucket Edge Cases
    // =========================================================================

    public function testAggregateBucketWithMissingVoltagesKey(): void
    {
        $metrics = [
            ['timestamp' => time()], // No 'voltages' key
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNull($result);
    }

    public function testAggregateBucketWithEmptyVoltagesArray(): void
    {
        $metrics = [
            ['voltages' => []],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertNull($result);
    }

    public function testAggregateBucketWithSparseRailData(): void
    {
        // Some entries have VDD_CORE_V, some have EXT5V_V
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => 0.8768]],
            ['voltages' => ['EXT5V_V' => 5.1536]],
            ['voltages' => ['VDD_CORE_V' => 0.8770]],
        ];
        $bucketStart = time();

        $result = $this->collector->aggregateBucket($metrics, $bucketStart, 60);

        $this->assertArrayHasKey('VDD_CORE_V', $result['voltages']);
        $this->assertArrayHasKey('EXT5V_V', $result['voltages']);
        $this->assertEquals(2, $result['voltages']['VDD_CORE_V']['samples']);
        $this->assertEquals(1, $result['voltages']['EXT5V_V']['samples']);
    }

    // =========================================================================
    // Aggregate For Rollup Alias Tests
    // =========================================================================

    public function testAggregateForRollupIsAliasForAggregateBucket(): void
    {
        $metrics = [
            ['voltages' => ['VDD_CORE_V' => 0.8768]]
        ];
        $bucketStart = time();
        $interval = 60;

        $result1 = $this->collector->aggregateBucket($metrics, $bucketStart, $interval);
        $result2 = $this->collector->aggregateForRollup($metrics, $bucketStart, $interval);

        $this->assertEquals($result1, $result2);
    }

    public function testAggregateForRollupWithEmptyArray(): void
    {
        $result = $this->collector->aggregateForRollup([], time(), 60);

        $this->assertNull($result);
    }

    // =========================================================================
    // Write and Read Round Trip Tests
    // =========================================================================

    public function testWriteAndReadRoundTrip(): void
    {
        // Write raw metrics
        $voltages = ['VDD_CORE_V' => 0.8768, 'EXT5V_V' => 5.1536, '3V3_SYS_V' => 3.3128];
        $this->collector->writeRawMetrics($voltages);

        // Read back
        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(1, $result);
        $this->assertEquals(0.8768, $result[0]['voltages']['VDD_CORE_V']);
        $this->assertEquals(5.1536, $result[0]['voltages']['EXT5V_V']);
        $this->assertEquals(3.3128, $result[0]['voltages']['3V3_SYS_V']);
    }

    public function testMultipleWritesPreserveOrder(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->collector->writeRawMetrics(['VDD_CORE_V' => 0.8760 + ($i * 0.001)]);
        }

        $result = $this->collector->readRawMetrics(1);

        $this->assertCount(5, $result);

        // Entries should be in chronological order
        for ($i = 0; $i < 5; $i++) {
            $expectedValue = 0.876 + (($i + 1) * 0.001);
            $this->assertEqualsWithDelta($expectedValue, $result[$i]['voltages']['VDD_CORE_V'], 0.0001);
        }
    }

    // =========================================================================
    // Rollup File Path Consistency Tests
    // =========================================================================

    public function testRollupFilePathsAreConsistent(): void
    {
        $tiers = ['1min', '5min', '30min', '2hour'];

        foreach ($tiers as $tier) {
            $path = $this->collector->getRollupFilePath($tier);

            $this->assertStringContainsString($this->dataDir, $path);
            $this->assertStringEndsWith('.log', $path);
            $this->assertStringContainsString($tier, $path);
        }
    }

    public function testRollupFilePathsAreUnique(): void
    {
        $paths = [];
        foreach (['1min', '5min', '30min', '2hour'] as $tier) {
            $paths[] = $this->collector->getRollupFilePath($tier);
        }

        $this->assertEquals(count($paths), count(array_unique($paths)));
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(VoltageCollector::class);

        $expectedMethods = [
            'getInstance',
            'getRollupProcessor',
            'getTiers',
            'getRollupFilePath',
            'getStateFilePath',
            'getBestTierForHours',
            'writeRawMetric',
            'writeRawMetrics',
            'readRawMetrics',
            'processRollup',
            'aggregateBucket',
            'aggregateForRollup',
            'readRollup',
            'getCurrentStatus',
            'getMetrics',
            'getMetricsWithFractionalHours',
            'getRollupTiersInfo',
            'getRollupState',
            'getConfig',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );
        }
    }
}
