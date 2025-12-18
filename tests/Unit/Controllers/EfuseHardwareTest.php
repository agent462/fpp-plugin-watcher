<?php
/**
 * Unit tests for EfuseHardware class
 *
 * Note: Hardware detection requires FPP system files to be present.
 * These tests focus on the logic and structure of the class.
 * Integration tests with actual hardware are in Integration/EfuseTest.php
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\EfuseHardware;

class EfuseHardwareTest extends TestCase
{
    private EfuseHardware $hardware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hardware = EfuseHardware::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = EfuseHardware::getInstance();
        $instance2 = EfuseHardware::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testHardwareCacheTTLConstant(): void
    {
        $this->assertEquals(3600, EfuseHardware::HARDWARE_CACHE_TTL);
    }

    public function testResetDelayConstant(): void
    {
        $this->assertEquals(100000, EfuseHardware::RESET_DELAY_US); // 100ms in microseconds
    }

    public function testFppDirectoryConstants(): void
    {
        $this->assertEquals('/home/fpp/media/tmp', EfuseHardware::FPP_TMP_DIR);
        $this->assertEquals('/home/fpp/media/config', EfuseHardware::FPP_CONFIG_DIR);
        $this->assertEquals('/opt/fpp/capes', EfuseHardware::FPP_CAPES_DIR);
    }

    // =========================================================================
    // Detection Result Structure Tests
    // =========================================================================

    public function testDetectHardwareReturnsExpectedStructure(): void
    {
        $result = $this->hardware->detectHardware();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('ports', $result);
        $this->assertArrayHasKey('details', $result);

        $this->assertIsBool($result['supported']);
        $this->assertIsString($result['type']);
        $this->assertIsInt($result['ports']);
        $this->assertIsArray($result['details']);
    }

    public function testDetectHardwareWithForceRefresh(): void
    {
        // First call (may be cached)
        $result1 = $this->hardware->detectHardware();

        // Second call with force refresh
        $result2 = $this->hardware->detectHardware(true);

        // Both should have valid structure
        $this->assertArrayHasKey('supported', $result1);
        $this->assertArrayHasKey('supported', $result2);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(EfuseHardware::class);

        // Verify key public methods exist
        $expectedMethods = [
            'getInstance',
            'detectHardware',
            'readEfuseData',
            'getFppdPortsCached',
            'countEfusePortsFromFppd',
            'getEfuseCapablePortNames',
            'getPortCurrentSummary',
            'calculateTotalCurrent',
            'getHardwareSummary',
            'clearHardwareCache',
            'togglePort',
            'resetPort',
            'setAllPorts',
            'resetAllTrippedFuses',
            'getControlCapabilities',
            'getPortStatus',
            'getTrippedFuses',
            'getSummary',
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
    // Hardware Summary Tests
    // =========================================================================

    public function testGetHardwareSummaryReturnsArray(): void
    {
        $result = $this->hardware->getHardwareSummary();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
    }

    public function testGetSummaryIsAliasForHardwareSummary(): void
    {
        $summary1 = $this->hardware->getHardwareSummary();
        $summary2 = $this->hardware->getSummary();

        // Both should return same structure
        $this->assertEquals(array_keys($summary1), array_keys($summary2));
    }

    // =========================================================================
    // Cache Tests
    // =========================================================================

    public function testClearHardwareCacheMethod(): void
    {
        // Clear should not throw
        $this->hardware->clearHardwareCache();

        // After clearing, next detect should work
        $result = $this->hardware->detectHardware();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // Current Calculation Tests
    // =========================================================================

    public function testCalculateTotalCurrentWithEmptyArray(): void
    {
        $result = $this->hardware->calculateTotalCurrent([]);

        $this->assertIsArray($result);
        // Should return some form of total (0 or structure)
    }

    public function testCalculateTotalCurrentWithSampleData(): void
    {
        $readings = [
            'Port 1' => 2.5,
            'Port 2' => 3.0,
            'Port 3' => 1.5,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertIsArray($result);
    }

    public function testGetPortCurrentSummaryWithEmptyArray(): void
    {
        $result = $this->hardware->getPortCurrentSummary([]);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Control Capabilities Tests
    // =========================================================================

    public function testGetControlCapabilitiesReturnsArray(): void
    {
        $result = $this->hardware->getControlCapabilities();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('canToggle', $result);
        $this->assertArrayHasKey('canReset', $result);
        $this->assertArrayHasKey('canMasterControl', $result);

        $this->assertIsBool($result['canToggle']);
        $this->assertIsBool($result['canReset']);
        $this->assertIsBool($result['canMasterControl']);
    }

    // =========================================================================
    // Port Reading Tests
    // =========================================================================

    public function testReadEfuseDataReturnsArrayOrFalse(): void
    {
        $result = $this->hardware->readEfuseData();

        // Can return array (with data) or false (if no hardware)
        $this->assertTrue(
            is_array($result) || $result === false,
            'readEfuseData should return array or false'
        );
    }

    public function testCountEfusePortsFromFppdReturnsInt(): void
    {
        $result = $this->hardware->countEfusePortsFromFppd();

        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetEfuseCapablePortNamesReturnsArray(): void
    {
        $result = $this->hardware->getEfuseCapablePortNames();

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Tripped Fuse Tests
    // =========================================================================

    public function testGetTrippedFusesReturnsArray(): void
    {
        $result = $this->hardware->getTrippedFuses();

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Port Status Tests
    // =========================================================================

    public function testGetPortStatusWithInvalidPort(): void
    {
        $result = $this->hardware->getPortStatus('NonexistentPort999');

        // Should return null or empty status for invalid port
        $this->assertTrue(
            is_null($result) || is_array($result),
            'getPortStatus should return null or array'
        );
    }

    // =========================================================================
    // Port Control Tests (Mock scenarios)
    // =========================================================================

    public function testTogglePortReturnsBoolOrArray(): void
    {
        // This will likely fail without actual hardware, but should not throw
        $result = $this->hardware->togglePort('Port 1', 'on');

        $this->assertTrue(
            is_bool($result) || is_array($result),
            'togglePort should return bool or array'
        );
    }

    public function testResetPortReturnsBoolOrArray(): void
    {
        $result = $this->hardware->resetPort('Port 1');

        $this->assertTrue(
            is_bool($result) || is_array($result),
            'resetPort should return bool or array'
        );
    }

    public function testSetAllPortsReturnsBoolOrArray(): void
    {
        $result = $this->hardware->setAllPorts('on');

        $this->assertTrue(
            is_bool($result) || is_array($result),
            'setAllPorts should return bool or array'
        );
    }

    public function testResetAllTrippedFusesReturnsBoolOrArray(): void
    {
        $result = $this->hardware->resetAllTrippedFuses();

        $this->assertTrue(
            is_bool($result) || is_array($result),
            'resetAllTrippedFuses should return bool or array'
        );
    }

    // =========================================================================
    // Sample Data Structure Tests
    // =========================================================================

    public function testSampleEfuseReadingsFormat(): void
    {
        // Load fixture for eFuse readings
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $efuseReadings = $fixtureData['efuse_readings'];

        // Verify structure
        foreach ($efuseReadings as $portName => $portData) {
            $this->assertStringContainsString('Port', $portName);
            $this->assertArrayHasKey('current', $portData);
            $this->assertArrayHasKey('enabled', $portData);
            $this->assertArrayHasKey('tripped', $portData);

            $this->assertIsFloat($portData['current']);
            $this->assertIsBool($portData['enabled']);
            $this->assertIsBool($portData['tripped']);
        }
    }

    public function testCalculateTotalCurrentWithFixtureData(): void
    {
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $efuseReadings = $fixtureData['efuse_readings'];

        // Extract just the current values (mA) from the fixture data
        $currentReadings = array_map(fn($port) => $port['current'], $efuseReadings);

        $result = $this->hardware->calculateTotalCurrent($currentReadings);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // FPP Cache Tests
    // =========================================================================

    public function testGetFppdPortsCachedReturnsArrayOrNull(): void
    {
        $result = $this->hardware->getFppdPortsCached();

        $this->assertTrue(
            is_array($result) || is_null($result),
            'getFppdPortsCached should return array or null'
        );
    }
}
