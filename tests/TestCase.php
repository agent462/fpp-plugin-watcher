<?php
/**
 * Base Test Case for Watcher Plugin Tests
 *
 * Provides common setup, teardown, and utility methods for all tests.
 */

declare(strict_types=1);

namespace Watcher\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Watcher\Core\Logger;
use Watcher\Core\FileManager;
use Watcher\Core\Settings;
use Watcher\MultiSync\Comparator;
use Watcher\Metrics\SystemMetrics;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var string Temporary directory for this test
     */
    protected string $testTmpDir;

    /**
     * @var array Files created during the test that should be cleaned up
     */
    protected array $createdFiles = [];

    /**
     * @var array Directories created during the test that should be cleaned up
     */
    protected array $createdDirs = [];

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create unique temp directory for this test
        $this->testTmpDir = WATCHER_TEST_TMP_DIR . '/' . uniqid('test_');
        if (!is_dir($this->testTmpDir)) {
            mkdir($this->testTmpDir, 0755, true);
        }

        // Reset singletons before each test
        $this->resetSingletons();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        // Clean up created files
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up created directories (in reverse order for nested dirs)
        $this->createdDirs = array_reverse($this->createdDirs);
        foreach ($this->createdDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        // Clean up test temp directory
        if (is_dir($this->testTmpDir)) {
            $this->removeDirectory($this->testTmpDir);
        }

        // Reset singletons after each test
        $this->resetSingletons();

        parent::tearDown();
    }

    /**
     * Reset singleton instances for clean test state
     */
    protected function resetSingletons(): void
    {
        Logger::resetInstance();
        FileManager::resetInstance();
        Settings::resetInstance();
        Comparator::resetInstance();
        SystemMetrics::resetInstance();
    }

    /**
     * Remove a directory and all its contents recursively
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Create a temporary file with optional content
     */
    protected function createTempFile(string $filename, string $content = ''): string
    {
        $path = $this->testTmpDir . '/' . $filename;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->createdDirs[] = $dir;
        }

        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * Create a temporary directory
     */
    protected function createTempDir(string $dirname): string
    {
        $path = $this->testTmpDir . '/' . $dirname;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $this->createdDirs[] = $path;
        }
        return $path;
    }

    /**
     * Get the path to a fixture file
     */
    protected function getFixturePath(string $filename): string
    {
        return WATCHER_TEST_DIR . '/Fixtures/' . $filename;
    }

    /**
     * Load a fixture file content
     */
    protected function loadFixture(string $filename): string
    {
        $path = $this->getFixturePath($filename);
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture file not found: {$path}");
        }
        return file_get_contents($path);
    }

    /**
     * Load a JSON fixture file and decode it
     */
    protected function loadJsonFixture(string $filename): array
    {
        $content = $this->loadFixture($filename);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in fixture: {$filename}");
        }
        return $data;
    }

    /**
     * Generate sample metrics data for testing
     */
    protected function generateSampleMetrics(int $count = 100, int $startTime = null): array
    {
        $startTime = $startTime ?? (time() - ($count * 60));
        $metrics = [];

        for ($i = 0; $i < $count; $i++) {
            $timestamp = $startTime + ($i * 60);
            $metrics[] = [
                'timestamp' => $timestamp,
                'latency' => mt_rand(10, 100) + (mt_rand(0, 99) / 100),
                'success' => mt_rand(0, 100) > 5, // 95% success rate
                'hostname' => 'testhost-' . ($i % 5),
            ];
        }

        return $metrics;
    }

    /**
     * Generate sample eFuse readings for testing
     */
    protected function generateSampleEfuseReadings(int $portCount = 16): array
    {
        $readings = [];
        for ($i = 1; $i <= $portCount; $i++) {
            $readings["Port {$i}"] = [
                'current' => mt_rand(100, 5000) / 100, // 1.00 - 50.00 Amps
                'enabled' => true,
                'tripped' => mt_rand(0, 100) < 2, // 2% tripped
            ];
        }
        return $readings;
    }

    /**
     * Assert that a file exists and contains expected content
     */
    protected function assertFileContainsString(string $expected, string $file): void
    {
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $this->assertStringContainsString($expected, $content);
    }

    /**
     * Assert that a JSON file contains expected data
     */
    protected function assertJsonFileEquals(array $expected, string $file): void
    {
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        $this->assertEquals($expected, $data);
    }

    /**
     * Assert that a value is within a percentage of an expected value
     */
    protected function assertWithinPercent(float $expected, float $actual, float $percent): void
    {
        $margin = $expected * ($percent / 100);
        $this->assertGreaterThanOrEqual($expected - $margin, $actual);
        $this->assertLessThanOrEqual($expected + $margin, $actual);
    }

    /**
     * Create a mock HTTP response for testing
     */
    protected function createMockHttpResponse(int $statusCode, array $body = []): array
    {
        return [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'http_code' => $statusCode,
            'data' => $body,
            'response_time' => 0.05,
            'error' => $statusCode >= 400 ? 'HTTP Error' : null,
        ];
    }

    /**
     * Write JSON-lines format metrics file for testing
     */
    protected function writeJsonLinesFile(string $path, array $entries): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->createdDirs[] = $dir;
        }

        $lines = [];
        foreach ($entries as $entry) {
            $timestamp = $entry['timestamp'] ?? time();
            $datetime = date('Y-m-d H:i:s', $timestamp);
            $json = json_encode($entry);
            $lines[] = "[{$datetime}] {$json}";
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->createdFiles[] = $path;
    }

    /**
     * Assert array has all expected keys
     */
    protected function assertArrayHasKeys(array $keys, array $array): void
    {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, "Array missing expected key: {$key}");
        }
    }

    /**
     * Skip test if running in CI environment without FPP
     */
    protected function skipIfNoFpp(): void
    {
        if (!file_exists('/opt/fpp/www/common.php')) {
            $this->markTestSkipped('FPP installation not found - skipping integration test');
        }
    }

    /**
     * Skip test if no network connectivity
     */
    protected function skipIfNoNetwork(): void
    {
        $socket = @fsockopen('8.8.8.8', 53, $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped('No network connectivity - skipping network test');
        }
        fclose($socket);
    }
}
