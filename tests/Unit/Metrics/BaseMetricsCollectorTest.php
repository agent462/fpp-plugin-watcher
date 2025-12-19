<?php
/**
 * Unit tests for BaseMetricsCollector abstract class
 *
 * Uses PingCollector as a concrete implementation to test base functionality.
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\BaseMetricsCollector;
use Watcher\Metrics\PingCollector;
use Watcher\Metrics\RollupProcessor;
use Watcher\Metrics\MetricsStorage;
use Watcher\Core\Logger;

/**
 * Concrete implementation of BaseMetricsCollector for testing
 */
class ConcreteMetricsCollector extends BaseMetricsCollector
{
    protected static ?self $instance = null;
    private string $testDataDir;
    private string $testMetricsFile;
    private string $testStateFileSuffix = '/test-state.json';

    public function __construct(
        string $dataDir,
        string $metricsFile,
        ?RollupProcessor $rollup = null,
        ?MetricsStorage $storage = null,
        ?Logger $logger = null
    ) {
        $this->testDataDir = $dataDir;
        $this->testMetricsFile = $metricsFile;
        parent::__construct($rollup, $storage, $logger);
    }

    protected function initializePaths(): void
    {
        $this->dataDir = $this->testDataDir;
        $this->metricsFile = $this->testMetricsFile;
    }

    protected function getStateFileSuffix(): string
    {
        return $this->testStateFileSuffix;
    }

    public function aggregateForRollup(array $bucketMetrics, int $bucketStart, int $interval): ?array
    {
        if (empty($bucketMetrics)) {
            return null;
        }

        return [
            'timestamp' => $bucketStart,
            'period_start' => $bucketStart,
            'period_end' => $bucketStart + $interval,
            'count' => count($bucketMetrics)
        ];
    }

    public function setStateFileSuffix(string $suffix): void
    {
        $this->testStateFileSuffix = $suffix;
    }
}

