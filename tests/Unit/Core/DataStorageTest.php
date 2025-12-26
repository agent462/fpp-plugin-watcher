<?php
/**
 * Unit tests for DataStorage class
 *
 * @package Watcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\DataStorage;

class DataStorageTest extends TestCase
{
    private DataStorage $dataStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataStorage = DataStorage::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = DataStorage::getInstance();
        $instance2 = DataStorage::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceClearsInstance(): void
    {
        $instance1 = DataStorage::getInstance();
        DataStorage::resetInstance();
        $instance2 = DataStorage::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // Category Tests
    // =========================================================================

    public function testGetCategoriesReturnsAllCategories(): void
    {
        $categories = $this->dataStorage->getCategories();

        $this->assertIsArray($categories);
        $this->assertArrayHasKey('ping', $categories);
        $this->assertArrayHasKey('multisync-ping', $categories);
        $this->assertArrayHasKey('network-quality', $categories);
        $this->assertArrayHasKey('mqtt', $categories);
        $this->assertArrayHasKey('connectivity', $categories);
        $this->assertArrayHasKey('collectd', $categories);
        $this->assertArrayHasKey('efuse', $categories);
    }

    public function testGetCategoriesHasRequiredKeys(): void
    {
        $categories = $this->dataStorage->getCategories();

        foreach ($categories as $key => $category) {
            $this->assertArrayHasKey('name', $category, "Category '$key' missing 'name'");
            $this->assertArrayHasKey('dir', $category, "Category '$key' missing 'dir'");
            $this->assertArrayHasKey('description', $category, "Category '$key' missing 'description'");
        }
    }

    public function testHasCategoryReturnsTrueForValidCategory(): void
    {
        $this->assertTrue($this->dataStorage->hasCategory('ping'));
        $this->assertTrue($this->dataStorage->hasCategory('efuse'));
        $this->assertTrue($this->dataStorage->hasCategory('collectd'));
    }

    public function testHasCategoryReturnsFalseForInvalidCategory(): void
    {
        $this->assertFalse($this->dataStorage->hasCategory('nonexistent'));
        $this->assertFalse($this->dataStorage->hasCategory(''));
        $this->assertFalse($this->dataStorage->hasCategory('invalid-category'));
    }

    public function testGetCategoryDirectoryReturnsPath(): void
    {
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');

        $this->assertNotNull($pingDir);
        $this->assertStringContainsString('ping', $pingDir);
    }

    public function testGetCategoryDirectoryReturnsNullForInvalid(): void
    {
        $result = $this->dataStorage->getCategoryDirectory('nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // Directory Management Tests
    // =========================================================================

    public function testEnsureDirectoriesCreatesDirectories(): void
    {
        // Remove a test directory to verify it gets created
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');
        if (is_dir($pingDir)) {
            rmdir($pingDir);
        }

        $this->dataStorage->ensureDirectories();

        $this->assertDirectoryExists($pingDir);
    }

    // =========================================================================
    // Stats Tests
    // =========================================================================

    public function testGetStatsReturnsAllCategories(): void
    {
        $stats = $this->dataStorage->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('ping', $stats);
        $this->assertArrayHasKey('efuse', $stats);
    }

    public function testGetStatsHasRequiredKeys(): void
    {
        $stats = $this->dataStorage->getStats();

        foreach ($stats as $key => $stat) {
            $this->assertArrayHasKey('name', $stat, "Stat '$key' missing 'name'");
            $this->assertArrayHasKey('description', $stat, "Stat '$key' missing 'description'");
            $this->assertArrayHasKey('files', $stat, "Stat '$key' missing 'files'");
            $this->assertArrayHasKey('totalSize', $stat, "Stat '$key' missing 'totalSize'");
            $this->assertArrayHasKey('fileCount', $stat, "Stat '$key' missing 'fileCount'");
            $this->assertArrayHasKey('showFiles', $stat, "Stat '$key' missing 'showFiles'");
            $this->assertArrayHasKey('recursive', $stat, "Stat '$key' missing 'recursive'");
            $this->assertArrayHasKey('playerOnly', $stat, "Stat '$key' missing 'playerOnly'");
        }
    }

    public function testGetStatsCountsFiles(): void
    {
        // Create test files in ping directory
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');
        file_put_contents($pingDir . '/test1.log', str_repeat('a', 100));
        file_put_contents($pingDir . '/test2.log', str_repeat('b', 200));

        $stats = $this->dataStorage->getStats();

        $this->assertEquals(2, $stats['ping']['fileCount']);
        $this->assertEquals(300, $stats['ping']['totalSize']);
        $this->assertCount(2, $stats['ping']['files']);

        // Cleanup
        unlink($pingDir . '/test1.log');
        unlink($pingDir . '/test2.log');
    }

    public function testGetStatsHandlesEmptyDirectory(): void
    {
        $stats = $this->dataStorage->getStats();

        // Network quality should be empty in test environment
        $this->assertEquals(0, $stats['network-quality']['fileCount']);
        $this->assertEquals(0, $stats['network-quality']['totalSize']);
        $this->assertEmpty($stats['network-quality']['files']);
    }

    public function testGetStatsRecursiveDirectory(): void
    {
        // Collectd is marked as recursive
        $stats = $this->dataStorage->getStats();

        $this->assertTrue($stats['collectd']['recursive']);
        $this->assertFalse($stats['collectd']['showFiles']);
    }

    // =========================================================================
    // Clear Category Tests
    // =========================================================================

    public function testClearCategoryDeletesFiles(): void
    {
        // Create test files
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');
        file_put_contents($pingDir . '/test1.log', 'content1');
        file_put_contents($pingDir . '/test2.log', 'content2');

        $result = $this->dataStorage->clearCategory('ping');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['deleted']);
        $this->assertEmpty($result['errors']);
        $this->assertFileDoesNotExist($pingDir . '/test1.log');
        $this->assertFileDoesNotExist($pingDir . '/test2.log');
    }

    public function testClearCategoryReturnsErrorForInvalidCategory(): void
    {
        $result = $this->dataStorage->clearCategory('nonexistent');

        $this->assertFalse($result['success']);
        $this->assertEquals(0, $result['deleted']);
        $this->assertContains('Invalid category', $result['errors']);
    }

    public function testClearCategoryHandlesEmptyDirectory(): void
    {
        $result = $this->dataStorage->clearCategory('network-quality');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['deleted']);
        $this->assertEmpty($result['errors']);
    }

    public function testClearCategoryHandlesNonexistentDirectory(): void
    {
        // Remove the directory temporarily
        $mqttDir = $this->dataStorage->getCategoryDirectory('mqtt');
        if (is_dir($mqttDir)) {
            rmdir($mqttDir);
        }

        $result = $this->dataStorage->clearCategory('mqtt');

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['deleted']);

        // Recreate for other tests
        mkdir($mqttDir, 0755, true);
    }

    // =========================================================================
    // Clear File Tests
    // =========================================================================

    public function testClearFileDeletesSingleFile(): void
    {
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');
        $testFile = $pingDir . '/delete-me.log';
        file_put_contents($testFile, 'test content');

        $result = $this->dataStorage->clearFile('ping', 'delete-me.log');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertFileDoesNotExist($testFile);
    }

    public function testClearFileReturnsErrorForInvalidCategory(): void
    {
        $result = $this->dataStorage->clearFile('nonexistent', 'file.log');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid category', $result['error']);
    }

    public function testClearFileReturnsErrorForNonexistentFile(): void
    {
        $result = $this->dataStorage->clearFile('ping', 'does-not-exist.log');

        $this->assertFalse($result['success']);
        $this->assertEquals('File not found', $result['error']);
    }

    public function testClearFilePreventsDirectoryTraversal(): void
    {
        $result = $this->dataStorage->clearFile('ping', '../../../etc/passwd');

        // Should sanitize to just 'passwd' and then fail as not found
        $this->assertFalse($result['success']);
    }

    public function testClearFileRejectsInvalidFilenames(): void
    {
        $result1 = $this->dataStorage->clearFile('ping', '.');
        $result2 = $this->dataStorage->clearFile('ping', '..');
        $result3 = $this->dataStorage->clearFile('ping', '');

        $this->assertFalse($result1['success']);
        $this->assertFalse($result2['success']);
        $this->assertFalse($result3['success']);
        $this->assertEquals('Invalid filename', $result1['error']);
        $this->assertEquals('Invalid filename', $result2['error']);
        $this->assertEquals('Invalid filename', $result3['error']);
    }

    // =========================================================================
    // Tail File Tests
    // =========================================================================

    public function testTailFileReturnsLastLines(): void
    {
        $pingDir = $this->dataStorage->getCategoryDirectory('ping');
        $testFile = $pingDir . '/tail-test.log';

        // Create file with 100 lines
        $lines = [];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "Line {$i}";
        }
        file_put_contents($testFile, implode("\n", $lines));

        $result = $this->dataStorage->tailFile('ping', 'tail-test.log', 10);

        $this->assertTrue($result['success']);
        $this->assertEquals(10, $result['lines']);
        $this->assertStringContainsString('Line 100', $result['content']);
        $this->assertStringContainsString('Line 91', $result['content']);
        $this->assertEquals('tail-test.log', $result['filename']);
        $this->assertEquals('ping', $result['category']);

        unlink($testFile);
    }

    public function testTailFileReturnsErrorForInvalidCategory(): void
    {
        $result = $this->dataStorage->tailFile('nonexistent', 'file.log');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid category', $result['error']);
        $this->assertEquals('file.log', $result['filename']);
        $this->assertEquals('nonexistent', $result['category']);
    }

    public function testTailFileReturnsErrorForNonexistentFile(): void
    {
        $result = $this->dataStorage->tailFile('ping', 'does-not-exist.log');

        $this->assertFalse($result['success']);
        $this->assertEquals('File not found', $result['error']);
    }

    public function testTailFilePreventsDirectoryTraversal(): void
    {
        $result = $this->dataStorage->tailFile('ping', '../../../etc/passwd');

        // Should sanitize filename and fail appropriately
        $this->assertFalse($result['success']);
    }

    public function testTailFileRejectsInvalidFilenames(): void
    {
        $result1 = $this->dataStorage->tailFile('ping', '.');
        $result2 = $this->dataStorage->tailFile('ping', '..');
        $result3 = $this->dataStorage->tailFile('ping', '');

        $this->assertFalse($result1['success']);
        $this->assertFalse($result2['success']);
        $this->assertFalse($result3['success']);
        $this->assertEquals('Invalid filename', $result1['error']);
        $this->assertEquals('Invalid filename', $result2['error']);
        $this->assertEquals('Invalid filename', $result3['error']);
    }

    // =========================================================================
    // Category Property Tests
    // =========================================================================

    public function testCollectdCategoryHasWarning(): void
    {
        $categories = $this->dataStorage->getCategories();

        $this->assertArrayHasKey('warning', $categories['collectd']);
        $this->assertNotEmpty($categories['collectd']['warning']);
    }

    public function testPlayerOnlyCategoriesMarkedCorrectly(): void
    {
        $categories = $this->dataStorage->getCategories();

        // These should be player-only
        $this->assertTrue($categories['multisync-ping']['playerOnly'] ?? false);
        $this->assertTrue($categories['network-quality']['playerOnly'] ?? false);
        $this->assertTrue($categories['mqtt']['playerOnly'] ?? false);

        // These should not be player-only
        $this->assertFalse($categories['ping']['playerOnly'] ?? false);
        $this->assertFalse($categories['efuse']['playerOnly'] ?? false);
    }
}
