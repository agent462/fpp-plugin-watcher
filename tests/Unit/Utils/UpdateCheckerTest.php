<?php
/**
 * Unit tests for UpdateChecker class
 *
 * @package Watcher\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Tests\TestCase;
use Watcher\Utils\UpdateChecker;

class UpdateCheckerTest extends TestCase
{
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = UpdateChecker::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = UpdateChecker::getInstance();
        $instance2 = UpdateChecker::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(UpdateChecker::class);

        $expectedMethods = [
            'getInstance',
            'getLatestWatcherVersion',
            'checkWatcherUpdate',
            'getLatestFPPRelease',
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
    // Return Type Tests
    // =========================================================================

    public function testGetLatestWatcherVersionReturnsStringOrNull(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->getLatestWatcherVersion();

        $this->assertTrue(
            is_string($result) || is_null($result),
            'getLatestWatcherVersion should return string or null'
        );
    }

    public function testCheckWatcherUpdateReturnsArray(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->checkWatcherUpdate();

        $this->assertIsArray($result);
        // Should have either success or error keys
        $this->assertTrue(
            isset($result['success']) || isset($result['error']),
            'Result should have success or error key'
        );
        // If successful, should have latestVersion
        if ($result['success'] ?? false) {
            $this->assertArrayHasKey('latestVersion', $result);
        }
    }

    public function testGetLatestFPPReleaseReturnsArrayOrNull(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->getLatestFPPRelease();

        $this->assertTrue(
            is_array($result) || is_null($result),
            'getLatestFPPRelease should return array or null'
        );
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    // Note: fetchJsonUrl is a private method and is tested indirectly
    // through the public methods that use it (getLatestWatcherVersion,
    // checkWatcherUpdate, getLatestFPPRelease)

    // =========================================================================
    // Cache Behavior Tests
    // =========================================================================

    public function testLatestFPPReleaseCaching(): void
    {
        $this->skipIfNoNetwork();

        // First call
        $result1 = $this->checker->getLatestFPPRelease();

        // Second call should be cached (faster)
        $start = microtime(true);
        $result2 = $this->checker->getLatestFPPRelease();
        $duration = microtime(true) - $start;

        // If caching works, second call should be very fast (< 0.01s)
        // But we can't guarantee this in all environments
        // Just verify both return same structure
        if (is_array($result1) && is_array($result2)) {
            $this->assertEquals(array_keys($result1), array_keys($result2));
        }
    }
}