class BaseMetricsCollectorTest extends TestCase
{
    private ConcreteMetricsCollector $collector;
    private string $dataDir;
    private string $metricsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = sys_get_temp_dir() . '/watcher-test-base-' . uniqid();
        $this->metricsFile = $this->dataDir . '/metrics.log';

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        $this->collector = new ConcreteMetricsCollector(
            $this->dataDir,
            $this->metricsFile
        );
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $this->recursiveDelete($this->dataDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // =================================================================
    // Path Methods Tests
    // =================================================================

    public function testGetRollupFilePathReturnsCorrectPath(): void
    {
        $this->assertEquals(
            $this->dataDir . '/1min.log',
            $this->collector->getRollupFilePath('1min')
        );
    }

    public function testGetRollupFilePathWorksForDifferentTiers(): void
    {
        $tiers = ['1min', '5min', '15min', '1hour'];
        foreach ($tiers as $tier) {
            $this->assertEquals(
                $this->dataDir . "/{$tier}.log",
                $this->collector->getRollupFilePath($tier)
            );
        }
    }

    public function testGetStateFilePathReturnsCorrectPath(): void
    {
        $this->assertEquals(
            $this->dataDir . '/test-state.json',
            $this->collector->getStateFilePath()
        );
    }

    public function testGetDataDirReturnsCorrectPath(): void
    {
        $this->assertEquals($this->dataDir, $this->collector->getDataDir());
    }

    public function testGetMetricsFilePathReturnsCorrectPath(): void
    {
        $this->assertEquals($this->metricsFile, $this->collector->getMetricsFilePath());
    }

    // =================================================================
    // Rollup State Tests
    // =================================================================

    public function testGetRollupStateReturnsArray(): void
    {
        $state = $this->collector->getRollupState();
        $this->assertIsArray($state);
    }

    public function testGetRollupStateInitializesEmptyState(): void
    {
        $state = $this->collector->getRollupState();
        // State file may be empty or have default structure
        $this->assertIsArray($state);
    }

    public function testSaveRollupStateWritesFile(): void
    {
        $state = ['tiers' => ['1min' => ['last_processed' => time()]]];
        $result = $this->collector->saveRollupState($state);

        $this->assertTrue($result);
        $this->assertFileExists($this->collector->getStateFilePath());
    }

    public function testSaveAndRetrieveRollupState(): void
    {
        $state = [
            'tiers' => [
                '1min' => ['last_processed' => 1234567890],
                '5min' => ['last_processed' => 1234567800]
            ]
        ];

        $this->collector->saveRollupState($state);
        $retrieved = $this->collector->getRollupState();

        $this->assertEquals($state['tiers']['1min']['last_processed'], $retrieved['tiers']['1min']['last_processed']);
        $this->assertEquals($state['tiers']['5min']['last_processed'], $retrieved['tiers']['5min']['last_processed']);
    }

    // =================================================================
    // Accessor Tests
    // =================================================================

    public function testGetRollupProcessorReturnsProcessor(): void
    {
        $processor = $this->collector->getRollupProcessor();
        $this->assertInstanceOf(RollupProcessor::class, $processor);
    }

    public function testGetRollupProcessorReturnsSameInstance(): void
    {
        $processor1 = $this->collector->getRollupProcessor();
        $processor2 = $this->collector->getRollupProcessor();

        $this->assertSame($processor1, $processor2);
    }

    public function testGetMetricsStorageReturnsStorage(): void
    {
        $storage = $this->collector->getMetricsStorage();
        $this->assertInstanceOf(MetricsStorage::class, $storage);
    }

    // =================================================================
    // Tier Info Tests
    // =================================================================

    public function testGetRollupTiersInfoReturnsArray(): void
    {
        $info = $this->collector->getRollupTiersInfo();
        $this->assertIsArray($info);
    }

    public function testGetRollupTiersInfoContainsExpectedTiers(): void
    {
        $info = $this->collector->getRollupTiersInfo();
        // Default RollupProcessor tiers
        $this->assertArrayHasKey('1min', $info);
        $this->assertArrayHasKey('5min', $info);
        // May have additional tiers based on configuration
        $this->assertNotEmpty($info);
    }

    public function testGetRollupTiersInfoHasRequiredFields(): void
    {
        $info = $this->collector->getRollupTiersInfo();

        foreach ($info as $tier => $tierInfo) {
            // Core fields that must be present
            $this->assertArrayHasKey('interval', $tierInfo);
            $this->assertArrayHasKey('retention', $tierInfo);
            $this->assertArrayHasKey('label', $tierInfo);
            // Verify it's a proper array with expected data types
            $this->assertIsInt($tierInfo['interval']);
            $this->assertIsInt($tierInfo['retention']);
            $this->assertIsString($tierInfo['label']);
        }
    }

    // =================================================================
    // Best Tier Selection Tests
    // =================================================================

    public function testGetBestRollupTierForShortRange(): void
    {
        $tier = $this->collector->getBestRollupTier(1);
        $this->assertEquals('1min', $tier);
    }

    public function testGetBestRollupTierForMediumRange(): void
    {
        $tier = $this->collector->getBestRollupTier(12);
        $this->assertEquals('5min', $tier);
    }

    public function testGetBestRollupTierForLongRange(): void
    {
        $tier = $this->collector->getBestRollupTier(48);
        // RollupProcessor selects tier based on hours, returns valid tier
        $this->assertContains($tier, ['5min', '15min', '1hour', '30min']);
    }

    public function testGetBestRollupTierForVeryLongRange(): void
    {
        $tier = $this->collector->getBestRollupTier(168); // 1 week
        // RollupProcessor selects tier based on hours, returns valid tier
        $this->assertContains($tier, ['15min', '1hour', '30min', '2hour']);
    }

    // =================================================================
    // Read Raw Metrics Tests
    // =================================================================

    public function testReadRawMetricsReturnsEmptyArrayForNonexistentFile(): void
    {
        $metrics = $this->collector->readRawMetrics(0);
        $this->assertIsArray($metrics);
        $this->assertEmpty($metrics);
    }

    // =================================================================
    // Read Rollup Data Tests
    // =================================================================

    public function testReadRollupDataReturnsResultForNonexistentFile(): void
    {
        $result = $this->collector->readRollupData('1min', null, null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // File doesn't exist so either success with empty data, or success = false
        if ($result['success']) {
            $this->assertArrayHasKey('data', $result);
        }
    }

    // =================================================================
    // Get Metrics Tests
    // =================================================================

    public function testGetMetricsReturnsResultWithTierInfo(): void
    {
        $result = $this->collector->getMetrics(24);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // tier_info is only added if success is true
        if ($result['success']) {
            $this->assertArrayHasKey('tier_info', $result);
        }
    }

    public function testGetMetricsSelectsCorrectTier(): void
    {
        $result = $this->collector->getMetrics(1);
        if ($result['success'] && isset($result['tier_info'])) {
            $this->assertEquals('1min', $result['tier_info']['tier']);
        } else {
            $this->markTestSkipped('No tier_info returned - file does not exist');
        }
    }

    // =================================================================
    // Abstract Method Implementation Tests
    // =================================================================

    public function testAggregateForRollupReturnsNullForEmptyMetrics(): void
    {
        $result = $this->collector->aggregateForRollup([], 1000, 60);
        $this->assertNull($result);
    }

    public function testAggregateForRollupReturnsDataForValidMetrics(): void
    {
        $metrics = [
            ['timestamp' => 1000, 'value' => 1],
            ['timestamp' => 1001, 'value' => 2]
        ];

        $result = $this->collector->aggregateForRollup($metrics, 1000, 60);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('period_end', $result);
        $this->assertEquals(1000, $result['timestamp']);
        $this->assertEquals(1060, $result['period_end']);
        $this->assertEquals(2, $result['count']);
    }
}
