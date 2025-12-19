<?php
/**
 * Unit tests for RollupProcessor class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\RollupProcessor;

class RollupProcessorTest extends TestCase
{
    private RollupProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new RollupProcessor();
    }

    // =========================================================================
    // Tier Configuration Tests
    // =========================================================================

    public function testTiersConstantHasExpectedKeys(): void
    {
        $tiers = RollupProcessor::TIERS;

        $this->assertArrayHasKey('1min', $tiers);
        $this->assertArrayHasKey('5min', $tiers);
        $this->assertArrayHasKey('30min', $tiers);
        $this->assertArrayHasKey('2hour', $tiers);
    }

    public function testEachTierHasRequiredFields(): void
    {
        foreach (RollupProcessor::TIERS as $tier => $config) {
            $this->assertArrayHasKey('interval', $config, "Tier {$tier} missing 'interval'");
            $this->assertArrayHasKey('retention', $config, "Tier {$tier} missing 'retention'");
            $this->assertArrayHasKey('label', $config, "Tier {$tier} missing 'label'");
        }
    }

    public function testTierIntervalsAreIncreasing(): void
    {
        $tiers = RollupProcessor::TIERS;

        $this->assertLessThan($tiers['5min']['interval'], $tiers['1min']['interval']);
        $this->assertLessThan($tiers['30min']['interval'], $tiers['5min']['interval']);
        $this->assertLessThan($tiers['2hour']['interval'], $tiers['30min']['interval']);
    }

    public function testTierRetentionsAreIncreasing(): void
    {
        $tiers = RollupProcessor::TIERS;

        $this->assertLessThan($tiers['5min']['retention'], $tiers['1min']['retention']);
        $this->assertLessThan($tiers['30min']['retention'], $tiers['5min']['retention']);
        $this->assertLessThan($tiers['2hour']['retention'], $tiers['30min']['retention']);
    }

    public function testGetTiersReturnsConfiguration(): void
    {
        $tiers = $this->processor->getTiers();

        $this->assertIsArray($tiers);
        $this->assertEquals(RollupProcessor::TIERS, $tiers);
    }

    public function testCustomTiersCanBeInjected(): void
    {
        $customTiers = [
            'custom' => [
                'interval' => 120,
                'retention' => 3600,
                'label' => 'Custom tier'
            ]
        ];

        $processor = new RollupProcessor($customTiers);
        $tiers = $processor->getTiers();

        $this->assertArrayHasKey('custom', $tiers);
        $this->assertArrayNotHasKey('1min', $tiers);
    }

    // =========================================================================
    // Tier Selection Tests
    // =========================================================================

    public function testGetBestTierForHoursUnder6(): void
    {
        $this->assertEquals('1min', $this->processor->getBestTierForHours(1));
        $this->assertEquals('1min', $this->processor->getBestTierForHours(3));
        $this->assertEquals('1min', $this->processor->getBestTierForHours(6));
    }

    public function testGetBestTierForHours7To48(): void
    {
        $this->assertEquals('5min', $this->processor->getBestTierForHours(7));
        $this->assertEquals('5min', $this->processor->getBestTierForHours(24));
        $this->assertEquals('5min', $this->processor->getBestTierForHours(48));
    }

    public function testGetBestTierForHours49To336(): void
    {
        $this->assertEquals('30min', $this->processor->getBestTierForHours(49));
        $this->assertEquals('30min', $this->processor->getBestTierForHours(168)); // 1 week
        $this->assertEquals('30min', $this->processor->getBestTierForHours(336)); // 14 days
    }

    public function testGetBestTierForHoursOver336(): void
    {
        $this->assertEquals('2hour', $this->processor->getBestTierForHours(337));
        $this->assertEquals('2hour', $this->processor->getBestTierForHours(720)); // 30 days
        $this->assertEquals('2hour', $this->processor->getBestTierForHours(2160)); // 90 days
    }

    public function testGetTiersInfo(): void
    {
        $getFilePath = fn($tier) => $this->testTmpDir . "/rollup_{$tier}.log";

        // Create one file to test file_exists logic
        $testFile = $getFilePath('1min');
        file_put_contents($testFile, "test content");

        $info = $this->processor->getTiersInfo($getFilePath);

        $this->assertArrayHasKey('1min', $info);
        $this->assertArrayHasKey('5min', $info);

        // Check 1min tier structure
        $this->assertArrayHasKey('interval', $info['1min']);
        $this->assertArrayHasKey('interval_label', $info['1min']);
        $this->assertArrayHasKey('retention', $info['1min']);
        $this->assertArrayHasKey('retention_label', $info['1min']);
        $this->assertArrayHasKey('file_exists', $info['1min']);
        $this->assertArrayHasKey('file_size', $info['1min']);

        $this->assertTrue($info['1min']['file_exists']);
        $this->assertGreaterThan(0, $info['1min']['file_size']);
        $this->assertFalse($info['5min']['file_exists']);
    }

    // =========================================================================
    // Quality Rating Tests
    // =========================================================================

    public function testLatencyThresholdsExist(): void
    {
        $thresholds = RollupProcessor::LATENCY_THRESHOLDS;

        $this->assertArrayHasKey('good', $thresholds);
        $this->assertArrayHasKey('fair', $thresholds);
        $this->assertArrayHasKey('poor', $thresholds);
    }

    public function testJitterThresholdsExist(): void
    {
        $thresholds = RollupProcessor::JITTER_THRESHOLDS;

        $this->assertArrayHasKey('good', $thresholds);
        $this->assertArrayHasKey('fair', $thresholds);
        $this->assertArrayHasKey('poor', $thresholds);
    }

    public function testPacketLossThresholdsExist(): void
    {
        $thresholds = RollupProcessor::PACKET_LOSS_THRESHOLDS;

        $this->assertArrayHasKey('good', $thresholds);
        $this->assertArrayHasKey('fair', $thresholds);
        $this->assertArrayHasKey('poor', $thresholds);
    }

    public function testGetQualityRatingGood(): void
    {
        $result = $this->processor->getQualityRating(25, 50, 100, 250);
        $this->assertEquals('good', $result);
    }

    public function testGetQualityRatingFair(): void
    {
        $result = $this->processor->getQualityRating(75, 50, 100, 250);
        $this->assertEquals('fair', $result);
    }

    public function testGetQualityRatingPoor(): void
    {
        $result = $this->processor->getQualityRating(150, 50, 100, 250);
        $this->assertEquals('poor', $result);
    }

    public function testGetQualityRatingCritical(): void
    {
        $result = $this->processor->getQualityRating(300, 50, 100, 250);
        $this->assertEquals('critical', $result);
    }

    public function testGetQualityRatingBoundaryValues(): void
    {
        // Exactly at threshold - should be next level
        $this->assertEquals('fair', $this->processor->getQualityRating(50, 50, 100, 250));
        $this->assertEquals('poor', $this->processor->getQualityRating(100, 50, 100, 250));
        $this->assertEquals('critical', $this->processor->getQualityRating(250, 50, 100, 250));
    }

    public function testGetOverallQualityRatingCriticalDominates(): void
    {
        $result = $this->processor->getOverallQualityRating('good', 'good', 'critical');
        $this->assertEquals('critical', $result);
    }

    public function testGetOverallQualityRatingPoorDominates(): void
    {
        $result = $this->processor->getOverallQualityRating('good', 'poor', 'fair');
        $this->assertEquals('poor', $result);
    }

    public function testGetOverallQualityRatingFairDominates(): void
    {
        $result = $this->processor->getOverallQualityRating('good', 'good', 'fair');
        $this->assertEquals('fair', $result);
    }

    public function testGetOverallQualityRatingAllGood(): void
    {
        $result = $this->processor->getOverallQualityRating('good', 'good', 'good');
        $this->assertEquals('good', $result);
    }

    // =========================================================================
    // Jitter Calculation Tests (RFC 3550)
    // =========================================================================

    public function testCalculateJitterRFC3550FirstSampleReturnsNull(): void
    {
        $state = [];
        $result = $this->processor->calculateJitterRFC3550('host1', 50.0, $state);

        $this->assertNull($result);
        $this->assertArrayHasKey('host1', $state);
    }

    public function testCalculateJitterRFC3550InitializesState(): void
    {
        $state = [];
        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);

        $this->assertEquals(50.0, $state['host1']['prevLatency']);
        $this->assertEquals(0.0, $state['host1']['jitter']);
    }

    public function testCalculateJitterRFC3550SecondSampleReturnsValue(): void
    {
        $state = [];
        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);
        $result = $this->processor->calculateJitterRFC3550('host1', 60.0, $state);

        $this->assertNotNull($result);
        $this->assertIsFloat($result);
    }

    public function testCalculateJitterRFC3550MatchesFormula(): void
    {
        $state = [];

        // First sample initializes state
        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);

        // Second sample: J = 0 + (|60-50| - 0) / 16 = 0.625
        $jitter = $this->processor->calculateJitterRFC3550('host1', 60.0, $state);
        $this->assertEquals(0.63, $jitter); // Rounded to 2 decimal places

        // Third sample: J = 0.625 + (|55-60| - 0.625) / 16 = 0.625 + 0.2734 = 0.8984
        $jitter = $this->processor->calculateJitterRFC3550('host1', 55.0, $state);
        $this->assertEqualsWithDelta(0.90, $jitter, 0.01);
    }

    public function testCalculateJitterRFC3550IndependentHosts(): void
    {
        $state = [];

        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);
        $this->processor->calculateJitterRFC3550('host2', 100.0, $state);

        $jitter1 = $this->processor->calculateJitterRFC3550('host1', 60.0, $state);
        $jitter2 = $this->processor->calculateJitterRFC3550('host2', 80.0, $state);

        $this->assertArrayHasKey('host1', $state);
        $this->assertArrayHasKey('host2', $state);
        $this->assertNotEquals($jitter1, $jitter2);
    }

    public function testCalculateJitterFromLatencyArrayInsufficientSamples(): void
    {
        $result = $this->processor->calculateJitterFromLatencyArray([50.0]);
        $this->assertNull($result);

        $result = $this->processor->calculateJitterFromLatencyArray([]);
        $this->assertNull($result);
    }

    public function testCalculateJitterFromLatencyArrayReturnsAvgAndMax(): void
    {
        $latencies = [50.0, 60.0, 55.0, 70.0, 65.0];
        $result = $this->processor->calculateJitterFromLatencyArray($latencies);

        $this->assertArrayHasKey('avg', $result);
        $this->assertArrayHasKey('max', $result);
        $this->assertIsFloat($result['avg']);
        $this->assertIsFloat($result['max']);
        $this->assertGreaterThanOrEqual($result['avg'], $result['max']);
    }

    public function testCalculateJitterFromLatencyArrayStableLatency(): void
    {
        $latencies = [50.0, 50.0, 50.0, 50.0, 50.0];
        $result = $this->processor->calculateJitterFromLatencyArray($latencies);

        // All same latency = 0 jitter
        $this->assertEquals(0.0, $result['avg']);
        $this->assertEquals(0.0, $result['max']);
    }

    // =========================================================================
    // Latency Aggregation Tests
    // =========================================================================

    public function testAggregateLatenciesEmptyArray(): void
    {
        $result = $this->processor->aggregateLatencies([]);

        $this->assertNull($result['latency_min']);
        $this->assertNull($result['latency_max']);
        $this->assertNull($result['latency_avg']);
        $this->assertNull($result['latency_p95']);
    }

    public function testAggregateLatenciesBasicStats(): void
    {
        $latencies = [10.0, 20.0, 30.0, 40.0, 50.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(10.0, $result['latency_min']);
        $this->assertEquals(50.0, $result['latency_max']);
        $this->assertEquals(30.0, $result['latency_avg']);
    }

    public function testAggregateLatenciesP95(): void
    {
        // Create 100 values where P95 should be around 95
        $latencies = range(1, 100);
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(95.0, $result['latency_p95']);
    }

    public function testAggregateLatenciesPrecision(): void
    {
        $latencies = [10.123, 20.456, 30.789];
        $result = $this->processor->aggregateLatencies($latencies, 2);

        $this->assertEquals(10.12, $result['latency_min']);
        $this->assertEquals(30.79, $result['latency_max']);
    }

    public function testAggregateLatenciesWithoutP95(): void
    {
        $latencies = [10.0, 20.0, 30.0];
        $result = $this->processor->aggregateLatencies($latencies, 1, false);

        $this->assertArrayNotHasKey('latency_p95', $result);
    }

    public function testAggregateLatenciesSingleValue(): void
    {
        $latencies = [50.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(50.0, $result['latency_min']);
        $this->assertEquals(50.0, $result['latency_max']);
        $this->assertEquals(50.0, $result['latency_avg']);
        $this->assertEquals(50.0, $result['latency_p95']);
    }

    // =========================================================================
    // State Management Tests
    // =========================================================================

    public function testGetStateCreatesNewFile(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';

        $state = $this->processor->getState($stateFile);

        $this->assertFileExists($stateFile);
        $this->assertArrayHasKey('1min', $state);
        $this->assertArrayHasKey('5min', $state);
        $this->assertArrayHasKey('30min', $state);
        $this->assertArrayHasKey('2hour', $state);
    }

    public function testGetStateInitializesAllFields(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';

        $state = $this->processor->getState($stateFile);

        foreach ($state as $tier => $tierState) {
            $this->assertArrayHasKey('last_processed', $tierState);
            $this->assertArrayHasKey('last_bucket_end', $tierState);
            $this->assertArrayHasKey('last_rollup', $tierState);
        }
    }

    public function testSaveStateWritesToFile(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';
        $state = [
            '1min' => ['last_processed' => 1000, 'last_bucket_end' => 1060, 'last_rollup' => 1100]
        ];

        $result = $this->processor->saveState($stateFile, $state);

        $this->assertTrue($result);
        $this->assertFileExists($stateFile);

        $content = file_get_contents($stateFile);
        $saved = json_decode($content, true);
        $this->assertEquals($state, $saved);
    }

    public function testGetStateReadsExistingFile(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';
        $state = [
            '1min' => ['last_processed' => 1000, 'last_bucket_end' => 1060, 'last_rollup' => 1100]
        ];
        file_put_contents($stateFile, json_encode($state));

        $loaded = $this->processor->getState($stateFile);

        $this->assertEquals(1000, $loaded['1min']['last_processed']);
    }

    public function testGetStateBackfillsMissingTiers(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';
        // Write state with only one tier
        $state = [
            '1min' => ['last_processed' => 1000, 'last_bucket_end' => 1060, 'last_rollup' => 1100]
        ];
        file_put_contents($stateFile, json_encode($state));

        $loaded = $this->processor->getState($stateFile);

        // Should have all tiers now
        $this->assertArrayHasKey('5min', $loaded);
        $this->assertArrayHasKey('30min', $loaded);
        $this->assertArrayHasKey('2hour', $loaded);
    }

    public function testGetStateHandlesCorruptedFile(): void
    {
        $stateFile = $this->testTmpDir . '/state.json';
        file_put_contents($stateFile, 'not valid json');

        $state = $this->processor->getState($stateFile);

        // Should rebuild fresh state
        $this->assertArrayHasKey('1min', $state);
        $this->assertEquals(0, $state['1min']['last_processed']);
    }

    // =========================================================================
    // Rollup Data Operations Tests
    // =========================================================================

    public function testReadRollupDataFromNonexistentFile(): void
    {
        $result = $this->processor->readRollupData('/nonexistent/file.log', '1min');

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['data']);
    }

    public function testReadRollupDataReturnsEntries(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $now = time();

        // Write some entries
        $entries = [
            "[" . date('Y-m-d H:i:s', $now - 60) . "] {\"timestamp\":{$now}-60,\"value\":1}",
            "[" . date('Y-m-d H:i:s', $now) . "] {\"timestamp\":{$now},\"value\":2}",
        ];
        $content = implode("\n", $entries);
        // Fix the timestamp format
        $content = "[" . date('Y-m-d H:i:s', $now - 60) . "] " . json_encode(['timestamp' => $now - 60, 'value' => 1]) . "\n";
        $content .= "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now, 'value' => 2]) . "\n";
        file_put_contents($rollupFile, $content);

        $result = $this->processor->readRollupData($rollupFile, '1min', $now - 120, $now + 60);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
    }

    public function testReadRollupDataFiltersbyTimeRange(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $now = time();

        $content = "";
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - ($i * 60);
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts, 'value' => $i]) . "\n";
        }
        file_put_contents($rollupFile, $content);

        // Only get last 2 minutes
        $result = $this->processor->readRollupData($rollupFile, '1min', $now - 120, $now);

        $this->assertTrue($result['success']);
        $this->assertLessThanOrEqual(3, $result['count']); // At most 3 entries (0, 1, 2 minutes ago)
    }

    public function testReadRollupDataWithFilter(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $now = time();

        $content = "";
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - ($i * 60);
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts, 'type' => $i % 2 === 0 ? 'even' : 'odd']) . "\n";
        }
        file_put_contents($rollupFile, $content);

        // Filter to only even types
        $result = $this->processor->readRollupData(
            $rollupFile,
            '1min',
            $now - 300,
            $now,
            fn($e) => $e['type'] === 'even'
        );

        $this->assertTrue($result['success']);
        foreach ($result['data'] as $entry) {
            $this->assertEquals('even', $entry['type']);
        }
    }

    public function testAppendRollupEntries(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $entries = [
            ['timestamp' => time(), 'value' => 'test1'],
            ['timestamp' => time() + 60, 'value' => 'test2'],
        ];

        $result = $this->processor->appendRollupEntries($rollupFile, $entries);

        $this->assertTrue($result);
        $this->assertFileExists($rollupFile);

        $content = file_get_contents($rollupFile);
        $this->assertStringContainsString('"value":"test1"', $content);
        $this->assertStringContainsString('"value":"test2"', $content);
    }

    public function testAppendRollupEntriesAppends(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';

        $entries1 = [['timestamp' => time(), 'value' => 'first']];
        $entries2 = [['timestamp' => time() + 60, 'value' => 'second']];

        $this->processor->appendRollupEntries($rollupFile, $entries1);
        $this->processor->appendRollupEntries($rollupFile, $entries2);

        $content = file_get_contents($rollupFile);
        $this->assertStringContainsString('first', $content);
        $this->assertStringContainsString('second', $content);
    }

    // =========================================================================
    // Formatting Utility Tests
    // =========================================================================

    public function testFormatIntervalSeconds(): void
    {
        $result = RollupProcessor::formatInterval(30);
        $this->assertEquals('30 seconds', $result);
    }

    public function testFormatIntervalMinutes(): void
    {
        $result = RollupProcessor::formatInterval(300);
        $this->assertEquals('5 minutes', $result);
    }

    public function testFormatIntervalHours(): void
    {
        $result = RollupProcessor::formatInterval(7200);
        $this->assertEquals('2 hours', $result);
    }

    public function testFormatDurationMinutes(): void
    {
        $result = RollupProcessor::formatDuration(1800);
        $this->assertEquals('30 minutes', $result);
    }

    public function testFormatDurationHours(): void
    {
        $result = RollupProcessor::formatDuration(21600);
        $this->assertEquals('6 hours', $result);
    }

    public function testFormatDurationDays(): void
    {
        $result = RollupProcessor::formatDuration(172800);
        $this->assertEquals('2 days', $result);
    }

    // =========================================================================
    // Additional Edge Case Tests for Data Aggregation
    // =========================================================================

    public function testAggregateLatenciesUnsortedInput(): void
    {
        // Verify that unsorted input still produces correct results
        $latencies = [50.0, 10.0, 40.0, 20.0, 30.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(10.0, $result['latency_min']);
        $this->assertEquals(50.0, $result['latency_max']);
        $this->assertEquals(30.0, $result['latency_avg']);
    }

    public function testAggregateLatenciesWithDuplicates(): void
    {
        $latencies = [20.0, 20.0, 20.0, 50.0, 50.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(20.0, $result['latency_min']);
        $this->assertEquals(50.0, $result['latency_max']);
        $this->assertEquals(32.0, $result['latency_avg']);
    }

    public function testAggregateLatenciesP95WithTwoValues(): void
    {
        $latencies = [10.0, 100.0];
        $result = $this->processor->aggregateLatencies($latencies);

        // P95 index: ceil(2 * 0.95) - 1 = ceil(1.9) - 1 = 2 - 1 = 1
        $this->assertEquals(100.0, $result['latency_p95']);
    }

    public function testAggregateLatenciesP95WithThreeValues(): void
    {
        $latencies = [10.0, 50.0, 100.0];
        $result = $this->processor->aggregateLatencies($latencies);

        // P95 index: ceil(3 * 0.95) - 1 = ceil(2.85) - 1 = 3 - 1 = 2
        $this->assertEquals(100.0, $result['latency_p95']);
    }

    public function testAggregateLatenciesP95WithTenValues(): void
    {
        $latencies = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];
        $result = $this->processor->aggregateLatencies($latencies);

        // P95 index: ceil(10 * 0.95) - 1 = ceil(9.5) - 1 = 10 - 1 = 9
        $this->assertEquals(10.0, $result['latency_p95']);
    }

    public function testAggregateLatenciesWithNegativeValues(): void
    {
        // Edge case: negative values (shouldn't happen but test robustness)
        $latencies = [-10.0, 0.0, 10.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(-10.0, $result['latency_min']);
        $this->assertEquals(10.0, $result['latency_max']);
        $this->assertEquals(0.0, $result['latency_avg']);
    }

    public function testAggregateLatenciesWithVeryLargeValues(): void
    {
        $latencies = [1000000.0, 2000000.0, 3000000.0];
        $result = $this->processor->aggregateLatencies($latencies);

        $this->assertEquals(1000000.0, $result['latency_min']);
        $this->assertEquals(3000000.0, $result['latency_max']);
        $this->assertEquals(2000000.0, $result['latency_avg']);
    }

    public function testAggregateLatenciesWithVerySmallValues(): void
    {
        $latencies = [0.001, 0.002, 0.003];
        $result = $this->processor->aggregateLatencies($latencies, 3);

        $this->assertEquals(0.001, $result['latency_min']);
        $this->assertEquals(0.003, $result['latency_max']);
        $this->assertEquals(0.002, $result['latency_avg']);
    }

    // =========================================================================
    // Jitter Calculation Edge Cases
    // =========================================================================

    public function testCalculateJitterFromLatencyArrayWithTwoSamples(): void
    {
        $latencies = [50.0, 60.0];
        $result = $this->processor->calculateJitterFromLatencyArray($latencies);

        $this->assertNotNull($result);
        // J = 0 + (|60-50| - 0) / 16 = 0.625
        $this->assertEquals(0.63, $result['avg']);
        $this->assertEquals(0.63, $result['max']);
    }

    public function testCalculateJitterFromLatencyArrayHighVariance(): void
    {
        // Large swings in latency should produce high jitter
        $latencies = [10.0, 100.0, 10.0, 100.0, 10.0];
        $result = $this->processor->calculateJitterFromLatencyArray($latencies);

        $this->assertGreaterThan(5.0, $result['max']);
    }

    public function testCalculateJitterRFC3550ZeroDifference(): void
    {
        $state = [];
        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);
        $jitter = $this->processor->calculateJitterRFC3550('host1', 50.0, $state);

        // Same latency twice = 0 difference = no jitter increase
        $this->assertEquals(0.0, $jitter);
    }

    public function testCalculateJitterRFC3550DecreasingJitter(): void
    {
        $state = [];

        // First sample
        $this->processor->calculateJitterRFC3550('host1', 50.0, $state);

        // Large difference causes high jitter
        $this->processor->calculateJitterRFC3550('host1', 100.0, $state);

        // Consecutive same values should decrease jitter over time
        for ($i = 0; $i < 20; $i++) {
            $jitter = $this->processor->calculateJitterRFC3550('host1', 100.0, $state);
        }

        // Jitter should decay toward 0 with stable latency
        $this->assertLessThan(1.0, $jitter);
    }

    // =========================================================================
    // Rotate Rollup File Tests
    // =========================================================================

    public function testRotateRollupFileNonexistentFile(): void
    {
        // Should not throw error for nonexistent file
        $this->processor->rotateRollupFile('/nonexistent/file.log', 3600);
        $this->assertTrue(true); // No exception thrown
    }

    public function testRotateRollupFileSmallFile(): void
    {
        $rollupFile = $this->testTmpDir . '/small_rollup.log';
        $now = time();

        // Create a small file (under 1MB threshold)
        $content = "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now]) . "\n";
        file_put_contents($rollupFile, $content);

        $sizeBefore = filesize($rollupFile);
        $this->processor->rotateRollupFile($rollupFile, 3600);

        // File should be unchanged (under 1MB threshold)
        $this->assertEquals($sizeBefore, filesize($rollupFile));
    }

    public function testRotateRollupFileLargeFileRemovesOldEntries(): void
    {
        $rollupFile = $this->testTmpDir . '/large_rollup.log';
        $now = time();
        $twoHoursAgo = $now - 7200;
        $recentTime = $now - 60;

        // Create a file larger than 1MB with old and new entries
        $fp = fopen($rollupFile, 'w');

        // Add many old entries to exceed 1MB
        for ($i = 0; $i < 10000; $i++) {
            $line = "[" . date('Y-m-d H:i:s', $twoHoursAgo) . "] " .
                json_encode(['timestamp' => $twoHoursAgo, 'data' => str_repeat('x', 100)]) . "\n";
            fwrite($fp, $line);
        }

        // Add some recent entries
        for ($i = 0; $i < 100; $i++) {
            $line = "[" . date('Y-m-d H:i:s', $recentTime) . "] " .
                json_encode(['timestamp' => $recentTime, 'data' => 'recent']) . "\n";
            fwrite($fp, $line);
        }

        fclose($fp);
        clearstatcache(true, $rollupFile);
        $sizeBefore = filesize($rollupFile);

        // Rotate with 1 hour retention
        $this->processor->rotateRollupFile($rollupFile, 3600);

        clearstatcache(true, $rollupFile);
        $sizeAfter = filesize($rollupFile);

        // File should be smaller after removing old entries
        $this->assertLessThan($sizeBefore, $sizeAfter);

        // Verify only recent entries remain
        $remaining = file_get_contents($rollupFile);
        $this->assertStringContainsString('"data":"recent"', $remaining);
    }

    public function testRotateRollupFileAllEntriesExpired(): void
    {
        $rollupFile = $this->testTmpDir . '/old_rollup.log';
        $twoHoursAgo = time() - 7200;

        // Create a large file with only old entries
        $fp = fopen($rollupFile, 'w');
        for ($i = 0; $i < 10000; $i++) {
            $line = "[" . date('Y-m-d H:i:s', $twoHoursAgo) . "] " .
                json_encode(['timestamp' => $twoHoursAgo, 'data' => str_repeat('x', 100)]) . "\n";
            fwrite($fp, $line);
        }
        fclose($fp);

        clearstatcache(true, $rollupFile);

        // Rotate with 1 hour retention - all entries should be removed
        $this->processor->rotateRollupFile($rollupFile, 3600);

        clearstatcache(true, $rollupFile);

        // File should be empty
        $this->assertEquals(0, filesize($rollupFile));
    }

    // =========================================================================
    // State Management Edge Cases
    // =========================================================================

    public function testGetStateBackfillsMissingFields(): void
    {
        $stateFile = $this->testTmpDir . '/partial_state.json';

        // Write state with missing fields within a tier
        $state = [
            '1min' => ['last_processed' => 1000]
            // Missing: last_bucket_end, last_rollup
        ];
        file_put_contents($stateFile, json_encode($state));

        $loaded = $this->processor->getState($stateFile);

        // Should backfill missing fields
        $this->assertArrayHasKey('last_bucket_end', $loaded['1min']);
        $this->assertArrayHasKey('last_rollup', $loaded['1min']);
        $this->assertEquals(0, $loaded['1min']['last_bucket_end']);
    }

    public function testGetStateWithEmptyJsonObject(): void
    {
        $stateFile = $this->testTmpDir . '/empty_state.json';
        file_put_contents($stateFile, '{}');

        $loaded = $this->processor->getState($stateFile);

        // Should rebuild with all tiers
        $this->assertArrayHasKey('1min', $loaded);
        $this->assertArrayHasKey('5min', $loaded);
        $this->assertArrayHasKey('30min', $loaded);
        $this->assertArrayHasKey('2hour', $loaded);
    }

    public function testGetStateWithEmptyArray(): void
    {
        $stateFile = $this->testTmpDir . '/array_state.json';
        file_put_contents($stateFile, '[]');

        $loaded = $this->processor->getState($stateFile);

        // Empty array is truthy but not associative - should rebuild
        $this->assertArrayHasKey('1min', $loaded);
    }

    public function testGetStateWithNullContent(): void
    {
        $stateFile = $this->testTmpDir . '/null_state.json';
        file_put_contents($stateFile, 'null');

        $loaded = $this->processor->getState($stateFile);

        // null decodes to null, should rebuild
        $this->assertArrayHasKey('1min', $loaded);
    }

    public function testSaveStatePrettyPrints(): void
    {
        $stateFile = $this->testTmpDir . '/pretty_state.json';
        $state = ['1min' => ['last_processed' => 1000]];

        $this->processor->saveState($stateFile, $state);

        $content = file_get_contents($stateFile);
        // JSON_PRETTY_PRINT adds newlines
        $this->assertStringContainsString("\n", $content);
    }

    // =========================================================================
    // Read Rollup Data Edge Cases
    // =========================================================================

    public function testReadRollupDataWithDefaultTimeRange(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $now = time();

        // Write entry within default retention period
        $content = "[" . date('Y-m-d H:i:s', $now - 100) . "] " .
            json_encode(['timestamp' => $now - 100, 'value' => 'test']) . "\n";
        file_put_contents($rollupFile, $content);

        // Pass null for start/end time - should use tier defaults
        $result = $this->processor->readRollupData($rollupFile, '1min', null, null);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
    }

    public function testReadRollupDataWithInvalidTier(): void
    {
        $rollupFile = $this->testTmpDir . '/rollup.log';
        $now = time();

        $content = "[" . date('Y-m-d H:i:s', $now) . "] " .
            json_encode(['timestamp' => $now]) . "\n";
        file_put_contents($rollupFile, $content);

        // Invalid tier should fall back to 24-hour default
        $result = $this->processor->readRollupData($rollupFile, 'invalid_tier', null, null);

        $this->assertTrue($result['success']);
    }

    public function testReadRollupDataMalformedJsonLines(): void
    {
        $rollupFile = $this->testTmpDir . '/malformed.log';
        $now = time();

        // Mix of valid and invalid lines
        $content = "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now, 'valid' => true]) . "\n";
        $content .= "[" . date('Y-m-d H:i:s', $now) . "] not valid json\n";
        $content .= "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now, 'also_valid' => true]) . "\n";
        $content .= "completely malformed line\n";
        file_put_contents($rollupFile, $content);

        $result = $this->processor->readRollupData($rollupFile, '1min', $now - 60, $now + 60);

        // Should only return valid entries
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
    }

    public function testReadRollupDataMissingTimestamp(): void
    {
        $rollupFile = $this->testTmpDir . '/no_timestamp.log';
        $now = time();

        // Entry without timestamp field
        $content = "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['value' => 'no timestamp']) . "\n";
        $content .= "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now, 'value' => 'has timestamp']) . "\n";
        file_put_contents($rollupFile, $content);

        $result = $this->processor->readRollupData($rollupFile, '1min', $now - 60, $now + 60);

        // Only entry with timestamp should be returned
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('has timestamp', $result['data'][0]['value']);
    }

    public function testReadRollupDataSortsResults(): void
    {
        $rollupFile = $this->testTmpDir . '/unsorted.log';
        $now = time();

        // Write entries in reverse order
        $content = "";
        for ($i = 5; $i >= 1; $i--) {
            $ts = $now - ($i * 60);
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts, 'order' => $i]) . "\n";
        }
        file_put_contents($rollupFile, $content);

        $result = $this->processor->readRollupData($rollupFile, '1min', $now - 400, $now);

        // Results should be sorted by timestamp ascending
        $this->assertTrue($result['success']);
        $prevTimestamp = 0;
        foreach ($result['data'] as $entry) {
            $this->assertGreaterThan($prevTimestamp, $entry['timestamp']);
            $prevTimestamp = $entry['timestamp'];
        }
    }

    public function testReadRollupDataReturnsPeriodInfo(): void
    {
        $rollupFile = $this->testTmpDir . '/period.log';
        $now = time();
        $startTime = $now - 3600;

        $content = "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now]) . "\n";
        file_put_contents($rollupFile, $content);

        $result = $this->processor->readRollupData($rollupFile, '1min', $startTime, $now);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('period', $result);
        $this->assertEquals($startTime, $result['period']['start']);
        $this->assertEquals($now, $result['period']['end']);
    }

    // =========================================================================
    // Quality Rating Edge Cases
    // =========================================================================

    public function testGetQualityRatingZeroValue(): void
    {
        $result = $this->processor->getQualityRating(0.0, 50, 100, 250);
        $this->assertEquals('good', $result);
    }

    public function testGetQualityRatingNegativeValue(): void
    {
        // Negative values should still be 'good' (below threshold)
        $result = $this->processor->getQualityRating(-10.0, 50, 100, 250);
        $this->assertEquals('good', $result);
    }

    public function testGetQualityRatingExactlyAtCriticalThreshold(): void
    {
        // At exactly the poor threshold = critical
        $result = $this->processor->getQualityRating(250.0, 50, 100, 250);
        $this->assertEquals('critical', $result);
    }

    public function testGetQualityRatingVeryHighValue(): void
    {
        $result = $this->processor->getQualityRating(10000.0, 50, 100, 250);
        $this->assertEquals('critical', $result);
    }

    public function testGetOverallQualityRatingWithAllCritical(): void
    {
        $result = $this->processor->getOverallQualityRating('critical', 'critical', 'critical');
        $this->assertEquals('critical', $result);
    }

    public function testGetOverallQualityRatingWithMixedBadRatings(): void
    {
        // Critical should always win over poor
        $result = $this->processor->getOverallQualityRating('poor', 'critical', 'fair');
        $this->assertEquals('critical', $result);
    }

    // =========================================================================
    // Tier Selection Edge Cases
    // =========================================================================

    public function testGetBestTierForZeroHours(): void
    {
        $result = $this->processor->getBestTierForHours(0);
        $this->assertEquals('1min', $result);
    }

    public function testGetBestTierForNegativeHours(): void
    {
        // Negative hours should return finest granularity
        $result = $this->processor->getBestTierForHours(-5);
        $this->assertEquals('1min', $result);
    }

    public function testGetBestTierForVeryLargeHours(): void
    {
        $result = $this->processor->getBestTierForHours(8760); // 1 year
        $this->assertEquals('2hour', $result);
    }

    // =========================================================================
    // Append Rollup Entries Edge Cases
    // =========================================================================

    public function testAppendRollupEntriesEmptyArray(): void
    {
        $rollupFile = $this->testTmpDir . '/empty_append.log';

        $result = $this->processor->appendRollupEntries($rollupFile, []);

        $this->assertTrue($result);
        // File should be created but empty
        $this->assertFileExists($rollupFile);
        $this->assertEquals(0, filesize($rollupFile));
    }

    public function testAppendRollupEntriesWithSpecialCharacters(): void
    {
        $rollupFile = $this->testTmpDir . '/special.log';
        $entries = [
            ['timestamp' => time(), 'message' => 'Test with "quotes" and \\backslashes'],
            ['timestamp' => time(), 'message' => 'Test with unicode: 日本語'],
        ];

        $result = $this->processor->appendRollupEntries($rollupFile, $entries);

        $this->assertTrue($result);
        $content = file_get_contents($rollupFile);
        $this->assertStringContainsString('quotes', $content);
        // json_encode escapes unicode by default, so check for escaped form
        $this->assertStringContainsString('\u65e5\u672c\u8a9e', $content);
    }

    public function testAppendRollupEntriesWithNestedData(): void
    {
        $rollupFile = $this->testTmpDir . '/nested.log';
        $entries = [
            [
                'timestamp' => time(),
                'nested' => [
                    'level1' => [
                        'level2' => 'deep value'
                    ]
                ],
                'array' => [1, 2, 3]
            ]
        ];

        $result = $this->processor->appendRollupEntries($rollupFile, $entries);

        $this->assertTrue($result);
        $content = file_get_contents($rollupFile);
        $this->assertStringContainsString('deep value', $content);
    }

    // =========================================================================
    // Custom Tier Configuration Tests
    // =========================================================================

    public function testCustomTiersAffectGetState(): void
    {
        $customTiers = [
            'fast' => ['interval' => 30, 'retention' => 3600, 'label' => 'Fast'],
            'slow' => ['interval' => 3600, 'retention' => 86400, 'label' => 'Slow'],
        ];
        $processor = new RollupProcessor($customTiers);
        $stateFile = $this->testTmpDir . '/custom_state.json';

        $state = $processor->getState($stateFile);

        $this->assertArrayHasKey('fast', $state);
        $this->assertArrayHasKey('slow', $state);
        $this->assertArrayNotHasKey('1min', $state);
    }

    public function testEmptyCustomTiers(): void
    {
        $processor = new RollupProcessor([]);
        $stateFile = $this->testTmpDir . '/no_tier_state.json';

        $state = $processor->getState($stateFile);

        // Should have empty state
        $this->assertEmpty($state);
    }

    // =========================================================================
    // Formatting Edge Cases
    // =========================================================================

    public function testFormatIntervalExactlyOneMinute(): void
    {
        $result = RollupProcessor::formatInterval(60);
        $this->assertEquals('1 minutes', $result);
    }

    public function testFormatIntervalExactlyOneHour(): void
    {
        $result = RollupProcessor::formatInterval(3600);
        $this->assertEquals('1 hours', $result);
    }

    public function testFormatDurationExactlyOneHour(): void
    {
        $result = RollupProcessor::formatDuration(3600);
        $this->assertEquals('1 hours', $result);
    }

    public function testFormatDurationExactlyOneDay(): void
    {
        $result = RollupProcessor::formatDuration(86400);
        $this->assertEquals('1 days', $result);
    }

    public function testFormatIntervalZeroSeconds(): void
    {
        $result = RollupProcessor::formatInterval(0);
        $this->assertEquals('0 seconds', $result);
    }

    // =========================================================================
    // getTiersInfo Edge Cases
    // =========================================================================

    public function testGetTiersInfoWithNonexistentDirectory(): void
    {
        $getFilePath = fn($tier) => '/nonexistent/path/' . $tier . '.log';

        $info = $this->processor->getTiersInfo($getFilePath);

        foreach ($info as $tier => $tierInfo) {
            $this->assertFalse($tierInfo['file_exists']);
            $this->assertEquals(0, $tierInfo['file_size']);
        }
    }

    public function testGetTiersInfoHasCorrectLabels(): void
    {
        $getFilePath = fn($tier) => $this->testTmpDir . '/' . $tier . '.log';

        $info = $this->processor->getTiersInfo($getFilePath);

        $this->assertEquals('1-minute averages', $info['1min']['label']);
        $this->assertEquals('5-minute averages', $info['5min']['label']);
        $this->assertEquals('30-minute averages', $info['30min']['label']);
        $this->assertEquals('2-hour averages', $info['2hour']['label']);
    }
}
