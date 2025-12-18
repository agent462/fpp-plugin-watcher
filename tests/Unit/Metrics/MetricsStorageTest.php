<?php
/**
 * Unit tests for MetricsStorage class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\MetricsStorage;

class MetricsStorageTest extends TestCase
{
    private MetricsStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new MetricsStorage();
    }

    // =========================================================================
    // WriteBatch Tests
    // =========================================================================

    public function testWriteBatchCreatesFile(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [
            ['timestamp' => time(), 'value' => 'test'],
        ];

        $result = $this->storage->writeBatch($file, $entries);

        $this->assertTrue($result);
        $this->assertFileExists($file);
    }

    public function testWriteBatchWithEmptyArray(): void
    {
        $file = $this->testTmpDir . '/metrics.log';

        $result = $this->storage->writeBatch($file, []);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($file);
    }

    public function testWriteBatchFormat(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $timestamp = 1700000000;
        $entries = [
            ['timestamp' => $timestamp, 'value' => 'test'],
        ];

        $this->storage->writeBatch($file, $entries);

        $content = file_get_contents($file);
        // Check format: [YYYY-MM-DD HH:MM:SS] {json}
        $this->assertMatchesRegularExpression(
            '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \{.+\}/',
            $content
        );
        $this->assertStringContainsString('"timestamp":1700000000', $content);
        $this->assertStringContainsString('"value":"test"', $content);
    }

    public function testWriteBatchAppendsToExisting(): void
    {
        $file = $this->testTmpDir . '/metrics.log';

        $entries1 = [['timestamp' => 1700000000, 'value' => 'first']];
        $entries2 = [['timestamp' => 1700000060, 'value' => 'second']];

        $this->storage->writeBatch($file, $entries1);
        $this->storage->writeBatch($file, $entries2);

        $content = file_get_contents($file);
        $this->assertStringContainsString('"value":"first"', $content);
        $this->assertStringContainsString('"value":"second"', $content);
    }

    public function testWriteBatchMultipleEntries(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = ['timestamp' => 1700000000 + ($i * 60), 'index' => $i];
        }

        $result = $this->storage->writeBatch($file, $entries);

        $this->assertTrue($result);

        $content = file_get_contents($file);
        for ($i = 0; $i < 10; $i++) {
            $this->assertStringContainsString("\"index\":{$i}", $content);
        }
    }

    public function testWriteBatchWithDefaultTimestamp(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [
            ['value' => 'no_timestamp'], // No timestamp field
        ];

        $beforeTime = time();
        $this->storage->writeBatch($file, $entries);
        $afterTime = time();

        $content = file_get_contents($file);
        // Should use current time - check the datetime format is recent
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2}/', $content);
    }

    // =========================================================================
    // Read Tests
    // =========================================================================

    public function testReadFromNonexistentFile(): void
    {
        $result = $this->storage->read('/nonexistent/file.log');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadReturnsEntries(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [
            ['timestamp' => 1700000000, 'value' => 'test1'],
            ['timestamp' => 1700000060, 'value' => 'test2'],
        ];

        $this->storage->writeBatch($file, $entries);
        $result = $this->storage->read($file);

        $this->assertCount(2, $result);
        $this->assertEquals('test1', $result[0]['value']);
        $this->assertEquals('test2', $result[1]['value']);
    }

    public function testReadFiltersByTimestamp(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [
            ['timestamp' => 1700000000, 'value' => 'old'],
            ['timestamp' => 1700000060, 'value' => 'newer'],
            ['timestamp' => 1700000120, 'value' => 'newest'],
        ];

        $this->storage->writeBatch($file, $entries);
        $result = $this->storage->read($file, 1700000050);

        $this->assertCount(2, $result);
        $this->assertEquals('newer', $result[0]['value']);
        $this->assertEquals('newest', $result[1]['value']);
    }

    public function testReadSortsByTimestamp(): void
    {
        $file = $this->testTmpDir . '/metrics.log';

        // Write entries out of order
        $content = "[2023-11-15 00:02:00] {\"timestamp\":1700000120,\"value\":\"c\"}\n";
        $content .= "[2023-11-15 00:00:00] {\"timestamp\":1700000000,\"value\":\"a\"}\n";
        $content .= "[2023-11-15 00:01:00] {\"timestamp\":1700000060,\"value\":\"b\"}\n";
        file_put_contents($file, $content);

        $result = $this->storage->read($file);

        $this->assertCount(3, $result);
        $this->assertEquals('a', $result[0]['value']);
        $this->assertEquals('b', $result[1]['value']);
        $this->assertEquals('c', $result[2]['value']);
    }

    public function testReadIgnoresInvalidLines(): void
    {
        $file = $this->testTmpDir . '/metrics.log';

        $content = "[2023-11-15 00:00:00] {\"timestamp\":1700000000,\"value\":\"valid\"}\n";
        $content .= "invalid line without json\n";
        $content .= "[2023-11-15 00:01:00] not valid json at all\n";
        $content .= "[2023-11-15 00:02:00] {\"value\":\"no_timestamp\"}\n"; // Missing timestamp
        $content .= "[2023-11-15 00:03:00] {\"timestamp\":1700000180,\"value\":\"also_valid\"}\n";
        file_put_contents($file, $content);

        $result = $this->storage->read($file);

        $this->assertCount(2, $result);
        $this->assertEquals('valid', $result[0]['value']);
        $this->assertEquals('also_valid', $result[1]['value']);
    }

    public function testReadWithNoTimestampFilterReturnsAll(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [];
        for ($i = 0; $i < 100; $i++) {
            $entries[] = ['timestamp' => 1700000000 + $i, 'index' => $i];
        }

        $this->storage->writeBatch($file, $entries);
        $result = $this->storage->read($file, 0);

        $this->assertCount(100, $result);
    }

    public function testReadPerformanceWithRegexFiltering(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $entries = [];
        $now = time();

        // Create 1000 entries, only last 100 should match
        for ($i = 0; $i < 1000; $i++) {
            $entries[] = [
                'timestamp' => $now - 1000 + $i,
                'data' => str_repeat('x', 100), // Add some bulk
            ];
        }

        $this->storage->writeBatch($file, $entries);

        // Filter to only last 100
        $sinceTime = $now - 100;
        $result = $this->storage->read($file, $sinceTime);

        // Should get ~99 entries (those with timestamp > sinceTime)
        $this->assertLessThanOrEqual(100, count($result));
        $this->assertGreaterThan(0, count($result));
    }

    // =========================================================================
    // Rotate Tests
    // =========================================================================

    public function testRotateNonexistentFile(): void
    {
        $result = $this->storage->rotate('/nonexistent/file.log', 3600);

        $this->assertEquals(0, $result['purged']);
        $this->assertEquals(0, $result['kept']);
    }

    public function testRotatePurgesOldEntries(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $now = time();

        // Create entries: some old (to be purged), some recent (to keep)
        $content = "";
        // Old entries (2 hours ago)
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - 7200 + $i;
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts, 'old' => true]) . "\n";
        }
        // Recent entries (5 minutes ago)
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - 300 + $i;
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts, 'old' => false]) . "\n";
        }
        file_put_contents($file, $content);

        // Rotate with 1 hour retention
        $result = $this->storage->rotate($file, 3600);

        $this->assertEquals(5, $result['purged']);
        $this->assertEquals(5, $result['kept']);

        // Verify file content
        $remaining = $this->storage->read($file);
        $this->assertCount(5, $remaining);
        foreach ($remaining as $entry) {
            $this->assertFalse($entry['old']);
        }
    }

    public function testRotateCreatesBackupFile(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $now = time();

        // Create old entries
        $content = "";
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - 7200 + $i;
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts]) . "\n";
        }
        // Add one recent entry
        $ts = $now;
        $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts]) . "\n";
        file_put_contents($file, $content);

        $this->storage->rotate($file, 3600);

        $this->assertFileExists($file . '.old');
    }

    public function testRotateWithNoOldEntriesSkipsRewrite(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $now = time();

        // Create only recent entries
        $content = "";
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - 60 + $i;
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts]) . "\n";
        }
        file_put_contents($file, $content);

        $result = $this->storage->rotate($file, 3600);

        $this->assertEquals(0, $result['purged']);
        $this->assertEquals(5, $result['kept']);

        // No backup should be created
        $this->assertFileDoesNotExist($file . '.old');
    }

    public function testRotateWithAllOldEntries(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $now = time();

        // Create only old entries
        $content = "";
        for ($i = 0; $i < 5; $i++) {
            $ts = $now - 7200 + $i;
            $content .= "[" . date('Y-m-d H:i:s', $ts) . "] " . json_encode(['timestamp' => $ts]) . "\n";
        }
        file_put_contents($file, $content);

        $result = $this->storage->rotate($file, 3600);

        $this->assertEquals(5, $result['purged']);
        $this->assertEquals(0, $result['kept']);

        // File should be empty
        $remaining = $this->storage->read($file);
        $this->assertEmpty($remaining);
    }

    public function testRotateWithCustomBackupSuffix(): void
    {
        $file = $this->testTmpDir . '/metrics.log';
        $now = time();

        // Create old and new entries
        $content = "[" . date('Y-m-d H:i:s', $now - 7200) . "] " . json_encode(['timestamp' => $now - 7200]) . "\n";
        $content .= "[" . date('Y-m-d H:i:s', $now) . "] " . json_encode(['timestamp' => $now]) . "\n";
        file_put_contents($file, $content);

        $this->storage->rotate($file, 3600, '.backup');

        $this->assertFileExists($file . '.backup');
        $this->assertFileDoesNotExist($file . '.old');
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testHandlesLargeDataset(): void
    {
        $file = $this->testTmpDir . '/large.log';
        $entries = [];

        for ($i = 0; $i < 1000; $i++) {
            $entries[] = [
                'timestamp' => time() - (1000 - $i),
                'index' => $i,
                'payload' => str_repeat('x', 100),
            ];
        }

        $writeResult = $this->storage->writeBatch($file, $entries);
        $this->assertTrue($writeResult);

        $readResult = $this->storage->read($file);
        $this->assertCount(1000, $readResult);

        // Verify order
        for ($i = 0; $i < 1000; $i++) {
            $this->assertEquals($i, $readResult[$i]['index']);
        }
    }

    public function testHandlesSpecialCharactersInData(): void
    {
        $file = $this->testTmpDir . '/special.log';
        $entries = [
            [
                'timestamp' => time(),
                'message' => "Special chars: \"quotes\" 'apostrophe' <tag> & newline\nhere",
                'unicode' => '日本語 emoji 🎄',
            ],
        ];

        $this->storage->writeBatch($file, $entries);
        $result = $this->storage->read($file);

        $this->assertEquals($entries[0]['message'], $result[0]['message']);
        $this->assertEquals($entries[0]['unicode'], $result[0]['unicode']);
    }

    public function testConcurrentWritesSafety(): void
    {
        $file = $this->testTmpDir . '/concurrent.log';

        // Simulate rapid concurrent writes
        for ($i = 0; $i < 50; $i++) {
            $entries = [['timestamp' => time(), 'batch' => $i]];
            $this->storage->writeBatch($file, $entries);
        }

        $result = $this->storage->read($file);

        $this->assertCount(50, $result);
    }
}
