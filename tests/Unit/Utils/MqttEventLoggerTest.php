<?php
/**
 * Unit tests for MqttEventLogger class
 *
 * @package Watcher\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Tests\TestCase;
use Watcher\Utils\MqttEventLogger;
use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * Testable MqttEventLogger that allows custom file paths
 */
class TestableMqttEventLogger extends MqttEventLogger
{
    private string $testEventsFile;

    public function __construct(string $eventsFile)
    {
        $this->testEventsFile = $eventsFile;

        // Use reflection to set parent private properties
        $parent = new \ReflectionClass(MqttEventLogger::class);

        $loggerProp = $parent->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($this, Logger::getInstance());

        $fileManagerProp = $parent->getProperty('fileManager');
        $fileManagerProp->setAccessible(true);
        $fileManagerProp->setValue($this, FileManager::getInstance());

        $eventsFileProp = $parent->getProperty('eventsFile');
        $eventsFileProp->setAccessible(true);
        $eventsFileProp->setValue($this, $eventsFile);

        $ownershipProp = $parent->getProperty('ownershipVerified');
        $ownershipProp->setAccessible(true);
        $ownershipProp->setValue($this, []);
    }

    public function getEventsFilePath(): string
    {
        return $this->testEventsFile;
    }
}

class MqttEventLoggerTest extends TestCase
{
    private TestableMqttEventLogger $logger;
    private string $eventsFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventsFile = $this->testTmpDir . '/mqtt-events.log';
        $this->logger = new TestableMqttEventLogger($this->eventsFile);
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = MqttEventLogger::getInstance();
        $instance2 = MqttEventLogger::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Event Type Constants Tests
    // =========================================================================

    public function testEventTypeConstants(): void
    {
        $this->assertEquals('ss', MqttEventLogger::EVENT_SEQ_START);
        $this->assertEquals('se', MqttEventLogger::EVENT_SEQ_STOP);
        $this->assertEquals('ps', MqttEventLogger::EVENT_PL_START);
        $this->assertEquals('pe', MqttEventLogger::EVENT_PL_STOP);
        $this->assertEquals('st', MqttEventLogger::EVENT_STATUS);
        $this->assertEquals('ms', MqttEventLogger::EVENT_MEDIA_START);
        $this->assertEquals('me', MqttEventLogger::EVENT_MEDIA_STOP);
        $this->assertEquals('wn', MqttEventLogger::EVENT_WARNING);
    }

    // =========================================================================
    // Event Label Tests
    // =========================================================================

    public function testGetEventLabelForSequenceEvents(): void
    {
        $this->assertEquals('Sequence Start', $this->logger->getEventLabel('ss'));
        $this->assertEquals('Sequence Stop', $this->logger->getEventLabel('se'));
    }

    public function testGetEventLabelForPlaylistEvents(): void
    {
        $this->assertEquals('Playlist Start', $this->logger->getEventLabel('ps'));
        $this->assertEquals('Playlist Stop', $this->logger->getEventLabel('pe'));
    }

    public function testGetEventLabelForMediaEvents(): void
    {
        $this->assertEquals('Media Start', $this->logger->getEventLabel('ms'));
        $this->assertEquals('Media Stop', $this->logger->getEventLabel('me'));
    }

    public function testGetEventLabelForOtherEvents(): void
    {
        $this->assertEquals('Status', $this->logger->getEventLabel('st'));
        $this->assertEquals('Warning', $this->logger->getEventLabel('wn'));
    }

    public function testGetEventLabelForUnknownEvent(): void
    {
        $result = $this->logger->getEventLabel('unknown');

        // Should return the input string for unknown events
        $this->assertEquals('unknown', $result);
    }

    public function testGetEventLabelForEmptyString(): void
    {
        $result = $this->logger->getEventLabel('');
        $this->assertEquals('', $result);
    }

    // =========================================================================
    // All Event Types Map Correctly
    // =========================================================================

    public function testAllEventTypesHaveLabels(): void
    {
        $eventTypes = [
            MqttEventLogger::EVENT_SEQ_START,
            MqttEventLogger::EVENT_SEQ_STOP,
            MqttEventLogger::EVENT_PL_START,
            MqttEventLogger::EVENT_PL_STOP,
            MqttEventLogger::EVENT_STATUS,
            MqttEventLogger::EVENT_MEDIA_START,
            MqttEventLogger::EVENT_MEDIA_STOP,
            MqttEventLogger::EVENT_WARNING,
        ];

        foreach ($eventTypes as $type) {
            $label = $this->logger->getEventLabel($type);

            $this->assertIsString($label);
            $this->assertNotEmpty($label);
            $this->assertNotEquals($type, $label); // Label should be human-readable
        }
    }

