<?php
/**
 * Unit tests for FileManager class
 *
 * @package Watcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\FileManager;

class FileManagerTest extends TestCase
{
    private FileManager $fileManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileManager = FileManager::getInstance();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = FileManager::getInstance();
        $instance2 = FileManager::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceClearsInstance(): void
    {
        $instance1 = FileManager::getInstance();
        FileManager::resetInstance();
        $instance2 = FileManager::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // JSON Lines File Tests
    // =========================================================================

    public function testWriteJsonLinesFileCreatesFile(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $entries = [
            ['timestamp' => 1700000000, 'value' => 'test1'],
            ['timestamp' => 1700000060, 'value' => 'test2'],
        ];

        $result = $this->fileManager->writeJsonLinesFile($path, $entries);

        $this->assertTrue($result);
        $this->assertFileExists($path);
    }

    public function testWriteJsonLinesFileFormat(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $timestamp = 1700000000;
        $entries = [['timestamp' => $timestamp, 'value' => 'test']];

        $this->fileManager->writeJsonLinesFile($path, $entries);

        $content = file_get_contents($path);
        // Check format: [YYYY-MM-DD HH:MM:SS] {json}
        $this->assertMatchesRegularExpression(
            '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \{.+\}/',
            $content
        );
    }

    public function testWriteJsonLinesFileAppends(): void
    {
        $path = $this->testTmpDir . '/test.log';

        $entries1 = [['timestamp' => 1700000000, 'value' => 'first']];
        $entries2 = [['timestamp' => 1700000060, 'value' => 'second']];

        $this->fileManager->writeJsonLinesFile($path, $entries1);
        $this->fileManager->writeJsonLinesFile($path, $entries2, true);

        $content = file_get_contents($path);
        $this->assertStringContainsString('"value":"first"', $content);
        $this->assertStringContainsString('"value":"second"', $content);
    }

    public function testWriteJsonLinesFileOverwrites(): void
    {
        $path = $this->testTmpDir . '/test.log';

        $entries1 = [['timestamp' => 1700000000, 'value' => 'first']];
        $entries2 = [['timestamp' => 1700000060, 'value' => 'second']];

        $this->fileManager->writeJsonLinesFile($path, $entries1);
        $this->fileManager->writeJsonLinesFile($path, $entries2, false);

        $content = file_get_contents($path);
        $this->assertStringNotContainsString('"value":"first"', $content);
        $this->assertStringContainsString('"value":"second"', $content);
    }

    public function testWriteJsonLinesFileWithEmptyArray(): void
    {
        $path = $this->testTmpDir . '/test.log';

        $result = $this->fileManager->writeJsonLinesFile($path, []);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($path);
    }

    public function testReadJsonLinesFileReturnsEntries(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $entries = [
            ['timestamp' => 1700000000, 'value' => 'test1'],
            ['timestamp' => 1700000060, 'value' => 'test2'],
        ];

        $this->fileManager->writeJsonLinesFile($path, $entries);
        $result = $this->fileManager->readJsonLinesFile($path);

        $this->assertCount(2, $result);
        $this->assertEquals('test1', $result[0]['value']);
        $this->assertEquals('test2', $result[1]['value']);
    }

    public function testReadJsonLinesFileReturnsEmptyForNonexistent(): void
    {
        $result = $this->fileManager->readJsonLinesFile('/nonexistent/path.log');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testReadJsonLinesFileWithTimestampFilter(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $entries = [
            ['timestamp' => 1700000000, 'value' => 'old'],
            ['timestamp' => 1700000060, 'value' => 'new'],
            ['timestamp' => 1700000120, 'value' => 'newest'],
        ];

        $this->fileManager->writeJsonLinesFile($path, $entries);
        $result = $this->fileManager->readJsonLinesFile($path, 1700000050);

        $this->assertCount(2, $result);
        $this->assertEquals('new', $result[0]['value']);
        $this->assertEquals('newest', $result[1]['value']);
    }

    public function testReadJsonLinesFileWithCustomFilter(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $entries = [
            ['timestamp' => 1700000000, 'type' => 'info', 'value' => 'test1'],
            ['timestamp' => 1700000060, 'type' => 'error', 'value' => 'test2'],
            ['timestamp' => 1700000120, 'type' => 'info', 'value' => 'test3'],
        ];

        $this->fileManager->writeJsonLinesFile($path, $entries);
        $result = $this->fileManager->readJsonLinesFile($path, 0, fn($e) => $e['type'] === 'error');

        $this->assertCount(1, $result);
        $this->assertEquals('test2', $result[0]['value']);
    }

    public function testReadJsonLinesFileSortsResults(): void
    {
        $path = $this->testTmpDir . '/test.log';
        // Write entries out of order
        $content = "[2023-11-15 00:02:00] {\"timestamp\":1700000120,\"value\":\"c\"}\n";
        $content .= "[2023-11-15 00:00:00] {\"timestamp\":1700000000,\"value\":\"a\"}\n";
        $content .= "[2023-11-15 00:01:00] {\"timestamp\":1700000060,\"value\":\"b\"}\n";
        file_put_contents($path, $content);

        $result = $this->fileManager->readJsonLinesFile($path, 0, null, true);

        $this->assertCount(3, $result);
        $this->assertEquals('a', $result[0]['value']);
        $this->assertEquals('b', $result[1]['value']);
        $this->assertEquals('c', $result[2]['value']);
    }

    public function testReadJsonLinesFileWithCustomTimestampField(): void
    {
        $path = $this->testTmpDir . '/test.log';
        $content = "[2023-11-15 00:00:00] {\"created_at\":1700000000,\"value\":\"old\"}\n";
        $content .= "[2023-11-15 00:01:00] {\"created_at\":1700000060,\"value\":\"new\"}\n";
        file_put_contents($path, $content);

        $result = $this->fileManager->readJsonLinesFile($path, 1700000030, null, true, 'created_at');

        $this->assertCount(1, $result);
        $this->assertEquals('new', $result[0]['value']);
    }

    // =========================================================================
    // JSON File Tests
    // =========================================================================

    public function testWriteJsonFileCreatesFile(): void
    {
        $path = $this->testTmpDir . '/test.json';
        $data = ['key' => 'value', 'number' => 42];

        $result = $this->fileManager->writeJsonFile($path, $data);

        $this->assertTrue($result);
        $this->assertFileExists($path);
    }

    public function testWriteJsonFileIsPrettyPrinted(): void
    {
        $path = $this->testTmpDir . '/test.json';
        $data = ['key' => 'value'];

        $this->fileManager->writeJsonFile($path, $data);

        $content = file_get_contents($path);
        $this->assertStringContainsString("\n", $content);
    }

    public function testWriteJsonFileWithoutPrettyPrint(): void
    {
        $path = $this->testTmpDir . '/test.json';
        $data = ['key' => 'value'];

        $this->fileManager->writeJsonFile($path, $data, 0);

        $content = file_get_contents($path);
        $this->assertStringNotContainsString("\n", $content);
    }

    public function testReadJsonFileReturnsData(): void
    {
        $path = $this->testTmpDir . '/test.json';
        $data = ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]];

        $this->fileManager->writeJsonFile($path, $data);
        $result = $this->fileManager->readJsonFile($path);

        $this->assertEquals($data, $result);
    }

    public function testReadJsonFileReturnsNullForNonexistent(): void
    {
        $result = $this->fileManager->readJsonFile('/nonexistent/path.json');

        $this->assertNull($result);
    }

    public function testReadJsonFileReturnsNullForEmptyFile(): void
    {
        $path = $this->testTmpDir . '/empty.json';
        file_put_contents($path, '');

        $result = $this->fileManager->readJsonFile($path);

        $this->assertNull($result);
    }

    public function testReadJsonFileReturnsNullForInvalidJson(): void
    {
        $path = $this->testTmpDir . '/invalid.json';
        file_put_contents($path, 'not valid json');

        $result = $this->fileManager->readJsonFile($path);

        $this->assertNull($result);
    }

    public function testJsonFileRoundTrip(): void
    {
        $path = $this->testTmpDir . '/roundtrip.json';
        $data = [
            'string' => 'hello',
            'number' => 42,
            'float' => 3.14,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'object' => ['nested' => 'value'],
        ];

        $this->fileManager->writeJsonFile($path, $data);
        $result = $this->fileManager->readJsonFile($path);

        $this->assertEquals($data, $result);
    }

    // =========================================================================
    // Directory Operations Tests
    // =========================================================================

    public function testEnsureDirectoryCreatesDirectory(): void
    {
        $path = $this->testTmpDir . '/new/nested/dir';

        $result = $this->fileManager->ensureDirectory($path);

        $this->assertTrue($result);
        $this->assertDirectoryExists($path);
    }

    public function testEnsureDirectoryReturnsTrueForExisting(): void
    {
        $path = $this->testTmpDir;

        $result = $this->fileManager->ensureDirectory($path);

        $this->assertTrue($result);
    }

    public function testGetDirectorySizeRecursive(): void
    {
        $dir = $this->testTmpDir . '/sizedir';
        mkdir($dir);

        // Create some files with known sizes
        file_put_contents($dir . '/file1.txt', str_repeat('a', 100));
        file_put_contents($dir . '/file2.txt', str_repeat('b', 200));

        mkdir($dir . '/subdir');
        file_put_contents($dir . '/subdir/file3.txt', str_repeat('c', 50));

        $result = $this->fileManager->getDirectorySizeRecursive($dir);

        $this->assertEquals(350, $result['size']);
        $this->assertEquals(3, $result['count']);
    }

    public function testGetDirectorySizeRecursiveForNonexistent(): void
    {
        $result = $this->fileManager->getDirectorySizeRecursive('/nonexistent/path');

        $this->assertEquals(0, $result['size']);
        $this->assertEquals(0, $result['count']);
    }

    public function testClearDirectoryRecursive(): void
    {
        $dir = $this->testTmpDir . '/cleardir';
        mkdir($dir);
        mkdir($dir . '/subdir');

        file_put_contents($dir . '/file1.txt', 'content');
        file_put_contents($dir . '/subdir/file2.txt', 'content');

        $result = $this->fileManager->clearDirectoryRecursive($dir);

        $this->assertEquals(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertDirectoryExists($dir);
        $this->assertFileDoesNotExist($dir . '/file1.txt');
        $this->assertFileDoesNotExist($dir . '/subdir/file2.txt');
    }

    public function testClearDirectoryRecursiveForNonexistent(): void
    {
        $result = $this->fileManager->clearDirectoryRecursive('/nonexistent/path');

        $this->assertEquals(0, $result['deleted']);
        $this->assertEmpty($result['errors']);
    }

    // =========================================================================
    // File Tail Tests
    // =========================================================================

    public function testTailFileReturnsLastLines(): void
    {
        $path = $this->testTmpDir . '/tail.log';
        $lines = [];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "Line {$i}";
        }
        file_put_contents($path, implode("\n", $lines));

        $result = $this->fileManager->tailFile($path, 10);

        $this->assertTrue($result['success']);
        $this->assertEquals(10, $result['lines']);
        $this->assertStringContainsString('Line 100', $result['content']);
        $this->assertStringContainsString('Line 91', $result['content']);
        $this->assertStringNotContainsString('Line 90', $result['content']);
    }

    public function testTailFileForNonexistent(): void
    {
        $result = $this->fileManager->tailFile('/nonexistent/path.log');

        $this->assertFalse($result['success']);
        $this->assertEquals('File not found', $result['error']);
    }

    public function testTailFileForEmptyFile(): void
    {
        $path = $this->testTmpDir . '/empty.log';
        file_put_contents($path, '');

        $result = $this->fileManager->tailFile($path);

        $this->assertTrue($result['success']);
        $this->assertEquals('', $result['content']);
    }

    public function testTailFileForSmallFile(): void
    {
        $path = $this->testTmpDir . '/small.log';
        file_put_contents($path, "Line 1\nLine 2\nLine 3");

        $result = $this->fileManager->tailFile($path, 100);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $result['lines']);
    }

    // =========================================================================
    // Ownership Tests
    // =========================================================================

    public function testEnsureFppOwnershipReturnsFalseForNonexistent(): void
    {
        $result = $this->fileManager->ensureFppOwnership('/nonexistent/path');

        $this->assertFalse($result);
    }

    public function testEnsureFppOwnershipReturnsFalseForEmptyPath(): void
    {
        $result = $this->fileManager->ensureFppOwnership('');

        $this->assertFalse($result);
    }

    public function testEnsureFppOwnershipCachesResult(): void
    {
        $path = $this->testTmpDir . '/ownership.txt';
        file_put_contents($path, 'test');

        // First call should succeed and cache
        $result1 = $this->fileManager->ensureFppOwnership($path);
        $this->assertTrue($result1);

        // Second call should return cached result
        $result2 = $this->fileManager->ensureFppOwnership($path);
        $this->assertTrue($result2);
    }

    public function testEnsureFppOwnershipForceBypassesCache(): void
    {
        $path = $this->testTmpDir . '/ownership.txt';
        file_put_contents($path, 'test');

        $this->fileManager->ensureFppOwnership($path);

        // Force should still succeed even if cached
        $result = $this->fileManager->ensureFppOwnership($path, true);
        $this->assertTrue($result);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testLargeJsonLinesFile(): void
    {
        $path = $this->testTmpDir . '/large.log';
        $entries = [];
        $startTime = 1700000000;

        // Create 1000 entries
        for ($i = 0; $i < 1000; $i++) {
            $entries[] = [
                'timestamp' => $startTime + ($i * 60),
                'value' => "Entry {$i}",
                'data' => str_repeat('x', 100),
            ];
        }

        $this->fileManager->writeJsonLinesFile($path, $entries);
        $result = $this->fileManager->readJsonLinesFile($path);

        $this->assertCount(1000, $result);
    }

    public function testJsonLinesWithSpecialCharacters(): void
    {
        $path = $this->testTmpDir . '/special.log';
        $entries = [
            [
                'timestamp' => 1700000000,
                'message' => "Special chars: \"quotes\" 'apostrophe' <tag> & newline\nhere",
                'unicode' => '日本語 emoji 🎄',
            ],
        ];

        $this->fileManager->writeJsonLinesFile($path, $entries);
        $result = $this->fileManager->readJsonLinesFile($path);

        $this->assertEquals($entries[0]['message'], $result[0]['message']);
        $this->assertEquals($entries[0]['unicode'], $result[0]['unicode']);
    }

    public function testNestedJsonStructure(): void
    {
        $path = $this->testTmpDir . '/nested.json';
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'deep' => 'value',
                        ],
                    ],
                ],
            ],
        ];

        $this->fileManager->writeJsonFile($path, $data);
        $result = $this->fileManager->readJsonFile($path);

        $this->assertEquals('value', $result['level1']['level2']['level3']['level4']['deep']);
    }
}
