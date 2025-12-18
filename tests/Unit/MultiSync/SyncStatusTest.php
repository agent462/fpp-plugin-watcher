<?php
/**
 * Unit tests for SyncStatus class
 *
 * @package Watcher\Tests\Unit\MultiSync
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\MultiSync;

use Watcher\Tests\TestCase;
use Watcher\MultiSync\SyncStatus;

class SyncStatusTest extends TestCase
{
    private SyncStatus $syncStatus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncStatus = SyncStatus::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = SyncStatus::getInstance();
        $instance2 = SyncStatus::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Static Utility Tests
    // =========================================================================

    public function testFormatTimeSinceSeconds(): void
    {
        $result = SyncStatus::formatTimeSince(30);
        $this->assertEquals('30s', $result);

        $result = SyncStatus::formatTimeSince(59);
        $this->assertEquals('59s', $result);
    }

    public function testFormatTimeSinceMinutes(): void
    {
        $result = SyncStatus::formatTimeSince(60);
        $this->assertEquals('1m', $result);

        $result = SyncStatus::formatTimeSince(125);
        $this->assertEquals('2m', $result);

        $result = SyncStatus::formatTimeSince(3599);
        $this->assertEquals('59m', $result);
    }

    public function testFormatTimeSinceHours(): void
    {
        $result = SyncStatus::formatTimeSince(3600);
        $this->assertEquals('1h', $result);

        $result = SyncStatus::formatTimeSince(7200);
        $this->assertEquals('2h', $result);

        $result = SyncStatus::formatTimeSince(86399);
        $this->assertEquals('23h', $result);
    }

    public function testFormatTimeSinceDays(): void
    {
        $result = SyncStatus::formatTimeSince(86400);
        $this->assertEquals('1d', $result);

        $result = SyncStatus::formatTimeSince(172800);
        $this->assertEquals('2d', $result);

        $result = SyncStatus::formatTimeSince(604800);
        $this->assertEquals('7d', $result);
    }

    public function testFormatTimeSinceZero(): void
    {
        $result = SyncStatus::formatTimeSince(0);
        $this->assertEquals('0s', $result);
    }

    public function testGetIssueSeverityClass(): void
    {
        // Test various severity levels
        $this->assertIsString(SyncStatus::getIssueSeverityClass(1));
        $this->assertIsString(SyncStatus::getIssueSeverityClass(2));
        $this->assertIsString(SyncStatus::getIssueSeverityClass(3));
    }

    public function testGetIssueSeverityLabel(): void
    {
        $this->assertIsString(SyncStatus::getIssueSeverityLabel(1));
        $this->assertIsString(SyncStatus::getIssueSeverityLabel(2));
        $this->assertIsString(SyncStatus::getIssueSeverityLabel(3));
    }

    // =========================================================================
    // API Method Structure Tests
    // =========================================================================

    public function testGetStatusReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getStatus();

        $this->assertTrue(
            is_array($result) || $result === false,
            'getStatus should return array or false'
        );
    }

    public function testGetMetricsReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getMetrics();

        $this->assertTrue(
            is_array($result) || $result === false,
            'getMetrics should return array or false'
        );
    }

    public function testGetIssuesReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getIssues();

        $this->assertTrue(
            is_array($result) || $result === false,
            'getIssues should return array or false'
        );
    }

    public function testIsPluginLoadedReturnsBool(): void
    {
        $result = $this->syncStatus->isPluginLoaded();

        $this->assertIsBool($result);
    }

    public function testGetDashboardDataReturnsArray(): void
    {
        $result = $this->syncStatus->getDashboardData();

        $this->assertIsArray($result);
    }

    public function testGetFullStatusReturnsArray(): void
    {
        $result = $this->syncStatus->getFullStatus();

        $this->assertIsArray($result);
    }

    public function testGetFppSystemsReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getFppSystems();

        $this->assertTrue(
            is_array($result) || $result === false,
            'getFppSystems should return array or false'
        );
    }

    public function testGetFppSyncStatsReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getFppSyncStats();

        $this->assertTrue(
            is_array($result) || $result === false,
            'getFppSyncStats should return array or false'
        );
    }

    public function testGetHostMetricsReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->getHostMetrics('192.168.1.100');

        $this->assertTrue(
            is_array($result) || $result === false || $result === null,
            'getHostMetrics should return array, false, or null'
        );
    }

    public function testResetMetricsReturnsArrayOrFalse(): void
    {
        $result = $this->syncStatus->resetMetrics();

        $this->assertTrue(
            is_array($result) || $result === false,
            'resetMetrics should return array or false'
        );
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(SyncStatus::class);

        $expectedMethods = [
            'getInstance',
            'getStatus',
            'getMetrics',
            'getIssues',
            'getHostMetrics',
            'resetMetrics',
            'getFppSyncStats',
            'getFppSystems',
            'isPluginLoaded',
            'getDashboardData',
            'getFullStatus',
            'formatTimeSince',
            'getIssueSeverityClass',
            'getIssueSeverityLabel',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );
        }
    }
}