    // =========================================================================
    // Write Event Tests
    // =========================================================================

    public function testWriteEventCreatesFile(): void
    {
        $result = $this->logger->writeEvent('testhost', 'ss', 'test.fseq');

        $this->assertTrue($result);
        $this->assertFileExists($this->eventsFile);
    }

    public function testWriteEventAppendsToFile(): void
    {
        $this->logger->writeEvent('host1', 'ss', 'seq1.fseq');
        $this->logger->writeEvent('host2', 'ps', 'playlist1');
        $this->logger->writeEvent('host1', 'se', 'seq1.fseq');

        $content = file_get_contents($this->eventsFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(3, $lines);
    }

    public function testWriteEventIncludesTimestamp(): void
    {
        $this->logger->writeEvent('testhost', 'ss', 'test.fseq');

        $content = file_get_contents($this->eventsFile);

        // Check for timestamp format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $content);
    }

    public function testWriteEventIncludesHostname(): void
    {
        $this->logger->writeEvent('myhost', 'ss', 'test.fseq');

        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('"h":"myhost"', $content);
    }

    public function testWriteEventIncludesEventType(): void
    {
        $this->logger->writeEvent('testhost', 'ss', 'test.fseq');

        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('"e":"ss"', $content);
    }

    public function testWriteEventIncludesData(): void
    {
        $this->logger->writeEvent('testhost', 'ss', 'my-sequence.fseq');

        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('"d":"my-sequence.fseq"', $content);
    }

    public function testWriteEventWithDuration(): void
    {
        $result = $this->logger->writeEvent('testhost', 'se', 'test.fseq', 120);

        $this->assertTrue($result);
        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('"dur":120', $content);
    }

    public function testWriteEventWithZeroDuration(): void
    {
        $this->logger->writeEvent('testhost', 'se', 'test.fseq', 0);

        $content = file_get_contents($this->eventsFile);
        // Zero duration should not be included
        $this->assertStringNotContainsString('"dur"', $content);
    }

    public function testWriteEventWithNullDuration(): void
    {
        $this->logger->writeEvent('testhost', 'se', 'test.fseq', null);

        $content = file_get_contents($this->eventsFile);
        $this->assertStringNotContainsString('"dur"', $content);
    }

    public function testWriteEventWithNegativeDuration(): void
    {
        $this->logger->writeEvent('testhost', 'se', 'test.fseq', -10);

        $content = file_get_contents($this->eventsFile);
        // Negative duration should not be included
        $this->assertStringNotContainsString('"dur"', $content);
    }

    public function testWriteEventWithSpecialCharacters(): void
    {
        $result = $this->logger->writeEvent('test-host_1', 'wn', 'Warning: Error "test" & <chars>');

        $this->assertTrue($result);
        $content = file_get_contents($this->eventsFile);
        // JSON encoding should handle special characters
        $this->assertStringContainsString('Warning:', $content);
    }

    public function testWriteEventWithEmptyData(): void
    {
        $result = $this->logger->writeEvent('testhost', 'st', '');

        $this->assertTrue($result);
        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('"d":""', $content);
    }

    public function testWriteEventWithUnicodeData(): void
    {
        $result = $this->logger->writeEvent('testhost', 'wn', 'Unicode: \u00e9\u00e8\u00ea');

        $this->assertTrue($result);
        $content = file_get_contents($this->eventsFile);
        $this->assertStringContainsString('Unicode:', $content);
    }

    // =========================================================================
    // Get Events Tests - Basic
    // =========================================================================

    public function testGetEventsFromNonexistentFile(): void
    {
        $result = $this->logger->getEvents(24);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['count']);
        $this->assertEmpty($result['data']);
        $this->assertEquals(['hours' => 24], $result['period']);
    }

    public function testGetEventsReturnsAllEvents(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 60],
            ['h' => 'host2', 'e' => 'ps', 'd' => 'playlist1', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'se', 'd' => 'seq1.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['count']);
        $this->assertCount(3, $result['data']);
    }

