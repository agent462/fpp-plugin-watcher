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
}