    public function testGetEventsFiltersOldEvents(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'old.fseq', 't' => time() - 7200], // 2 hours ago
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => time() - 60], // 1 min ago
        ]);

        $result = $this->logger->getEvents(1); // Last 1 hour

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('recent.fseq', $result['data'][0]['data']);
    }

    public function testGetEventsWithZeroHoursBack(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'very-old.fseq', 't' => time() - 86400 * 30], // 30 days ago
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(0); // All time

        $this->assertEquals(2, $result['count']);
    }

    public function testGetEventsSortsByTimestampDescending(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'first.fseq', 't' => time() - 120],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'second.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'third.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1);

        // Most recent first
        $this->assertEquals('third.fseq', $result['data'][0]['data']);
        $this->assertEquals('second.fseq', $result['data'][1]['data']);
        $this->assertEquals('first.fseq', $result['data'][2]['data']);
    }

    // =========================================================================
    // Get Events Tests - Filtering
    // =========================================================================

    public function testGetEventsFiltersByHostname(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 60],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq3.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1, 'host1');

        $this->assertEquals(2, $result['count']);
        foreach ($result['data'] as $event) {
            $this->assertEquals('host1', $event['hostname']);
        }
    }

    public function testGetEventsFiltersByEventType(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1, null, 'ss');

        $this->assertEquals(2, $result['count']);
        foreach ($result['data'] as $event) {
            $this->assertEquals('ss', $event['eventType']);
        }
    }

    public function testGetEventsFiltersByBothHostnameAndEventType(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 90],
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist', 't' => time() - 60],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq3.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1, 'host1', 'ss');

        $this->assertEquals(2, $result['count']);
        foreach ($result['data'] as $event) {
            $this->assertEquals('host1', $event['hostname']);
            $this->assertEquals('ss', $event['eventType']);
        }
    }

    public function testGetEventsReturnsNoMatchesForInvalidFilter(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEvents(1, 'nonexistent-host');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['count']);
    }

    // =========================================================================
    // Get Events Tests - Data Expansion
    // =========================================================================

    public function testGetEventsExpandsToFullFormat(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'testhost', 'e' => 'ss', 'd' => 'test.fseq', 't' => $now],
        ]);

        $result = $this->logger->getEvents(1);
        $event = $result['data'][0];

        $this->assertArrayHasKey('timestamp', $event);
        $this->assertArrayHasKey('datetime', $event);
        $this->assertArrayHasKey('hostname', $event);
        $this->assertArrayHasKey('eventType', $event);
        $this->assertArrayHasKey('eventLabel', $event);
        $this->assertArrayHasKey('data', $event);

        $this->assertEquals($now, $event['timestamp']);
        $this->assertEquals('testhost', $event['hostname']);
        $this->assertEquals('ss', $event['eventType']);
        $this->assertEquals('Sequence Start', $event['eventLabel']);
        $this->assertEquals('test.fseq', $event['data']);
    }

    public function testGetEventsIncludesDuration(): void
    {
        $this->writeTestEvents([
            ['h' => 'testhost', 'e' => 'se', 'd' => 'test.fseq', 't' => time(), 'dur' => 120],
        ]);

        $result = $this->logger->getEvents(1);
        $event = $result['data'][0];

        $this->assertArrayHasKey('duration', $event);
        $this->assertEquals(120, $event['duration']);
    }

    public function testGetEventsMissingHostnameDefaultsToUnknown(): void
    {
        // Write event without hostname
        $entry = json_encode(['t' => time(), 'e' => 'ss', 'd' => 'test.fseq']);
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->eventsFile, "[{$timestamp}] {$entry}\n");

        $result = $this->logger->getEvents(1);
        $event = $result['data'][0];

        $this->assertEquals('unknown', $event['hostname']);
    }

    // =========================================================================
    // Get Event Stats Tests
    // =========================================================================

    public function testGetEventStatsReturnsSuccessOnEmpty(): void
    {
        $result = $this->logger->getEventStats(24);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('stats', $result);
    }

    public function testGetEventStatsCountsTotalEvents(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'se', 'd' => 'seq1.fseq', 't' => time() - 30],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(3, $result['stats']['totalEvents']);
    }

    public function testGetEventStatsTracksUniqueHosts(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 60],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq3.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertCount(2, $result['stats']['uniqueHosts']);
        $this->assertContains('host1', $result['stats']['uniqueHosts']);
        $this->assertContains('host2', $result['stats']['uniqueHosts']);
    }

    public function testGetEventStatsCountsEventsByType(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 90],
            ['h' => 'host1', 'e' => 'se', 'd' => 'seq.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(2, $result['stats']['eventsByType']['ss']);
        $this->assertEquals(1, $result['stats']['eventsByType']['se']);
        $this->assertEquals(1, $result['stats']['eventsByType']['ps']);
    }

    public function testGetEventStatsCountsEventsByHost(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 30],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(2, $result['stats']['eventsByHost']['host1']);
        $this->assertEquals(1, $result['stats']['eventsByHost']['host2']);
    }

    public function testGetEventStatsTracksSequencesPlayed(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 90],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq2.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq1.fseq', 't' => time() - 30],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(2, $result['stats']['sequencesPlayed']['seq1.fseq']);
        $this->assertEquals(1, $result['stats']['sequencesPlayed']['seq2.fseq']);
    }

    public function testGetEventStatsTracksPlaylistsStarted(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist1', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist1', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ps', 'd' => 'playlist2', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(2, $result['stats']['playlistsStarted']['playlist1']);
        $this->assertEquals(1, $result['stats']['playlistsStarted']['playlist2']);
    }

    public function testGetEventStatsTracksMediaPlayed(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ms', 'd' => 'song1.mp3', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ms', 'd' => 'song1.mp3', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ms', 'd' => 'song2.mp3', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(2, $result['stats']['mediaPlayed']['song1.mp3']);
        $this->assertEquals(1, $result['stats']['mediaPlayed']['song2.mp3']);
    }

    public function testGetEventStatsTracksWarnings(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'wn', 'd' => 'Warning message 1', 't' => $now - 60],
            ['h' => 'host2', 'e' => 'wn', 'd' => 'Warning message 2', 't' => $now],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertCount(2, $result['stats']['warnings']);
        $this->assertEquals('host2', $result['stats']['warnings'][0]['host']);
        $this->assertEquals('Warning message 2', $result['stats']['warnings'][0]['message']);
    }

    public function testGetEventStatsSumsTotalRuntime(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'se', 'd' => 'seq1.fseq', 't' => time() - 60, 'dur' => 120],
            ['h' => 'host1', 'e' => 'se', 'd' => 'seq2.fseq', 't' => time(), 'dur' => 180],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertEquals(300, $result['stats']['totalRuntime']);
    }

    public function testGetEventStatsBuildsHourlyDistribution(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => $now - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => $now - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => $now],
        ]);

        $result = $this->logger->getEventStats(1);

        $this->assertIsArray($result['stats']['hourlyDistribution']);
        $this->assertNotEmpty($result['stats']['hourlyDistribution']);

        foreach ($result['stats']['hourlyDistribution'] as $hourData) {
            $this->assertArrayHasKey('hour', $hourData);
            $this->assertArrayHasKey('timestamp', $hourData);
            $this->assertArrayHasKey('count', $hourData);
        }
    }

    public function testGetEventStatsSortsSequencesByCount(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'less-popular.fseq', 't' => time() - 60],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'popular.fseq', 't' => time() - 50],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'popular.fseq', 't' => time() - 40],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'popular.fseq', 't' => time()],
        ]);

        $result = $this->logger->getEventStats(1);

        $seqs = $result['stats']['sequencesPlayed'];
        $seqNames = array_keys($seqs);

        // Most popular should be first
        $this->assertEquals('popular.fseq', $seqNames[0]);
    }

    // =========================================================================
    // Get Hosts List Tests
    // =========================================================================

    public function testGetHostsListReturnsEmptyForNoEvents(): void
    {
        $result = $this->logger->getHostsList();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetHostsListReturnsUniqueHosts(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 60],
            ['h' => 'host2', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 30],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time()],
        ]);

        $result = $this->logger->getHostsList();

        $this->assertCount(2, $result);
        $this->assertContains('host1', $result);
        $this->assertContains('host2', $result);
    }

    public function testGetHostsListReturnsSortedHosts(): void
    {
        $this->writeTestEvents([
            ['h' => 'zebra', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 60],
            ['h' => 'alpha', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time() - 30],
            ['h' => 'beta', 'e' => 'ss', 'd' => 'seq.fseq', 't' => time()],
        ]);

        $result = $this->logger->getHostsList();

        $this->assertEquals(['alpha', 'beta', 'zebra'], $result);
    }

    // =========================================================================
    // Rotate Events File Tests
    // =========================================================================

    public function testRotateEventsFileDoesNothingForNonexistentFile(): void
    {
        // Should not throw exception
        $this->logger->rotateEventsFile(60);

        $this->assertFileDoesNotExist($this->eventsFile);
    }

    public function testRotateEventsFileKeepsRecentEvents(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => $now - 86400], // 1 day ago
            ['h' => 'host1', 'e' => 'ss', 'd' => 'very-recent.fseq', 't' => $now],
        ]);

        $this->logger->rotateEventsFile(7); // 7 days retention

        $result = $this->logger->getEvents(0);

        $this->assertEquals(2, $result['count']);
    }

    public function testRotateEventsFileRemovesOldEvents(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'old.fseq', 't' => $now - 86400 * 90], // 90 days ago
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => $now],
        ]);

        $this->logger->rotateEventsFile(7); // 7 days retention

        $result = $this->logger->getEvents(0);

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('recent.fseq', $result['data'][0]['data']);
    }

    public function testRotateEventsFileCreatesBackup(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'old.fseq', 't' => $now - 86400 * 90],
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => $now],
        ]);

        $this->logger->rotateEventsFile(7);

        $backupFile = $this->eventsFile . '.old';
        $this->assertFileExists($backupFile);
    }

    public function testRotateEventsFileNoOpIfNothingToRemove(): void
    {
        $now = time();
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'recent.fseq', 't' => $now],
        ]);

        $originalContent = file_get_contents($this->eventsFile);
        $this->logger->rotateEventsFile(7);

        // No backup should be created if nothing was purged
        $backupFile = $this->eventsFile . '.old';
        $this->assertFileDoesNotExist($backupFile);
    }

    // =========================================================================
    // Edge Cases and Error Handling
    // =========================================================================

    public function testGetEventsHandlesMalformedJsonGracefully(): void
    {
        // Write valid event
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'valid.fseq', 't' => time()],
        ]);

        // Append malformed line
        file_put_contents($this->eventsFile, "[2024-01-01 12:00:00] {invalid json}\n", FILE_APPEND);

        $result = $this->logger->getEvents(1);

        // Should still get the valid event
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['count']);
    }

    public function testGetEventsHandlesEmptyLines(): void
    {
        $this->writeTestEvents([
            ['h' => 'host1', 'e' => 'ss', 'd' => 'test.fseq', 't' => time()],
        ]);

        // Append empty lines
        file_put_contents($this->eventsFile, "\n\n\n", FILE_APPEND);

        $result = $this->logger->getEvents(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['count']);
    }

    public function testGetEventsHandlesMissingTimestamp(): void
    {
        // Write event without timestamp
        $entry = json_encode(['h' => 'host1', 'e' => 'ss', 'd' => 'test.fseq']);
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->eventsFile, "[{$timestamp}] {$entry}\n");

        $result = $this->logger->getEvents(1);

        // Event without 't' should be skipped
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['count']);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(MqttEventLogger::class);

        $expectedMethods = [
            'getInstance',
            'getEventLabel',
            'writeEvent',
            'getEvents',
            'getEventStats',
            'getHostsList',
            'rotateEventsFile',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );

            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method {$methodName} should be public"
            );
        }
    }

    // =========================================================================
    // Data Providers
    // =========================================================================

    /**
     * @dataProvider eventTypeProvider
     */
    public function testWriteAndReadEventType(string $eventType, string $expectedLabel): void
    {
        $this->logger->writeEvent('testhost', $eventType, 'test data');

        $result = $this->logger->getEvents(1);

        $this->assertEquals(1, $result['count']);
        $this->assertEquals($eventType, $result['data'][0]['eventType']);
        $this->assertEquals($expectedLabel, $result['data'][0]['eventLabel']);
    }

    public static function eventTypeProvider(): array
    {
        return [
            'sequence start' => ['ss', 'Sequence Start'],
            'sequence stop' => ['se', 'Sequence Stop'],
            'playlist start' => ['ps', 'Playlist Start'],
            'playlist stop' => ['pe', 'Playlist Stop'],
            'status' => ['st', 'Status'],
            'media start' => ['ms', 'Media Start'],
            'media stop' => ['me', 'Media Stop'],
            'warning' => ['wn', 'Warning'],
        ];
    }

    /**
     * @dataProvider durationProvider
     */
    public function testWriteEventWithVariousDurations(?int $duration, bool $shouldIncludeDur): void
    {
        $this->logger->writeEvent('testhost', 'se', 'test.fseq', $duration);

        $content = file_get_contents($this->eventsFile);

        if ($shouldIncludeDur) {
            $this->assertStringContainsString('"dur":', $content);
        } else {
            $this->assertStringNotContainsString('"dur":', $content);
        }
    }

    public static function durationProvider(): array
    {
        return [
            'positive duration' => [120, true],
            'zero duration' => [0, false],
            'null duration' => [null, false],
            'negative duration' => [-10, false],
            'large duration' => [86400, true],
        ];
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Write test events directly to the events file
     */
    private function writeTestEvents(array $events): void
    {
        $lines = [];
        foreach ($events as $event) {
            $timestamp = date('Y-m-d H:i:s', $event['t'] ?? time());
            $json = json_encode($event);
            $lines[] = "[{$timestamp}] {$json}";
        }
        file_put_contents($this->eventsFile, implode("\n", $lines) . "\n");
    }
}
